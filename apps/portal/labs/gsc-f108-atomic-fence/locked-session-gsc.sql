-- F108 — candidate, NOT production migration. Run only on disposable CI PG16.
-- The SECURITY DEFINER owner has no login, no BYPASSRLS and no role
-- inheritance. All table grants below go to this owner only, NEVER the
-- caller/runtime. A row-locking read remains active through caller COMMIT.
\set ON_ERROR_STOP on
BEGIN;
CREATE ROLE portal_gsc_f108_owner NOLOGIN NOSUPERUSER
  NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_gsc_f108_reader LOGIN
  PASSWORD 'f108_ci_synthetic_only'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE imagine_gsc_ci_test TO portal_gsc_f108_reader;
GRANT USAGE ON SCHEMA public TO portal_gsc_f108_reader,portal_gsc_f108_owner;
GRANT CREATE ON SCHEMA public TO portal_gsc_f108_owner;

-- FOR SHARE demands UPDATE privilege on at least one column of every
-- locked table. Grant only immutable key columns to the NOLOGIN definer.
-- Independent DBA review + immutable-key constraints are necessary for
-- an operational version. The execute-only reader has no table grants.
GRANT SELECT(id,status,email_verified_at,mfa_confirmed_at,security_revision),
      UPDATE(id) ON public.portal_identities TO portal_gsc_f108_owner;
GRANT SELECT(id,status,security_revision),
      UPDATE(id) ON public.portal_organizations TO portal_gsc_f108_owner;
GRANT SELECT(id,identity_id,organization_id,status,membership_revision),
      UPDATE(id) ON public.portal_memberships TO portal_gsc_f108_owner;
GRANT SELECT(session_sha256,identity_id,organization_id,status,
             session_purpose,revoked_at,issued_at,expires_at,mfa_verified_at,
             identity_revision,membership_revision,organization_revision),
      UPDATE(session_sha256) ON public.portal_auth_sessions
      TO portal_gsc_f108_owner;
GRANT SELECT(portal_organization_id,portal_identity_id,tenant_ref,actor_ref,
             organization_revision,membership_revision,issued_at,expires_at,
             link_status,revoked_at),
      UPDATE(tenant_ref) ON public.portal_gsc_identity_scope_links
      TO portal_gsc_f108_owner;
GRANT SELECT(tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
             revoked_at,verified_at,expires_at),
      UPDATE(tenant_ref) ON public.portal_gsc_property_grants
      TO portal_gsc_f108_owner;

-- Identity tables are FORCE RLS and have no otherwise-usable policies.
-- This policy applies ONLY to the named NOLOGIN definer in disposable CI.
-- Crucially, the definer's GSC tables still obey the existing tenant
-- scoped FORCE RLS policies using SET LOCAL app.tenant_ref.
CREATE POLICY portal_gsc_f108_identity_lock ON public.portal_identities
  FOR ALL TO portal_gsc_f108_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_gsc_f108_org_lock ON public.portal_organizations
  FOR ALL TO portal_gsc_f108_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_gsc_f108_membership_lock ON public.portal_memberships
  FOR ALL TO portal_gsc_f108_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_gsc_f108_session_lock ON public.portal_auth_sessions
  FOR ALL TO portal_gsc_f108_owner USING(true) WITH CHECK(true);

-- This is NOT a Google or customer-access authorization function. It
-- proves row-level locking across first-party session/membership/org and
-- recorded GSC link/grant within one transaction. It returns a candidate
-- review shape (not a credential, issuer or verified business permission).
CREATE FUNCTION public.portal_gsc_f108_locked_observation(
  p_digest text,
  p_expected_org uuid,
  p_tenant text,
  p_fingerprint text,
  p_now bigint
) RETURNS TABLE(
  portal_identity_id uuid,
  portal_organization_id uuid,
  portal_issued_at bigint,
  portal_expires_at bigint,
  organization_revision bigint,
  membership_revision bigint,
  recorded_grant_fresh boolean
)
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp
SET row_security=on
AS $f108$
  SELECT i.id,o.id,
    floor(extract(epoch FROM s.issued_at))::bigint,
    floor(extract(epoch FROM s.expires_at))::bigint,
    o.security_revision::bigint,
    m.membership_revision::bigint,
    true
  FROM public.portal_auth_sessions AS s
  JOIN public.portal_identities AS i
    ON i.id=s.identity_id
  JOIN public.portal_memberships AS m
    ON m.identity_id=i.id AND m.organization_id=s.organization_id
  JOIN public.portal_organizations AS o
    ON o.id=s.organization_id
  JOIN public.portal_gsc_identity_scope_links AS l
    ON l.portal_identity_id=i.id AND l.portal_organization_id=o.id
  JOIN public.portal_gsc_property_grants AS g
    ON g.tenant_ref=l.tenant_ref AND g.actor_ref=l.actor_ref
  WHERE p_digest ~ '^[a-f0-9]{64}$'
    AND p_tenant ~ '^[a-z0-9][a-z0-9_-]{2,63}$'
    AND p_fingerprint ~ '^[a-f0-9]{64}$'
    AND p_now BETWEEN 1600000000 AND 2200000000
    AND s.session_sha256=p_digest
    AND s.organization_id=p_expected_org
    AND s.status='active' AND s.session_purpose='business'
    AND s.revoked_at IS NULL
    AND s.issued_at<=clock_timestamp()
    AND s.expires_at>clock_timestamp()
    AND s.expires_at<=s.issued_at+interval '2 hours'
    AND s.mfa_verified_at IS NOT NULL
    AND s.mfa_verified_at>=s.issued_at
    AND s.mfa_verified_at<=clock_timestamp()
    AND i.status='active'
    AND i.email_verified_at IS NOT NULL
    AND i.email_verified_at<=clock_timestamp()
    AND i.mfa_confirmed_at IS NOT NULL
    AND i.mfa_confirmed_at<=clock_timestamp()
    AND m.status='active' AND o.status='active'
    AND s.identity_revision=i.security_revision
    AND s.membership_revision=m.membership_revision
    AND s.organization_revision=o.security_revision
    AND l.tenant_ref=p_tenant
    AND l.organization_revision=o.security_revision
    AND l.membership_revision=m.membership_revision
    AND l.link_status='active' AND l.revoked_at IS NULL
    AND l.issued_at<=p_now AND l.expires_at>p_now
    AND g.property_fingerprint=p_fingerprint
    AND g.scope='https://www.googleapis.com/auth/webmasters.readonly'
    AND g.grant_status='active' AND g.revoked_at IS NULL
    AND g.verified_at<=p_now AND g.verified_at>=p_now-900
    AND g.expires_at>p_now
  FOR SHARE OF s,i,m,o,l,g;
$f108$;

ALTER FUNCTION public.portal_gsc_f108_locked_observation(
 text,uuid,text,text,bigint
) OWNER TO portal_gsc_f108_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_f108_locked_observation(
 text,uuid,text,text,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f108_locked_observation(
 text,uuid,text,text,bigint
) TO portal_gsc_f108_reader;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f108_owner;
COMMIT;
