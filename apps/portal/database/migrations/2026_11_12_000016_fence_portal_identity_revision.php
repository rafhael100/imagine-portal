<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Candidate identity revision fence; no grants, roles, issuer or route. */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_guard_identity_revision()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $identity$
DECLARE sensitive_changed boolean;
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id THEN
        RAISE EXCEPTION 'Portal identity binding is immutable' USING ERRCODE='42501';
    END IF;
    IF NEW.security_revision IS NULL OR NEW.security_revision < OLD.security_revision THEN
        RAISE EXCEPTION 'Portal identity revision cannot decrease' USING ERRCODE='42501';
    END IF;
    IF NEW.mfa_last_used_counter IS NULL OR NEW.mfa_last_used_counter < OLD.mfa_last_used_counter THEN
        RAISE EXCEPTION 'Portal MFA counter cannot decrease' USING ERRCODE='42501';
    END IF;
    IF (OLD.status='revoked' AND NEW.status IS DISTINCT FROM OLD.status)
        OR (OLD.status='suspended' AND NEW.status='active') THEN
        RAISE EXCEPTION 'Portal identity cannot be silently reactivated' USING ERRCODE='42501';
    END IF;
    IF NEW.email_normalized IS DISTINCT FROM OLD.email_normalized
        AND NEW.email_verified_at IS NOT NULL THEN
        RAISE EXCEPTION 'Changed Portal email requires fresh verification' USING ERRCODE='42501';
    END IF;

    -- Initial invited-account enrollment binds the current verification challenge.
    -- First values must not invalidate that challenge. Replacement/clearing does.
    sensitive_changed :=
        NEW.status IS DISTINCT FROM OLD.status
        OR NEW.email_normalized IS DISTINCT FROM OLD.email_normalized
        OR (OLD.password_hash IS NOT NULL AND NEW.password_hash IS DISTINCT FROM OLD.password_hash)
        OR (OLD.mfa_secret_ciphertext IS NOT NULL AND NEW.mfa_secret_ciphertext IS DISTINCT FROM OLD.mfa_secret_ciphertext)
        OR (OLD.email_verified_at IS NOT NULL AND NEW.email_verified_at IS DISTINCT FROM OLD.email_verified_at)
        OR (OLD.mfa_confirmed_at IS NOT NULL AND NEW.mfa_confirmed_at IS DISTINCT FROM OLD.mfa_confirmed_at)
        OR (OLD.status <> 'invited' AND (
            NEW.password_hash IS DISTINCT FROM OLD.password_hash
            OR NEW.mfa_secret_ciphertext IS DISTINCT FROM OLD.mfa_secret_ciphertext
            OR NEW.email_verified_at IS DISTINCT FROM OLD.email_verified_at
            OR NEW.mfa_confirmed_at IS DISTINCT FROM OLD.mfa_confirmed_at
        ));
    IF sensitive_changed THEN
        IF OLD.security_revision >= 9223372036854775807 THEN
            RAISE EXCEPTION 'Portal identity revision is exhausted' USING ERRCODE='22003';
        END IF;
        NEW.security_revision := GREATEST(NEW.security_revision,OLD.security_revision+1);
    END IF;
    -- A normal forward TOTP counter update does not invalidate other sessions.
    RETURN NEW;
END;
$identity$;
SQL);
        DB::statement('REVOKE ALL ON FUNCTION public.portal_guard_identity_revision() FROM PUBLIC');
        DB::statement('CREATE TRIGGER portal_identity_revision_guard BEFORE UPDATE ON public.portal_identities '
            .'FOR EACH ROW EXECUTE FUNCTION public.portal_guard_identity_revision()');
    }

    public function down(): void
    {
        $this->requireFoundation();
        DB::transaction(function (): void {
            DB::statement('SET LOCAL row_security = off');
            if (DB::table('portal_identities')->exists() || DB::table('portal_auth_sessions')->exists()) {
                throw new RuntimeException('Identity fence rollback denied: identity/session evidence requires audited cleanup.');
            }
            DB::statement('DROP TRIGGER IF EXISTS portal_identity_revision_guard ON public.portal_identities');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_guard_identity_revision()');
        });
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql' || !Schema::hasTable('portal_auth_sessions')
            || !Schema::hasColumn('portal_identities','mfa_last_used_counter')) {
            throw new RuntimeException('Portal PostgreSQL identity/session foundation required.');
        }
    }
};
