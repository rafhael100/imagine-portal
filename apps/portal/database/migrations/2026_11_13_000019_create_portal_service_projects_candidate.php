<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review-only tenant-bound project preparation, never service entitlement.
 *
 * A project is a draft/review candidate and NEVER active, approved, billable,
 * published or visible through an HTTP route. No runtime SQL privileges,
 * grants, RLS allow policy, customer account, or production migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();

        DB::unprepared(<<<'SQL'
ALTER TABLE public.portal_service_resource_claims
ADD CONSTRAINT portal_service_claim_project_scope_uq
UNIQUE (id, organization_id, product_code);

CREATE TABLE public.portal_service_projects (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL REFERENCES public.portal_organizations(id) ON DELETE RESTRICT,
    project_ref varchar(128) NOT NULL,
    product_code varchar(32) NOT NULL,
    source_claim_id uuid,
    state varchar(16) NOT NULL DEFAULT 'draft',
    project_revision bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT portal_service_project_product_ck
        CHECK (product_code IN ('sites_onepage','sites_landing_page','performance_ai')),
    CONSTRAINT portal_service_project_reference_ck
        CHECK (project_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CONSTRAINT portal_service_project_state_ck
        CHECK (state IN ('draft','review','retired')),
    CONSTRAINT portal_service_project_review_requires_claim_ck
        CHECK (state <> 'review' OR source_claim_id IS NOT NULL),
    CONSTRAINT portal_service_project_revision_ck
        CHECK (project_revision BETWEEN 1 AND 1000000000),
    CONSTRAINT portal_service_project_tenant_ref_uq
        UNIQUE (organization_id, project_ref),
    CONSTRAINT portal_service_project_claim_unique UNIQUE (source_claim_id),
    CONSTRAINT portal_service_project_claim_scope_fk FOREIGN KEY
        (source_claim_id, organization_id, product_code)
        REFERENCES public.portal_service_resource_claims
        (id, organization_id, product_code) ON DELETE RESTRICT
);
CREATE INDEX portal_service_project_org_state_idx
    ON public.portal_service_projects (organization_id, state, product_code);

CREATE FUNCTION public.portal_guard_service_project_candidate()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $portal_project$
DECLARE v_org_status text; v_claim_state text;
BEGIN
    IF TG_OP='DELETE' THEN
        RAISE EXCEPTION 'Portal project candidate evidence cannot be deleted'
            USING ERRCODE='42501';
    END IF;

    IF TG_OP='INSERT' THEN
        SELECT status INTO v_org_status
        FROM public.portal_organizations WHERE id=NEW.organization_id FOR SHARE;
        IF v_org_status IS DISTINCT FROM 'active'
           OR NEW.state<>'draft' OR NEW.project_revision<>1
           OR NEW.source_claim_id IS NOT NULL THEN
            RAISE EXCEPTION 'Portal project candidate must begin as an unbound active-org draft'
                USING ERRCODE='42501';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
       OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
       OR NEW.project_ref IS DISTINCT FROM OLD.project_ref
       OR NEW.product_code IS DISTINCT FROM OLD.product_code
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Portal project identity, tenant and product are immutable'
            USING ERRCODE='42501';
    END IF;

    IF OLD.state='retired' THEN
        RAISE EXCEPTION 'Retired Portal project cannot be altered'
            USING ERRCODE='42501';
    END IF;

    IF NEW.source_claim_id IS DISTINCT FROM OLD.source_claim_id THEN
        IF OLD.source_claim_id IS NOT NULL OR NEW.source_claim_id IS NULL
           OR OLD.state<>'draft' OR NEW.state<>'draft' THEN
            RAISE EXCEPTION 'Portal project claim can only bind once in draft'
                USING ERRCODE='42501';
        END IF;
        SELECT state INTO v_claim_state
          FROM public.portal_service_resource_claims
         WHERE id=NEW.source_claim_id
           AND organization_id=NEW.organization_id
           AND product_code=NEW.product_code FOR SHARE;
        IF v_claim_state IS DISTINCT FROM 'candidate' THEN
            RAISE EXCEPTION 'Portal project claim is not a current matching candidate'
                USING ERRCODE='42501';
        END IF;
    END IF;

    IF NEW.state IS DISTINCT FROM OLD.state
       AND NOT ((OLD.state='draft' AND NEW.state IN ('review','retired'))
            OR (OLD.state='review' AND NEW.state='retired')) THEN
        RAISE EXCEPTION 'Portal project candidate stage transition refused'
            USING ERRCODE='42501';
    END IF;

    IF NEW.project_revision IS DISTINCT FROM OLD.project_revision THEN
        RAISE EXCEPTION 'Project revision cannot be caller-controlled'
            USING ERRCODE='42501';
    END IF;

    IF NEW.state IS DISTINCT FROM OLD.state
        OR NEW.source_claim_id IS DISTINCT FROM OLD.source_claim_id THEN
        IF OLD.project_revision>=1000000000 THEN
            RAISE EXCEPTION 'Project candidate revision exhausted'
                USING ERRCODE='22003';
        END IF;
        NEW.project_revision:=OLD.project_revision+1;
        NEW.updated_at:=clock_timestamp();
    ELSIF NEW.updated_at IS DISTINCT FROM OLD.updated_at THEN
        RAISE EXCEPTION 'Project timestamp cannot be caller-controlled'
            USING ERRCODE='42501';
    END IF;

    RETURN NEW;
END;
$portal_project$;

REVOKE ALL ON FUNCTION public.portal_guard_service_project_candidate() FROM PUBLIC;
CREATE TRIGGER portal_service_project_candidate_guard
BEFORE INSERT OR UPDATE OR DELETE ON public.portal_service_projects
FOR EACH ROW EXECUTE FUNCTION public.portal_guard_service_project_candidate();

ALTER TABLE public.portal_service_projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_service_projects FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_service_projects FROM PUBLIC;
-- NO RLS allow policies, NO runtime grants and NO project access route.
SQL);
    }

    public function down(): void
    {
        $this->requireFoundation();
        DB::transaction(function (): void {
            DB::statement('SET LOCAL row_security=off');
            if (Schema::hasTable('portal_service_projects')
                && DB::table('portal_service_projects')->exists()) {
                throw new RuntimeException(
                    'Portal project rollback refused: candidate records require audited disposal.'
                );
            }
            DB::statement('DROP TABLE IF EXISTS public.portal_service_projects');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_guard_service_project_candidate()');
            DB::statement(
                'ALTER TABLE public.portal_service_resource_claims '
                .'DROP CONSTRAINT IF EXISTS portal_service_claim_project_scope_uq'
            );
        });
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_organizations')
            || !Schema::hasTable('portal_service_resource_claims')) {
            throw new RuntimeException(
                'Portal project candidate requires reviewed PostgreSQL tenant and ownership-claim foundation.'
            );
        }
    }
};
