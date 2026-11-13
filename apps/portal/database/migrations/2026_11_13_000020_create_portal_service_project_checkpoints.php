<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate-only preparation checkpoints; no live approvals or entitlement.
 *
 * A service project may reach internal 'review' only with its candidate
 * resource claim bound AND all required per-product checkpoints reviewed.
 * The reviewed state is NOT permission to publish, bill, run ads or grant
 * customer access. Zero runtime policies/grants and no HTTP endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->requireFoundation();

        DB::unprepared(<<<'SQL'
ALTER TABLE public.portal_service_projects
ADD CONSTRAINT portal_service_project_tenant_identity_uq
UNIQUE (id, organization_id);

CREATE TABLE public.portal_service_project_checkpoints (
    id uuid PRIMARY KEY,
    project_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    checkpoint_code varchar(32) NOT NULL,
    state varchar(12) NOT NULL DEFAULT 'pending',
    checkpoint_revision bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT portal_project_checkpoint_fk FOREIGN KEY (project_id,organization_id)
        REFERENCES public.portal_service_projects(id,organization_id)
        ON DELETE RESTRICT,
    CONSTRAINT portal_project_checkpoint_unique
        UNIQUE (project_id,checkpoint_code),
    CONSTRAINT portal_project_checkpoint_code_ck
        CHECK (checkpoint_code IN (
            'briefing','content','domain','consent',
            'goals','ad_accounts','creatives','budget'
        )),
    CONSTRAINT portal_project_checkpoint_state_ck
        CHECK (state IN ('pending','reviewed')),
    CONSTRAINT portal_project_checkpoint_revision_ck
        CHECK (checkpoint_revision BETWEEN 1 AND 1000000000)
);
CREATE INDEX portal_service_project_check_org_idx
    ON public.portal_service_project_checkpoints
    (organization_id,project_id,state);

CREATE FUNCTION public.portal_guard_service_project_checkpoint()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $portal_checkpoint$
DECLARE v_project record;
BEGIN
    IF TG_OP='DELETE' THEN
        RAISE EXCEPTION 'Project checkpoint evidence cannot be deleted'
            USING ERRCODE='42501';
    END IF;

    SELECT p.product_code,p.state AS project_state,o.status AS org_status
       INTO v_project
      FROM public.portal_service_projects p
      JOIN public.portal_organizations o ON o.id=p.organization_id
     WHERE p.id=NEW.project_id AND p.organization_id=NEW.organization_id
     FOR SHARE OF p,o;

    IF NOT FOUND OR v_project.project_state<>'draft'
       OR v_project.org_status<>'active'
       OR NOT (
           (v_project.product_code='sites_onepage'
             AND NEW.checkpoint_code IN ('briefing','content','domain'))
           OR (v_project.product_code='sites_landing_page'
             AND NEW.checkpoint_code IN ('briefing','content','consent'))
           OR (v_project.product_code='performance_ai'
             AND NEW.checkpoint_code IN ('goals','ad_accounts','creatives','budget'))
       ) THEN
        RAISE EXCEPTION 'Project checkpoint does not belong to active draft/product'
            USING ERRCODE='42501';
    END IF;

    IF TG_OP='INSERT' THEN
        IF NEW.state<>'pending' OR NEW.checkpoint_revision<>1 THEN
            RAISE EXCEPTION 'Checkpoint must begin as unreviewed candidate'
                USING ERRCODE='42501';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
       OR NEW.project_id IS DISTINCT FROM OLD.project_id
       OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
       OR NEW.checkpoint_code IS DISTINCT FROM OLD.checkpoint_code
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
       OR NEW.checkpoint_revision IS DISTINCT FROM OLD.checkpoint_revision THEN
        RAISE EXCEPTION 'Checkpoint identity, tenant, code and revision immutable'
            USING ERRCODE='42501';
    END IF;
    IF OLD.state<>'pending' OR NEW.state<>'reviewed' THEN
        RAISE EXCEPTION 'Checkpoint is single-use and cannot be unreviewed'
            USING ERRCODE='42501';
    END IF;
    IF OLD.checkpoint_revision>=1000000000 THEN
        RAISE EXCEPTION 'Checkpoint revision exhausted' USING ERRCODE='22003';
    END IF;
    NEW.checkpoint_revision:=OLD.checkpoint_revision+1;
    NEW.updated_at:=clock_timestamp();
    RETURN NEW;
END;
$portal_checkpoint$;

REVOKE ALL ON FUNCTION public.portal_guard_service_project_checkpoint() FROM PUBLIC;
CREATE TRIGGER portal_service_project_checkpoint_guard
BEFORE INSERT OR UPDATE OR DELETE ON public.portal_service_project_checkpoints
FOR EACH ROW EXECUTE FUNCTION public.portal_guard_service_project_checkpoint();

CREATE FUNCTION public.portal_guard_project_review_readiness()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $portal_readiness$
DECLARE required_count integer; reviewed_count integer;
        v_claim_state text; v_org_status text;
BEGIN
    IF NEW.state='review' AND OLD.state='draft' THEN
        -- The bound claim may have been retired since it was linked in
        -- F11. Lock its current state and the company in the SAME
        -- transaction before accepting a candidate for internal review.
        SELECT c.state,o.status INTO v_claim_state,v_org_status
          FROM public.portal_service_resource_claims c
          JOIN public.portal_organizations o ON o.id=c.organization_id
         WHERE c.id=NEW.source_claim_id
           AND c.organization_id=NEW.organization_id
           AND c.product_code=NEW.product_code
         FOR SHARE OF c,o;
        IF v_claim_state IS DISTINCT FROM 'candidate'
           OR v_org_status IS DISTINCT FROM 'active' THEN
            RAISE EXCEPTION 'Project review requires current claim and active tenant'
                USING ERRCODE='42501';
        END IF;
        required_count:=CASE NEW.product_code
            WHEN 'sites_onepage' THEN 3
            WHEN 'sites_landing_page' THEN 3
            WHEN 'performance_ai' THEN 4
            ELSE 0 END;
        SELECT count(*)::integer INTO reviewed_count
          FROM public.portal_service_project_checkpoints c
         WHERE c.project_id=NEW.id
           AND c.organization_id=NEW.organization_id
           AND c.state='reviewed';
        IF required_count=0 OR reviewed_count<>required_count
           OR EXISTS (
                SELECT 1 FROM public.portal_service_project_checkpoints c
                 WHERE c.project_id=NEW.id AND c.organization_id=NEW.organization_id
                   AND c.state<>'reviewed'
           ) THEN
            RAISE EXCEPTION 'Project review requires complete product checklist'
                USING ERRCODE='42501';
        END IF;
    END IF;
    RETURN NEW;
END;
$portal_readiness$;

REVOKE ALL ON FUNCTION public.portal_guard_project_review_readiness() FROM PUBLIC;
CREATE TRIGGER portal_service_project_readiness_guard
BEFORE UPDATE ON public.portal_service_projects
FOR EACH ROW EXECUTE FUNCTION public.portal_guard_project_review_readiness();

ALTER TABLE public.portal_service_project_checkpoints ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_service_project_checkpoints FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_service_project_checkpoints FROM PUBLIC;
-- No RLS policies, no runtime table privileges, no operating app service.
SQL);
    }

    public function down(): void
    {
        $this->requireFoundation();
        DB::transaction(function (): void {
            DB::statement('SET LOCAL row_security=off');
            if (Schema::hasTable('portal_service_project_checkpoints')
                && DB::table('portal_service_project_checkpoints')->exists()) {
                throw new RuntimeException(
                    'Project checkpoint rollback refused: reviewed candidate evidence requires audited disposal.'
                );
            }
            DB::statement(
                'DROP TRIGGER IF EXISTS portal_service_project_readiness_guard '
                .'ON public.portal_service_projects'
            );
            DB::statement('DROP FUNCTION IF EXISTS public.portal_guard_project_review_readiness()');
            DB::statement('DROP TABLE IF EXISTS public.portal_service_project_checkpoints');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_guard_service_project_checkpoint()');
            DB::statement(
                'ALTER TABLE public.portal_service_projects '
                .'DROP CONSTRAINT IF EXISTS portal_service_project_tenant_identity_uq'
            );
        });
    }

    private function requireFoundation(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_service_projects')
            || !Schema::hasTable('portal_organizations')) {
            throw new RuntimeException(
                'Project checkpoints require reviewed PostgreSQL project and tenant foundation.'
            );
        }
    }
};
