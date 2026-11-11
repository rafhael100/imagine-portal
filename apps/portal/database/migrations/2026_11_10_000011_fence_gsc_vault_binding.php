<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GSC-F126: vault reference candidate metadata is append-once, revoke-once.
 *
 * Only a digest of a future locator exists in the table, never the locator
 * itself or OAuth material. Trigger is an additional defense for future
 * write paths; no RLS application policy or operational Google integration.
 */
return new class extends Migration
{
    private const TABLE = 'portal_gsc_vault_reference_candidates';

    public function up(): void
    {
        $this->requireVaultTable();

        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_gsc_f126_guard_vault_binding()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $f126$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status IS DISTINCT FROM 'pending_review'
            OR NEW.revoked_at IS NOT NULL
            OR NEW.binding_revision IS DISTINCT FROM 1
        THEN
            RAISE EXCEPTION 'GSC vault reference insertion must be pending and unapproved'
                USING ERRCODE = '42501';
        END IF;
        RETURN NEW;
    END IF;

    -- This is a candidate locator digest, NOT a live credential. Neither
    -- a revoked row nor any of its identity fields may ever be rebound.
    IF OLD.status IS DISTINCT FROM 'pending_review'
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
        OR NEW.candidate_id IS DISTINCT FROM OLD.candidate_id
        OR NEW.vault_reference_sha256 IS DISTINCT FROM OLD.vault_reference_sha256
        OR NEW.key_version IS DISTINCT FROM OLD.key_version
        OR NEW.scope IS DISTINCT FROM OLD.scope
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'GSC vault candidate identity is immutable or terminally revoked'
            USING ERRCODE = '42501';
    END IF;

    -- A metadata update that keeps pending_review must not provide a
    -- channel to mutate revision/timestamps or revive an older binding.
    IF NEW.status IS DISTINCT FROM 'revoked'
        OR NEW.revoked_at IS NULL
        OR NEW.revoked_at < OLD.created_at
        OR NEW.revoked_at > clock_timestamp() + interval '60 seconds'
        OR NEW.binding_revision IS DISTINCT FROM OLD.binding_revision + 1
    THEN
        RAISE EXCEPTION 'GSC vault reference permits only one audited revoke'
            USING ERRCODE = '42501';
    END IF;

    RETURN NEW;
END;
$f126$;
SQL);

        DB::statement(
            'REVOKE ALL ON FUNCTION public.portal_gsc_f126_guard_vault_binding() FROM PUBLIC'
        );
        DB::statement(
            'CREATE TRIGGER portal_gsc_vault_binding_guard '
            .'BEFORE INSERT OR UPDATE ON public.portal_gsc_vault_reference_candidates '
            .'FOR EACH ROW EXECUTE FUNCTION public.portal_gsc_f126_guard_vault_binding()'
        );
    }

    public function down(): void
    {
        $this->requireVaultTable();

        // Never silently remove an immutability fence from existing rows.
        // In CI the synthetic fixtures are explicitly removed and tested.
        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException(
                'GSC-F126 rollback denied: existing vault candidate metadata requires audited cleanup.'
            );
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS portal_gsc_vault_binding_guard '
            .'ON public.portal_gsc_vault_reference_candidates'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS public.portal_gsc_f126_guard_vault_binding()'
        );
    }

    private function requireVaultTable(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('GSC-F126 requires PostgreSQL and F125 vault candidates.');
        }
    }
};
