<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review-only requests ledger for tenant-bound service projects.
 * No runtime grants, customer routes, real actors, mail, or approvals.
 *
 * Business state transitions mirror ServiceRequestWorkflowPreview, but
 * a state transition NEVER provides HTTP permission or an authenticated actor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || !Schema::hasTable('portal_service_projects')
            || !Schema::hasTable('portal_service_project_checkpoints')) {
            throw new RuntimeException('Service request candidates require F11/F12 PG18 foundation.');
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE public.portal_service_requests (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL REFERENCES public.portal_organizations(id) ON DELETE RESTRICT,
    project_id uuid NOT NULL,
    request_ref varchar(128) NOT NULL,
    status varchar(24) NOT NULL DEFAULT 'draft',
    request_revision bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT portal_service_request_ref_ck CHECK (
        request_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
    ),
    CONSTRAINT portal_service_request_status_ck CHECK (
        status IN ('draft','submitted','in_review','waiting_on_customer','resolved','closed')
    ),
    CONSTRAINT portal_service_request_revision_ck CHECK (
        request_revision BETWEEN 1 AND 1000000000
    ),
    CONSTRAINT portal_service_request_tenant_ref_uq UNIQUE(organization_id,request_ref),
    CONSTRAINT portal_service_request_project_fk FOREIGN KEY(project_id,organization_id)
        REFERENCES public.portal_service_projects(id,organization_id) ON DELETE RESTRICT
);
CREATE INDEX portal_service_request_org_project_idx
    ON public.portal_service_requests(organization_id,project_id,status);

CREATE TABLE public.portal_service_request_transitions (
    event_id uuid PRIMARY KEY,
    request_id uuid NOT NULL REFERENCES public.portal_service_requests(id) ON DELETE RESTRICT,
    organization_id uuid NOT NULL REFERENCES public.portal_organizations(id) ON DELETE RESTRICT,
    from_status varchar(24),
    to_status varchar(24) NOT NULL,
    request_revision bigint NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT portal_service_request_event_revision_uq UNIQUE(request_id,request_revision),
    CONSTRAINT portal_service_request_event_revision_ck CHECK (
        request_revision BETWEEN 1 AND 1000000000
    ),
    CONSTRAINT portal_service_request_event_status_ck CHECK (
        to_status IN ('draft','submitted','in_review','waiting_on_customer','resolved','closed')
        AND (from_status IS NULL OR from_status IN (
            'draft','submitted','in_review','waiting_on_customer','resolved','closed'
        ))
    )
);
CREATE INDEX portal_service_request_transition_tenant_idx
    ON public.portal_service_request_transitions(organization_id,request_id,request_revision);

CREATE FUNCTION public.portal_guard_service_request_candidate()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $portal_request_guard$
DECLARE v_project_state text; v_org_state text;
BEGIN
    IF TG_OP='DELETE' THEN
        RAISE EXCEPTION 'Portal service request history cannot be deleted'
            USING ERRCODE='42501';
    END IF;

    SELECT p.state,o.status INTO v_project_state,v_org_state
      FROM public.portal_service_projects p
      JOIN public.portal_organizations o ON o.id=p.organization_id
     WHERE p.id=NEW.project_id AND p.organization_id=NEW.organization_id
     FOR SHARE OF p,o;
    IF v_org_state IS DISTINCT FROM 'active'
       OR v_project_state NOT IN ('draft','review') OR v_project_state IS NULL THEN
        RAISE EXCEPTION 'Portal service request requires an available tenant project'
            USING ERRCODE='42501';
    END IF;

    IF TG_OP='INSERT' THEN
        IF NEW.status<>'draft' OR NEW.request_revision<>1 THEN
            RAISE EXCEPTION 'Portal service request must begin as draft revision 1'
                USING ERRCODE='42501';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id
       OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
       OR NEW.project_id IS DISTINCT FROM OLD.project_id
       OR NEW.request_ref IS DISTINCT FROM OLD.request_ref
       OR NEW.created_at IS DISTINCT FROM OLD.created_at
       OR NEW.request_revision IS DISTINCT FROM OLD.request_revision
       OR NEW.updated_at IS DISTINCT FROM OLD.updated_at THEN
        RAISE EXCEPTION 'Portal service request scope and revision are immutable'
            USING ERRCODE='42501';
    END IF;

    IF NOT (
        (OLD.status='draft' AND NEW.status='submitted')
        OR (OLD.status='submitted' AND NEW.status='in_review')
        OR (OLD.status='in_review' AND NEW.status IN ('waiting_on_customer','resolved'))
        OR (OLD.status='waiting_on_customer' AND NEW.status='in_review')
        OR (OLD.status='resolved' AND NEW.status IN ('in_review','closed'))
    ) THEN
        RAISE EXCEPTION 'Portal service request transition is invalid'
            USING ERRCODE='42501';
    END IF;
    IF OLD.request_revision>=1000000000 THEN
        RAISE EXCEPTION 'Portal service request revision exhausted'
            USING ERRCODE='22003';
    END IF;
    NEW.request_revision:=OLD.request_revision+1;
    NEW.updated_at:=clock_timestamp();
    RETURN NEW;
END;
$portal_request_guard$;

CREATE FUNCTION public.portal_guard_service_request_event()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $portal_request_event_guard$
BEGIN
    IF TG_OP='INSERT' AND pg_trigger_depth()>=2 THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Portal request transition history is only appendable by request state trigger'
        USING ERRCODE='42501';
END;
$portal_request_event_guard$;

CREATE TRIGGER portal_service_request_event_guard
BEFORE INSERT OR UPDATE OR DELETE ON public.portal_service_request_transitions
FOR EACH ROW EXECUTE FUNCTION public.portal_guard_service_request_event();

CREATE FUNCTION public.portal_record_service_request_transition()
RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $portal_request_record$
BEGIN
    INSERT INTO public.portal_service_request_transitions(
        event_id,request_id,organization_id,from_status,to_status,request_revision
    ) VALUES (
        gen_random_uuid(),NEW.id,NEW.organization_id,
        CASE WHEN TG_OP='INSERT' THEN NULL ELSE OLD.status END,
        NEW.status,NEW.request_revision
    );
    RETURN NEW;
END;
$portal_request_record$;

REVOKE ALL ON FUNCTION public.portal_guard_service_request_candidate() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_guard_service_request_event() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_record_service_request_transition() FROM PUBLIC;
CREATE TRIGGER portal_service_request_guard
BEFORE INSERT OR UPDATE OR DELETE ON public.portal_service_requests
FOR EACH ROW EXECUTE FUNCTION public.portal_guard_service_request_candidate();
CREATE TRIGGER portal_service_request_audit
AFTER INSERT OR UPDATE ON public.portal_service_requests
FOR EACH ROW EXECUTE FUNCTION public.portal_record_service_request_transition();

ALTER TABLE public.portal_service_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_service_requests FORCE ROW LEVEL SECURITY;
ALTER TABLE public.portal_service_request_transitions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_service_request_transitions FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_service_requests FROM PUBLIC;
REVOKE ALL ON public.portal_service_request_transitions FROM PUBLIC;
-- No allow policies. No runtime grants. No operational API.
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Portal requests are PostgreSQL only.');
        }
        DB::transaction(function (): void {
            DB::statement('SET LOCAL row_security=off');
            if ((Schema::hasTable('portal_service_requests')
                 && DB::table('portal_service_requests')->exists())
                || (Schema::hasTable('portal_service_request_transitions')
                    && DB::table('portal_service_request_transitions')->exists())) {
                throw new RuntimeException(
                    'Portal service request rollback denied: transition history requires audited retention.'
                );
            }
            DB::statement('DROP TABLE IF EXISTS public.portal_service_request_transitions');
            DB::statement('DROP TABLE IF EXISTS public.portal_service_requests');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_record_service_request_transition()');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_guard_service_request_event()');
            DB::statement('DROP FUNCTION IF EXISTS public.portal_guard_service_request_candidate()');
        });
    }
};
