<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PORTAL-INT-F154: review-only PostgreSQL revision invalidation fences.
 *
 * A membership status/role change OR an inserted/deleted explicit grant must
 * increment portal_memberships.membership_revision. Thus older Portal session
 * snapshots may not be silently reused by a future trusted authority.
 *
 * IMPORTANT: SECURITY INVOKER triggers, no RLS policy, no GRANT, no login,
 * no session issuer, no implicit privileges. App roles are still denied.
 * Deployment requires independent DBA/AppSec approval and staging rehearsal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();

        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_f154_membership_before_update()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $f154_membership$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.identity_id IS DISTINCT FROM OLD.identity_id
        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
    THEN
        RAISE EXCEPTION 'Portal membership actor/tenant binding is immutable'
            USING ERRCODE = '42501';
    END IF;

    IF NEW.role IS DISTINCT FROM OLD.role
        OR NEW.status IS DISTINCT FROM OLD.status
    THEN
        IF OLD.membership_revision >= 9223372036854775807 THEN
            RAISE EXCEPTION 'Portal membership revision is exhausted'
                USING ERRCODE = '22003';
        END IF;
        NEW.membership_revision := OLD.membership_revision + 1;
    ELSIF NEW.membership_revision < OLD.membership_revision THEN
        RAISE EXCEPTION 'Portal membership revision cannot move backward'
            USING ERRCODE = '42501';
    END IF;
    RETURN NEW;
END;
$f154_membership$;

CREATE FUNCTION public.portal_f154_grant_after_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $f154_grant$
DECLARE
    member_id uuid;
BEGIN
    IF TG_OP = 'INSERT' THEN
        member_id := NEW.membership_id;
    ELSIF TG_OP = 'DELETE' THEN
        member_id := OLD.membership_id;
    ELSE
        RAISE EXCEPTION 'Grant revision requires an insert or delete'
            USING ERRCODE = '42501';
    END IF;

    -- SQL row locking serializes concurrent grant changes against other
    -- membership updates. A future role must have explicit permissions to
    -- update membership; otherwise the entire grant mutation fails closed.
    UPDATE public.portal_memberships
       SET membership_revision = membership_revision + 1,
           updated_at = clock_timestamp()
     WHERE id = member_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'No matching membership to revise'
            USING ERRCODE = '23503';
    END IF;
    RETURN NULL;
END;
$f154_grant$;

CREATE FUNCTION public.portal_f154_deny_grant_update()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $f154_deny$
BEGIN
    RAISE EXCEPTION 'Existing Portal grant rows are immutable; revoke and reissue'
        USING ERRCODE = '42501';
END;
$f154_deny$;
SQL);

        foreach ([
            'public.portal_f154_membership_before_update()',
            'public.portal_f154_grant_after_change()',
            'public.portal_f154_deny_grant_update()',
        ] as $function) {
            DB::statement('REVOKE ALL ON FUNCTION '.$function.' FROM PUBLIC');
        }

        DB::statement(
            'CREATE TRIGGER portal_f154_membership_revision_guard '
            .'BEFORE UPDATE ON public.portal_memberships '
            .'FOR EACH ROW EXECUTE FUNCTION public.portal_f154_membership_before_update()',
        );
        DB::statement(
            'CREATE TRIGGER portal_f154_grant_immutable_guard '
            .'BEFORE UPDATE ON public.portal_membership_grants '
            .'FOR EACH ROW EXECUTE FUNCTION public.portal_f154_deny_grant_update()',
        );
        DB::statement(
            'CREATE TRIGGER portal_f154_grant_revision_guard '
            .'AFTER INSERT OR DELETE ON public.portal_membership_grants '
            .'FOR EACH ROW EXECUTE FUNCTION public.portal_f154_grant_after_change()',
        );

        // Crucially: no RLS ALLOW policies, writer grants, or authentication.
    }

    public function down(): void
    {
        $this->requireFoundation();

        DB::transaction(function (): void {
            // Force an error rather than silently ignoring rows hidden by
            // RLS. Only an approved DBA with full visibility can downgrade.
            DB::statement('SET LOCAL row_security = off');
            foreach ([
                'portal_auth_sessions',
                'portal_membership_grants',
                'portal_memberships',
            ] as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException(
                        'F154 rollback denied: memberships/grants/sessions need audited cleanup.',
                    );
                }
            }

            DB::statement(
                'DROP TRIGGER IF EXISTS portal_f154_grant_revision_guard '
                .'ON public.portal_membership_grants',
            );
            DB::statement(
                'DROP TRIGGER IF EXISTS portal_f154_grant_immutable_guard '
                .'ON public.portal_membership_grants',
            );
            DB::statement(
                'DROP TRIGGER IF EXISTS portal_f154_membership_revision_guard '
                .'ON public.portal_memberships',
            );
            DB::statement('DROP FUNCTION IF EXISTS public.portal_f154_grant_after_change()');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_f154_deny_grant_update()');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_f154_membership_before_update()');
        });
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_memberships')
            || !Schema::hasTable('portal_membership_grants')
            || !Schema::hasTable('portal_auth_sessions')) {
            throw new RuntimeException(
                'F154 requires the PostgreSQL Portal identity foundation.',
            );
        }
    }
};
