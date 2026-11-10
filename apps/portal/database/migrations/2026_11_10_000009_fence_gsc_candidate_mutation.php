<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GSC-F124: immutable property/tenant bindings and one-way revocation.
 *
 * The trigger is a defensive schema fence, NOT a proof of Google access,
 * identity, MFA or approval to serve a client. Application RLS remains closed.
 */
return new class extends Migration
{
    private const TABLE = 'portal_gsc_property_candidates';

    public function up(): void
    {
        $this->requireTable();

        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.portal_gsc_f124_guard_candidate_update()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, pg_temp
AS $f124$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
        OR NEW.created_by_identity_id IS DISTINCT FROM OLD.created_by_identity_id
        OR NEW.site_url IS DISTINCT FROM OLD.site_url
        OR NEW.site_fingerprint_sha256 IS DISTINCT FROM OLD.site_fingerprint_sha256
        OR NEW.site_type IS DISTINCT FROM OLD.site_type
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'GSC property candidate identity and tenant are immutable'
            USING ERRCODE = '42501';
    END IF;

    IF OLD.status = 'revoked'
       OR (OLD.status = 'disabled' AND NEW.status <> 'disabled' AND NEW.status <> 'revoked')
    THEN
        RAISE EXCEPTION 'GSC property candidate revocation/status is not reversible'
            USING ERRCODE = '42501';
    END IF;

    IF NEW.security_revision <= OLD.security_revision
    THEN
        RAISE EXCEPTION 'GSC property candidate security revision must advance'
            USING ERRCODE = '42501';
    END IF;

    RETURN NEW;
END;
$f124$;
SQL);

        DB::statement(
            'REVOKE ALL ON FUNCTION public.portal_gsc_f124_guard_candidate_update() FROM PUBLIC'
        );
        DB::statement(
            'CREATE TRIGGER portal_gsc_candidate_immutable_update '
            .'BEFORE UPDATE ON public.portal_gsc_property_candidates '
            .'FOR EACH ROW EXECUTE FUNCTION public.portal_gsc_f124_guard_candidate_update()'
        );
    }

    public function down(): void
    {
        $this->requireTable();

        // Removing a mutation fence while rows still exist would allow
        // previously terminal revocations to be silently reactivated.
        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException(
                'GSC-F124 rollback denied: property candidates must be audited and cleared.'
            );
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS portal_gsc_candidate_immutable_update '
            .'ON public.portal_gsc_property_candidates'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS public.portal_gsc_f124_guard_candidate_update()'
        );
    }

    private function requireTable(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('GSC-F124 requires the PostgreSQL F123 property schema.');
        }
    }
};
