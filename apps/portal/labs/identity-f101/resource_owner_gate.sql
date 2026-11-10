-- PORTAL-F101: DISPOSABLE POSTGRESQL 18 CI LAB ONLY.
-- Neither this table nor this SECURITY DEFINER function belongs in migrations,
-- customer databases, app providers or public HTTP/RPC endpoints.
--
-- The digest argument is bearer-equivalent and must never be logged, exposed
-- to a browser, or provided by untrusted partner/CRM systems.
--
-- The F76 grant is necessary but not sufficient: the resource must also
-- belong to the selected Portal organization and be active.
BEGIN;

CREATE TABLE public.portal_f101_resource_fixtures (
    id uuid PRIMARY KEY,
    organization_id uuid NOT NULL REFERENCES public.portal_organizations(id),
    status text NOT NULL CHECK (status IN ('active', 'archived')),
    display_ref text NOT NULL CHECK (length(display_ref) BETWEEN 1 AND 48),
    UNIQUE (organization_id, display_ref)
);
ALTER TABLE public.portal_f101_resource_fixtures ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_f101_resource_fixtures FORCE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.portal_f101_resource_fixtures FROM PUBLIC;

CREATE FUNCTION public.portal_f101_resource_check(
    p_session_hash text,
    p_organization_id uuid,
    p_resource_id uuid,
    p_permission text
) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $f101$
    SELECT CASE WHEN
        p_session_hash ~ '^[a-f0-9]{64}$'
        AND p_permission IN ('services.view', 'services.manage')
        AND public.portal_f76_permission_check(
            p_session_hash, p_organization_id, p_permission
        )
    THEN EXISTS (
        SELECT 1
        FROM public.portal_f101_resource_fixtures AS resource
        WHERE resource.id = p_resource_id
          AND resource.organization_id = p_organization_id
          AND resource.status = 'active'
    ) ELSE FALSE END;
$f101$;

-- Revoke default public function EXECUTE and then grant ONLY to the disposable
-- unprivileged role, in the same transaction as CREATE.
REVOKE ALL ON FUNCTION public.portal_f101_resource_check(text,uuid,uuid,text)
    FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f101_resource_check(text,uuid,uuid,text)
    TO portal_f101_reader;

COMMIT;
