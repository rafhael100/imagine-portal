<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INT-F156: review-only organization security revision fence.
 *
 * Existing sessions already witness portal_organizations.security_revision
 * through portal_auth_sessions.organization_revision (F76), but until now
 * status changes could leave the revision unchanged.
 *
 * This SECURITY INVOKER trigger adds NO permissions, RLS policies, session
 * issuer, or operational reactivation path. Pending independent DBA/AppSec.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();

        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_f156_guard_org_status_revision()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $f156$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.reference IS DISTINCT FROM OLD.reference
    THEN
        RAISE EXCEPTION 'Portal organization identity/reference is immutable'
            USING ERRCODE = '42501';
    END IF;

    -- Closed is terminal. Until a trusted audited activation workflow is
    -- approved, suspended -> active is not an authorized SQL transition.
    IF OLD.status = 'closed' AND NEW.status IS DISTINCT FROM OLD.status
        OR OLD.status = 'suspended' AND NEW.status = 'active'
    THEN
        RAISE EXCEPTION 'Portal organization cannot be silently reactivated'
            USING ERRCODE = '42501';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF OLD.security_revision >= 9223372036854775807 THEN
            RAISE EXCEPTION 'Organization revision is exhausted'
                USING ERRCODE = '22003';
        END IF;
        NEW.security_revision := OLD.security_revision + 1;
    ELSIF NEW.security_revision < OLD.security_revision THEN
        RAISE EXCEPTION 'Organization security revision cannot be decreased'
            USING ERRCODE = '42501';
    END IF;

    RETURN NEW;
END;
$f156$;
SQL);

        DB::statement(
            'REVOKE ALL ON FUNCTION public.portal_f156_guard_org_status_revision() FROM PUBLIC',
        );
        DB::statement(
            'CREATE TRIGGER portal_f156_org_revision_guard '
            .'BEFORE UPDATE ON public.portal_organizations '
            .'FOR EACH ROW EXECUTE FUNCTION public.portal_f156_guard_org_status_revision()',
        );
        // No RLS ALLOW policies, roles, grants or customer operations.
    }

    public function down(): void
    {
        $this->requireFoundation();

        DB::transaction(function (): void {
            DB::statement('SET LOCAL row_security = off');
            if (DB::table('portal_organizations')->exists()
                || DB::table('portal_auth_sessions')->exists()) {
                throw new RuntimeException(
                    'F156 rollback denied: organization/session evidence requires audited cleanup.',
                );
            }

            DB::statement(
                'DROP TRIGGER IF EXISTS portal_f156_org_revision_guard '
                .'ON public.portal_organizations',
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS public.portal_f156_guard_org_status_revision()',
            );
        });
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_organizations')
            || !Schema::hasTable('portal_auth_sessions')
            || !Schema::hasColumn('portal_auth_sessions','organization_revision')) {
            throw new RuntimeException(
                'F156 requires PG Portal organization foundation and F76 session org revision.',
            );
        }
    }
};
