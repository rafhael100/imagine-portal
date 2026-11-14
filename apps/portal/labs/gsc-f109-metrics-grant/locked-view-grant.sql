-- F109: disposable PostgreSQL ONLY. Independent SEO/GSC metric-view
-- membership grant, distinctly different from seo.gsc.connect.
-- This is NOT a production migration or an enabled permission in Portal.
\set ON_ERROR_STOP on
BEGIN;
CREATE TABLE public.portal_membership_grants (
  id uuid PRIMARY KEY,
  membership_id uuid NOT NULL,
  permission_code text NOT NULL
    CHECK (permission_code IN ('seo.gsc.connect','seo.gsc.view')),
  UNIQUE(membership_id,permission_code)
);
ALTER TABLE public.portal_membership_grants ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_membership_grants FORCE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.portal_membership_grants FROM PUBLIC;

CREATE ROLE portal_gsc_f109_owner NOLOGIN NOSUPERUSER
 NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE,CREATE ON SCHEMA public TO portal_gsc_f109_owner;
GRANT SELECT(id,identity_id,organization_id,status,membership_revision),
      UPDATE(id) ON public.portal_memberships TO portal_gsc_f109_owner;
GRANT SELECT(id,membership_id,permission_code),
      UPDATE(id) ON public.portal_membership_grants TO portal_gsc_f109_owner;
CREATE POLICY portal_gsc_f109_member_lock ON public.portal_memberships
 FOR ALL TO portal_gsc_f109_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_gsc_f109_explicit_read_lock
 ON public.portal_membership_grants
 FOR ALL TO portal_gsc_f109_owner USING(true) WITH CHECK(true);

-- F108 is already a strict row-locking observation, and its locks remain
-- held until the caller's transaction ends. The new owner has EXECUTE
-- only; it cannot read F108's source tables.
GRANT EXECUTE ON FUNCTION public.portal_gsc_f108_locked_observation(
 text,uuid,text,text,bigint
) TO portal_gsc_f109_owner;

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
AS $f109$
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
$f109$;
ALTER FUNCTION public.portal_gsc_f109_metric_read_candidate(
 text,uuid,text,text,bigint
) OWNER TO portal_gsc_f109_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_f109_metric_read_candidate(
 text,uuid,text,text,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f109_metric_read_candidate(
 text,uuid,text,text,bigint
) TO portal_gsc_f108_reader;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f109_owner;
COMMIT;
