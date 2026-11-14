-- F112 — disposable real Portal identity schema + pinned GSC F108 reviewer.
-- The existing Portal membership_grants table is reused. It must NOT be
-- replaced by the synthetic F109 table fixture. No migration/HTTP/deploy.
\set ON_ERROR_STOP on
BEGIN;
CREATE ROLE portal_gsc_f112_owner NOLOGIN NOSUPERUSER
 NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE,CREATE ON SCHEMA public TO portal_gsc_f112_owner;
GRANT SELECT(id,identity_id,organization_id,status,membership_revision),
      UPDATE(id) ON public.portal_memberships TO portal_gsc_f112_owner;
GRANT SELECT(id,membership_id,permission_code),
      UPDATE(id) ON public.portal_membership_grants TO portal_gsc_f112_owner;
CREATE POLICY portal_gsc_f112_member_lock ON public.portal_memberships
 FOR ALL TO portal_gsc_f112_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_gsc_f112_view_lock ON public.portal_membership_grants
 FOR ALL TO portal_gsc_f112_owner USING(true) WITH CHECK(true);

-- The GSC F108 function has a separate NOLOGIN owner and performs
-- row-locking read under its own RLS grants. The F112 owner only EXECUTEs
-- the existing function; it has no access to the underlying GSC tables.
GRANT EXECUTE ON FUNCTION public.portal_gsc_f108_locked_observation(
 text,uuid,text,text,bigint
) TO portal_gsc_f112_owner;

-- Identical read contract to the pinned F109 PHP caller, but specifically
-- bound to the REAL Portal identity membership table and its RLS, not a
-- laboratory replacement table.
CREATE FUNCTION public.portal_gsc_f109_metric_read_candidate(
 p_digest text,p_expected_org uuid,p_tenant text,p_fingerprint text,
 p_now bigint
) RETURNS TABLE(
 portal_organization_id uuid,
 portal_issued_at bigint,
 portal_expires_at bigint,
 recorded_metrics_view_grant boolean
)
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $f112$
 SELECT proof.portal_organization_id,
        proof.portal_issued_at,proof.portal_expires_at,true
 FROM public.portal_gsc_f108_locked_observation(
    p_digest,p_expected_org,p_tenant,p_fingerprint,p_now
 ) AS proof
 JOIN public.portal_memberships AS m
   ON m.identity_id=proof.portal_identity_id
  AND m.organization_id=proof.portal_organization_id
 JOIN public.portal_membership_grants AS mg
   ON mg.membership_id=m.id
  AND mg.permission_code='seo.gsc.view'
 WHERE m.status='active'
   AND m.membership_revision=proof.membership_revision
 FOR SHARE OF m,mg;
$f112$;
ALTER FUNCTION public.portal_gsc_f109_metric_read_candidate(
 text,uuid,text,text,bigint
) OWNER TO portal_gsc_f112_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_f109_metric_read_candidate(
 text,uuid,text,text,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f109_metric_read_candidate(
 text,uuid,text,text,bigint
) TO portal_gsc_f108_reader;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f112_owner;
COMMIT;
