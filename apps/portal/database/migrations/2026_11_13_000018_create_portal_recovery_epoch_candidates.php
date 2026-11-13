<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F373: dormant, production-shaped LOCAL evidence for Portal recovery epochs.
 *
 * NOT independent witness authority and NOT installed by the Portal private
 * foundation provisioner. Its only state is QUARANTINED. No auth decisions,
 * business grants, public routes or serving can be derived from the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_organizations')
            || !Schema::hasTable('portal_auth_sessions')) {
            throw new RuntimeException('F373 requires reviewed PostgreSQL Portal identity foundation.');
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE public.portal_recovery_epoch_candidates (
    organization_id uuid PRIMARY KEY
        REFERENCES public.portal_organizations(id) ON DELETE RESTRICT,
    instance varchar(64) NOT NULL
        CHECK(instance ~ '^[A-Za-z0-9_-]{1,64}$'),
    generation bigint NOT NULL CHECK(generation BETWEEN 1 AND 1000000000),
    session_revision bigint NOT NULL CHECK(session_revision BETWEEN 1 AND 1000000000),
    membership_revision bigint NOT NULL CHECK(membership_revision BETWEEN 1 AND 1000000000),
    witnessed_sequence bigint NOT NULL CHECK(witnessed_sequence BETWEEN 1 AND 1000000000),
    state varchar(16) NOT NULL DEFAULT 'quarantined'
        CHECK(state = 'quarantined'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE FUNCTION public.portal_f373_local_recovery_epoch_guard()
RETURNS trigger
LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp
AS $f373$
BEGIN
    IF NEW.organization_id IS DISTINCT FROM OLD.organization_id
       OR NEW.instance IS DISTINCT FROM OLD.instance
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Recovery epoch tenant/instance is immutable'
            USING ERRCODE='42501';
    END IF;
    IF NEW.state IS DISTINCT FROM 'quarantined'
       OR NEW.generation < OLD.generation
       OR NEW.session_revision < OLD.session_revision
       OR NEW.membership_revision < OLD.membership_revision
       OR NEW.witnessed_sequence < OLD.witnessed_sequence THEN
        RAISE EXCEPTION 'Recovery epoch must not regress or authorize serving'
            USING ERRCODE='42501';
    END IF;
    IF (NEW.generation IS DISTINCT FROM OLD.generation
        OR NEW.session_revision IS DISTINCT FROM OLD.session_revision
        OR NEW.membership_revision IS DISTINCT FROM OLD.membership_revision
        OR NEW.witnessed_sequence IS DISTINCT FROM OLD.witnessed_sequence)
       AND NEW.witnessed_sequence IS DISTINCT FROM OLD.witnessed_sequence + 1 THEN
        RAISE EXCEPTION 'Recovery epoch change requires exact next witnessed sequence'
            USING ERRCODE='42501';
    END IF;
    RETURN NEW;
END;
$f373$;

REVOKE ALL ON FUNCTION public.portal_f373_local_recovery_epoch_guard() FROM PUBLIC;
CREATE TRIGGER portal_f373_recovery_epoch_guard
BEFORE UPDATE ON public.portal_recovery_epoch_candidates
FOR EACH ROW EXECUTE FUNCTION public.portal_f373_local_recovery_epoch_guard();

ALTER TABLE public.portal_recovery_epoch_candidates ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_recovery_epoch_candidates FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_recovery_epoch_candidates FROM PUBLIC;

-- NOLOGIN and no memberships, bypass or DDL powers. A later separate,
-- independently approved credential provider would be required for access.
CREATE ROLE portal_recovery_epoch_reader
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE ON SCHEMA public TO portal_recovery_epoch_reader;
GRANT SELECT(organization_id,instance,generation,session_revision,
             membership_revision,witnessed_sequence,state)
  ON public.portal_recovery_epoch_candidates TO portal_recovery_epoch_reader;
CREATE POLICY portal_f373_epoch_reader ON public.portal_recovery_epoch_candidates
  FOR SELECT TO portal_recovery_epoch_reader USING(true);

-- Explicitly no runtime role grants, no RLS writer policy, no serving state
-- and no session/recovery authority.
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('F373 rollback requires PostgreSQL.');
        }
        DB::transaction(function (): void {
            DB::statement('SET LOCAL row_security=off');
            if (Schema::hasTable('portal_recovery_epoch_candidates')
                && DB::table('portal_recovery_epoch_candidates')->exists()) {
                throw new RuntimeException('F373 rollback denied: recovery evidence requires audited cleanup.');
            }
            DB::statement('DROP TABLE IF EXISTS public.portal_recovery_epoch_candidates');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_f373_local_recovery_epoch_guard()');
            // Intentionally do not drop the privileged role automatically.
            // A DBA must audit global role usage before any role deletion.
        });
    }
};
