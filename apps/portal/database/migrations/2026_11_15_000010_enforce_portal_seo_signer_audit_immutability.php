<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F130: PostgreSQL-side defense in depth for key lifecycle and append-only
 * audit. This is still a REVIEW LAB: superusers/table owners can disable
 * triggers. It does not supply an authenticated administrator or signer.
 */
return new class extends Migration {
    public function up():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F130 PostgreSQL required');

        // A revoked key may have exactly one audit event, independent of
        // event_digest/actor. Existing F129 events already follow this rule.
        DB::statement("CREATE UNIQUE INDEX seo_f130_one_revoke_per_key
            ON public.portal_seo_build_signer_events
            (tenant_ref,signer_ref,public_key_fingerprint)
            WHERE event_code='signer_key_revoked'");

        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_seo_f130_guard_key_mutation()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public
AS $f130$
BEGIN
    IF TG_OP='DELETE' THEN
        RAISE EXCEPTION 'F130: signing keys cannot be deleted'
            USING ERRCODE='23514';
    END IF;

    IF OLD.key_status <> 'active' OR
       NEW.key_status <> 'revoked' OR
       OLD.revoked_at IS NOT NULL OR
       NEW.revoked_at IS NULL OR
       ROW(NEW.tenant_ref,NEW.signer_ref,NEW.public_key_fingerprint,
           NEW.public_key_base64,NEW.not_before,NEW.expires_at)
           IS DISTINCT FROM
       ROW(OLD.tenant_ref,OLD.signer_ref,OLD.public_key_fingerprint,
           OLD.public_key_base64,OLD.not_before,OLD.expires_at)
    THEN
        RAISE EXCEPTION 'F130: only active-to-revoked is supported'
            USING ERRCODE='23514';
    END IF;

    RETURN NEW;
END;
$f130$
SQL);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_seo_f130_guard_event_immutable()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public
AS $f130$
BEGIN
    RAISE EXCEPTION 'F130: revocation events are append-only'
        USING ERRCODE='23514';
END;
$f130$
SQL);
        // Audit-only inserts must not predate a matching key transition.
        // SECURITY INVOKER: both target rows remain under tenant FORCE RLS.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_seo_f130_guard_event_insert()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public
AS $f130$
BEGIN
    IF NOT EXISTS(
        SELECT 1 FROM public.portal_seo_build_signing_keys k
        WHERE k.tenant_ref=NEW.tenant_ref
          AND k.signer_ref=NEW.signer_ref
          AND k.public_key_fingerprint=NEW.public_key_fingerprint
          AND k.key_status='revoked'
          AND k.revoked_at=NEW.occurred_at
    ) THEN
        RAISE EXCEPTION 'F130: audit event without matching revocation'
            USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$f130$
SQL);
        // A direct UPDATE to 'revoked' with no audit event cannot commit.
        // It is deferred so the existing F129 UPDATE-then-INSERT procedure
        // can succeed when both operations share a transaction.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_seo_f130_require_event_on_revoke()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public
AS $f130$
BEGIN
    IF NOT EXISTS(
        SELECT 1 FROM public.portal_seo_build_signer_events e
        WHERE e.tenant_ref=NEW.tenant_ref
          AND e.signer_ref=NEW.signer_ref
          AND e.public_key_fingerprint=NEW.public_key_fingerprint
          AND e.event_code='signer_key_revoked'
          AND e.occurred_at=NEW.revoked_at
    ) THEN
        RAISE EXCEPTION 'F130: key revocation requires committed audit'
            USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END;
$f130$
SQL);
        DB::statement("CREATE TRIGGER seo_f130_key_lifecycle_guard
            BEFORE UPDATE OR DELETE ON public.portal_seo_build_signing_keys
            FOR EACH ROW
            EXECUTE FUNCTION public.portal_seo_f130_guard_key_mutation()");
        DB::statement("CREATE CONSTRAINT TRIGGER seo_f130_key_must_be_audited
            AFTER UPDATE ON public.portal_seo_build_signing_keys
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.portal_seo_f130_require_event_on_revoke()");
        DB::statement("CREATE TRIGGER seo_f130_event_immutable_guard
            BEFORE UPDATE OR DELETE ON public.portal_seo_build_signer_events
            FOR EACH ROW
            EXECUTE FUNCTION public.portal_seo_f130_guard_event_immutable()");
        DB::statement("CREATE TRIGGER seo_f130_event_creation_guard
            BEFORE INSERT ON public.portal_seo_build_signer_events
            FOR EACH ROW
            EXECUTE FUNCTION public.portal_seo_f130_guard_event_insert()");
    }

    public function down():void
    {
        if(DB::getDriverName()!=='pgsql')
            throw new RuntimeException('F130 rollback PostgreSQL required');

        DB::statement('DROP TRIGGER IF EXISTS seo_f130_key_must_be_audited
            ON public.portal_seo_build_signing_keys');
        DB::statement('DROP TRIGGER IF EXISTS seo_f130_key_lifecycle_guard
            ON public.portal_seo_build_signing_keys');
        DB::statement('DROP TRIGGER IF EXISTS seo_f130_event_immutable_guard
            ON public.portal_seo_build_signer_events');
        DB::statement('DROP TRIGGER IF EXISTS seo_f130_event_creation_guard
            ON public.portal_seo_build_signer_events');
        foreach([
            'portal_seo_f130_guard_key_mutation',
            'portal_seo_f130_guard_event_immutable',
            'portal_seo_f130_guard_event_insert',
            'portal_seo_f130_require_event_on_revoke'
        ] as $function)
            DB::statement('DROP FUNCTION IF EXISTS public.'.$function.'()');
        DB::statement('DROP INDEX IF EXISTS public.seo_f130_one_revoke_per_key');
    }
};
