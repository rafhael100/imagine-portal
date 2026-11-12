<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PORTAL-LARAVEL-F309: durable, unverified ownership CANDIDATE ledger.
 *
 * No application or browser role can read, insert, promote or update this
 * table. No verified ownership exists until an independently audited issuer
 * and transactional resource-authority workflow are delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();

        DB::unprepared(<<<'SQL'
CREATE TABLE public.portal_service_resource_claims (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL REFERENCES public.portal_organizations(id) ON DELETE RESTRICT,
    source_system varchar(32) NOT NULL,
    source_resource_ref varchar(128) NOT NULL,
    product_code varchar(32) NOT NULL,
    state varchar(16) NOT NULL DEFAULT 'candidate',
    security_revision bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT portal_service_claim_source_ck CHECK (source_system IN ('imagine_sites','imagine_performance')),
    CONSTRAINT portal_service_claim_product_ck CHECK (product_code IN ('sites_onepage','sites_landing_page','performance_ai')),
    CONSTRAINT portal_service_claim_state_ck CHECK (state IN ('candidate','retired')),
    CONSTRAINT portal_service_claim_revision_ck CHECK (security_revision > 0),
    CONSTRAINT portal_service_claim_reference_ck CHECK (source_resource_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CONSTRAINT portal_service_claim_source_product_ck CHECK (
        (source_system='imagine_sites' AND product_code IN ('sites_onepage','sites_landing_page'))
        OR (source_system='imagine_performance' AND product_code='performance_ai')
    ),
    CONSTRAINT portal_service_claim_source_ref_uq UNIQUE (source_system, source_resource_ref)
);
CREATE INDEX portal_service_claim_org_idx
 ON public.portal_service_resource_claims (organization_id, state);

CREATE FUNCTION public.portal_f309_guard_service_resource_claim()
RETURNS trigger
LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp
AS $portal_f309$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
        OR NEW.source_system IS DISTINCT FROM OLD.source_system
        OR NEW.source_resource_ref IS DISTINCT FROM OLD.source_resource_ref
        OR NEW.product_code IS DISTINCT FROM OLD.product_code
        OR NEW.created_at IS DISTINCT FROM OLD.created_at
    THEN
        RAISE EXCEPTION 'Portal service candidate identity/tenant is immutable'
            USING ERRCODE='42501';
    END IF;

    IF OLD.state='retired' AND NEW.state IS DISTINCT FROM OLD.state THEN
        RAISE EXCEPTION 'Retired Portal service claim cannot be restored'
            USING ERRCODE='42501';
    END IF;

    IF NEW.state IS DISTINCT FROM OLD.state THEN
        IF OLD.state<>'candidate' OR NEW.state<>'retired' THEN
            RAISE EXCEPTION 'Portal service claim state transition is forbidden'
                USING ERRCODE='42501';
        END IF;
        IF OLD.security_revision>=9223372036854775807 THEN
            RAISE EXCEPTION 'Portal service claim revision exhausted'
                USING ERRCODE='22003';
        END IF;
        NEW.security_revision:=OLD.security_revision+1;
        NEW.updated_at:=clock_timestamp();
    ELSIF NEW.security_revision IS DISTINCT FROM OLD.security_revision THEN
        RAISE EXCEPTION 'Portal service claim revision is not caller-controlled'
            USING ERRCODE='42501';
    END IF;
    RETURN NEW;
END;
$portal_f309$;

REVOKE ALL ON FUNCTION public.portal_f309_guard_service_resource_claim() FROM PUBLIC;
CREATE TRIGGER portal_f309_service_claim_guard
BEFORE UPDATE ON public.portal_service_resource_claims
FOR EACH ROW EXECUTE FUNCTION public.portal_f309_guard_service_resource_claim();

ALTER TABLE public.portal_service_resource_claims ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_service_resource_claims FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_service_resource_claims FROM PUBLIC;
-- Deliberately no SELECT/INSERT/UPDATE grants and no RLS allow policy.
SQL);
    }

    public function down(): void
    {
        $this->requireFoundation();
        DB::transaction(function (): void {
            DB::statement('SET LOCAL row_security=off');
            if (Schema::hasTable('portal_service_resource_claims')
                && DB::table('portal_service_resource_claims')->exists()) {
                throw new RuntimeException(
                    'F309 rollback denied: service ownership evidence requires audited cleanup.',
                );
            }
            DB::statement('DROP TABLE IF EXISTS public.portal_service_resource_claims');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_f309_guard_service_resource_claim()');
        });
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_organizations')) {
            throw new RuntimeException(
                'F309 requires Portal-owned PostgreSQL organization foundation.',
            );
        }
    }
};
