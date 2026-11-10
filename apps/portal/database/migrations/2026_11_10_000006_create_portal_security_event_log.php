<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F89/F107: append-only audit envelope for Portal security events, not a trusted
 * event source. Default DENY RLS and no application policy.
 *
 * This table alone cannot resist PostgreSQL backup rollback, a DBA who drops
 * triggers, or a forged event writer. Independent SEC-R03 witness, verified
 * event provenance, retention/LGPD and non-superuser writer are REQUIRED.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_identities')
            || !Schema::hasTable('portal_organizations')) {
            throw new RuntimeException('F89 audit schema requires F66 PostgreSQL identity foundation.');
        }

        Schema::create('portal_security_events', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('event_id')->unique();
            $table->foreignUuid('organization_id')
                ->constrained('portal_organizations')->restrictOnDelete();
            $table->foreignUuid('identity_id')->nullable()
                ->constrained('portal_identities')->restrictOnDelete();
            $table->string('event_code', 36);
            // Digest of caller-provided sanitized event evidence, not the
            // event body, bearer, password, IP address or MFA secret.
            $table->char('evidence_sha256', 64);
            $table->unsignedBigInteger('security_revision');
            $table->timestampTz('recorded_at')->useCurrent();
            $table->index(['organization_id', 'sequence'], 'portal_security_events_org_seq_idx');
        });

        // If an identity is present, enforce its existing membership in
        // exactly the audited organization. Organization-wide events may omit
        // identity_id; this FK must not be treated as proof of event authority.
        DB::statement(
            'ALTER TABLE public.portal_security_events '
            . 'ADD CONSTRAINT portal_security_event_membership_fk '
            . 'FOREIGN KEY (identity_id, organization_id) '
            . 'REFERENCES public.portal_memberships (identity_id, organization_id) '
            . 'ON DELETE RESTRICT'
        );

        DB::statement(
            "ALTER TABLE portal_security_events ADD CONSTRAINT portal_security_event_code_ck "
            . "CHECK (event_code IN ("
            . "'identity.revoked','membership.revoked','organization.suspended',"
            . "'session.revoked','mfa.reset','grant.revoked','invitation.consumed'))"
        );
        DB::statement(
            "ALTER TABLE portal_security_events ADD CONSTRAINT portal_security_evidence_hash_ck "
            . "CHECK (evidence_sha256 ~ '^[a-f0-9]{64}$')"
        );
        DB::statement(
            'ALTER TABLE portal_security_events ADD CONSTRAINT portal_security_event_revision_ck '
            . 'CHECK (security_revision > 0)'
        );

        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_f89_deny_security_event_mutation()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    RAISE EXCEPTION 'Portal security events are append-only'
        USING ERRCODE = '42501';
END;
$$;
SQL);
        DB::statement(
            'REVOKE ALL ON FUNCTION public.portal_f89_deny_security_event_mutation() FROM PUBLIC'
        );

        DB::statement(
            'CREATE TRIGGER portal_security_events_no_change '
            . 'BEFORE UPDATE OR DELETE ON public.portal_security_events '
            . 'FOR EACH ROW EXECUTE FUNCTION public.portal_f89_deny_security_event_mutation()'
        );
        DB::statement(
            'CREATE TRIGGER portal_security_events_no_truncate '
            . 'BEFORE TRUNCATE ON public.portal_security_events '
            . 'FOR EACH STATEMENT EXECUTE FUNCTION public.portal_f89_deny_security_event_mutation()'
        );

        // No SELECT, INSERT, UPDATE or DELETE policy for any application role.
        // Even an accidental GRANT to an ordinary role must reveal no rows.
        DB::statement('ALTER TABLE public.portal_security_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.portal_security_events FORCE ROW LEVEL SECURITY');
        DB::statement('REVOKE ALL ON public.portal_security_events FROM PUBLIC');
        DB::statement('REVOKE ALL ON SEQUENCE public.portal_security_events_sequence_seq FROM PUBLIC');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('F89 audit rollback requires PostgreSQL.');
        }
        Schema::dropIfExists('portal_security_events');
        DB::statement('DROP FUNCTION IF EXISTS public.portal_f89_deny_security_event_mutation()');
    }
};
