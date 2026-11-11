<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INT-F163, proposal for independent DBA/AppSec review.
 *
 * A future Portal session writer must not rewrite immutable issuance identity,
 * tenant, bearer digest, captured security revisions or clocks. Existing
 * PostgreSQL FORCE RLS and runtime deny-all are deliberately unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();

        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_f163_guard_session_issuance()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $f163$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.session_sha256 IS DISTINCT FROM OLD.session_sha256
        OR NEW.identity_id IS DISTINCT FROM OLD.identity_id
        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
        OR NEW.identity_revision IS DISTINCT FROM OLD.identity_revision
        OR NEW.membership_revision IS DISTINCT FROM OLD.membership_revision
        OR NEW.organization_revision IS DISTINCT FROM OLD.organization_revision
        OR NEW.session_purpose IS DISTINCT FROM OLD.session_purpose
        OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
    THEN
        RAISE EXCEPTION 'Portal session issuance evidence is immutable; revoke and reissue'
            USING ERRCODE = '42501';
    END IF;

    -- Lifecycle: pending MFA may be activated or revoked, active may be
    -- revoked; revocation is terminal. This is NOT MFA verification itself.
    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF NOT (
            (OLD.status = 'pending_mfa' AND NEW.status IN ('active','revoked'))
            OR (OLD.status = 'active' AND NEW.status = 'revoked')
        ) THEN
            RAISE EXCEPTION 'Portal session state transition is forbidden'
                USING ERRCODE = '42501';
        END IF;
    END IF;

    IF NEW.mfa_verified_at IS DISTINCT FROM OLD.mfa_verified_at THEN
        IF NOT (
            OLD.status = 'pending_mfa'
            AND NEW.status = 'active'
            AND OLD.mfa_verified_at IS NULL
            AND NEW.mfa_verified_at IS NOT NULL
            AND NEW.mfa_verified_at >= OLD.issued_at
            AND NEW.mfa_verified_at <= OLD.expires_at
        ) THEN
            RAISE EXCEPTION 'MFA evidence cannot be replaced or backfilled'
                USING ERRCODE = '42501';
        END IF;
    END IF;

    IF NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN
        IF NOT (
            OLD.status IN ('pending_mfa','active')
            AND NEW.status = 'revoked'
            AND OLD.revoked_at IS NULL
            AND NEW.revoked_at IS NOT NULL
            AND NEW.revoked_at >= OLD.issued_at
        ) THEN
            RAISE EXCEPTION 'Revocation evidence is immutable after terminal transition'
                USING ERRCODE = '42501';
        END IF;
    END IF;

    RETURN NEW;
END;
$f163$;
SQL);

        DB::statement(
            'REVOKE ALL ON FUNCTION public.portal_f163_guard_session_issuance() FROM PUBLIC',
        );
        DB::statement(
            'CREATE TRIGGER portal_f163_session_issuance_guard '
            .'BEFORE UPDATE ON public.portal_auth_sessions '
            .'FOR EACH ROW EXECUTE FUNCTION public.portal_f163_guard_session_issuance()',
        );

        // No RLS policy, application role grant, token issuer, or session route.
    }

    public function down(): void
    {
        $this->requireFoundation();

        DB::transaction(function (): void {
            // As in F154/F156, never remove a security trigger while
            // sessions exist, even when an application role cannot see them.
            DB::statement('SET LOCAL row_security = off');
            if (DB::table('portal_auth_sessions')->exists()) {
                throw new RuntimeException(
                    'F163 rollback denied: session issuance evidence requires audited cleanup.',
                );
            }
            DB::statement(
                'DROP TRIGGER IF EXISTS portal_f163_session_issuance_guard '
                .'ON public.portal_auth_sessions',
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS public.portal_f163_guard_session_issuance()',
            );
        });
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_auth_sessions')
            || !Schema::hasColumn('portal_auth_sessions', 'session_purpose')
            || !Schema::hasColumn('portal_auth_sessions', 'organization_revision')) {
            throw new RuntimeException(
                'F163 requires Portal F66/F76/F113 PostgreSQL session schema.',
            );
        }
    }
};
