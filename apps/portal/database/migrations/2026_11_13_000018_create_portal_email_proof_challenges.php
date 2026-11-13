<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portal first-operator email challenge persistence, review-only candidate.
 *
 * NO runtime grants or RLS ALLOW policies. A valid challenge row alone does
 * not prove email possession, activate an account or issue any session.
 * Deployment needs separate DBA/AppSec authorization and restore fencing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();

        DB::unprepared(<<<'SQL'
CREATE TABLE public.portal_email_proof_challenges (
    identity_id uuid PRIMARY KEY REFERENCES public.portal_identities(id) ON DELETE RESTRICT,
    organization_id uuid NOT NULL,
    email_normalized varchar(254) NOT NULL,
    identity_revision bigint NOT NULL,
    proof_digest char(64) NOT NULL,
    status varchar(12) NOT NULL DEFAULT 'pending',
    attempts smallint NOT NULL DEFAULT 0,
    issued_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    consumed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT portal_email_proof_membership_fk
        FOREIGN KEY (identity_id,organization_id)
        REFERENCES public.portal_memberships(identity_id,organization_id)
        ON DELETE RESTRICT,
    CONSTRAINT portal_email_proof_digest_ck
        CHECK (proof_digest ~ '^[a-f0-9]{64}$'),
    CONSTRAINT portal_email_proof_email_ck
        CHECK (email_normalized = lower(email_normalized)
               AND position('@' IN email_normalized)>1),
    CONSTRAINT portal_email_proof_revision_ck
        CHECK (identity_revision > 0),
    CONSTRAINT portal_email_proof_attempt_ck
        CHECK (attempts BETWEEN 0 AND 5),
    CONSTRAINT portal_email_proof_status_ck
        CHECK (status IN ('pending','consumed','blocked')),
    CONSTRAINT portal_email_proof_expiry_ck
        CHECK (expires_at > issued_at
               AND expires_at <= issued_at+interval '10 minutes'),
    CONSTRAINT portal_email_proof_consumption_ck
        CHECK ((status='pending' AND consumed_at IS NULL AND attempts<5)
               OR (status='blocked' AND consumed_at IS NULL AND attempts=5)
               OR (status='consumed' AND consumed_at IS NOT NULL AND attempts<5
                   AND consumed_at>=issued_at AND consumed_at<expires_at))
);

CREATE FUNCTION public.portal_guard_email_proof_challenge()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp
AS $portal_email_proof$
BEGIN
    -- This is a one-shot identity/tenant/email/revision binding. A verifier
    -- replacement, tenant transfer or deadline extension requires an audited
    -- new enrollment protocol, not UPDATE of old challenge evidence.
    IF NEW.identity_id IS DISTINCT FROM OLD.identity_id
       OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
       OR NEW.email_normalized IS DISTINCT FROM OLD.email_normalized
       OR NEW.identity_revision IS DISTINCT FROM OLD.identity_revision
       OR NEW.proof_digest IS DISTINCT FROM OLD.proof_digest
       OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
       OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Portal email proof scope is immutable'
            USING ERRCODE='42501';
    END IF;
    IF OLD.status<>'pending' OR OLD.consumed_at IS NOT NULL THEN
        RAISE EXCEPTION 'Portal email proof was already finalized'
            USING ERRCODE='42501';
    END IF;
    IF NEW.attempts<OLD.attempts OR NEW.attempts>OLD.attempts+1 THEN
        RAISE EXCEPTION 'Portal email proof attempts cannot be reset or skipped'
            USING ERRCODE='42501';
    END IF;
    IF NEW.status='pending' THEN
        IF NEW.attempts=5 OR NEW.consumed_at IS NOT NULL THEN
            RAISE EXCEPTION 'Portal email proof pending state exhausted'
                USING ERRCODE='42501';
        END IF;
    ELSIF NEW.status='blocked' THEN
        IF NEW.attempts<>5 OR NEW.consumed_at IS NOT NULL
           OR NEW.attempts<>OLD.attempts+1 THEN
            RAISE EXCEPTION 'Portal email proof lockout requires fifth attempt'
                USING ERRCODE='42501';
        END IF;
    ELSIF NEW.status='consumed' THEN
        IF NEW.consumed_at IS NULL OR NEW.attempts<>OLD.attempts
           OR NEW.attempts>=5 OR OLD.expires_at<=clock_timestamp() THEN
            RAISE EXCEPTION 'Portal email proof cannot be consumed'
                USING ERRCODE='42501';
        END IF;
        -- Time comes from PostgreSQL, not a caller-supplied replay timestamp.
        NEW.consumed_at:=clock_timestamp();
    ELSE
        RAISE EXCEPTION 'Portal email proof state refused' USING ERRCODE='42501';
    END IF;
    NEW.updated_at:=clock_timestamp();
    RETURN NEW;
END;
$portal_email_proof$;

REVOKE ALL ON FUNCTION public.portal_guard_email_proof_challenge() FROM PUBLIC;
CREATE TRIGGER portal_email_proof_guard
BEFORE UPDATE ON public.portal_email_proof_challenges
FOR EACH ROW EXECUTE FUNCTION public.portal_guard_email_proof_challenge();

ALTER TABLE public.portal_email_proof_challenges ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_email_proof_challenges FORCE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.portal_email_proof_challenges FROM PUBLIC;
-- Intentionally zero RLS allow policies, no SELECT/INSERT/UPDATE grants.
SQL);
    }

    public function down(): void
    {
        $this->requireFoundation();
        DB::transaction(function (): void {
            DB::statement('SET LOCAL row_security=off');
            if (Schema::hasTable('portal_email_proof_challenges')
                && DB::table('portal_email_proof_challenges')->exists()) {
                throw new RuntimeException(
                    'Email proof rollback denied: challenge evidence requires audited disposal.',
                );
            }
            DB::statement('DROP TABLE IF EXISTS public.portal_email_proof_challenges');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_guard_email_proof_challenge()');
        });
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_identities')
            || !Schema::hasTable('portal_memberships')
            || !Schema::hasTable('portal_auth_sessions')) {
            throw new RuntimeException(
                'Portal email proof requires reviewed PostgreSQL identity foundation.',
            );
        }
    }
};
