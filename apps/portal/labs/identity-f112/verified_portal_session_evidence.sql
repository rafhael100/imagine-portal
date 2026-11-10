-- PORTAL-F112: disposable PostgreSQL 18 proof, NO CUSTOMER DATABASE.
-- A *first-party Portal session row* rather than a caller-constructed DTO
-- supplies identity/tenant/MFA evidence to the synthetic F110 preflight.
-- The session hash is bearer-equivalent. Never expose it to HTTP, logs, CRM,
-- analytics or external RPC. This is NOT an operational issuer or login.
BEGIN;

CREATE ROLE portal_f112_owner
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_f112_reader
  LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS
  PASSWORD 'ci-f112-reader-only';
GRANT CONNECT ON DATABASE portal_identity_lab TO portal_f112_reader;
GRANT USAGE ON SCHEMA public TO portal_f112_reader;
GRANT USAGE, CREATE ON SCHEMA public TO portal_f112_owner;

GRANT SELECT (id,status,email_verified_at,mfa_confirmed_at,security_revision)
  ON public.portal_identities TO portal_f112_owner;
GRANT SELECT (id,status,security_revision)
  ON public.portal_organizations TO portal_f112_owner;
GRANT SELECT (identity_id,organization_id,status,membership_revision)
  ON public.portal_memberships TO portal_f112_owner;
GRANT SELECT (
  session_sha256,identity_id,organization_id,status,revoked_at,issued_at,
  expires_at,mfa_verified_at,identity_revision,membership_revision,
  organization_revision
) ON public.portal_auth_sessions TO portal_f112_owner;

-- These policies target ONLY the dedicated NOLOGIN function owner, and
-- remain supplemental to F66's FORCE RLS. They are NOT production tenant RLS.
CREATE POLICY portal_f112_identity_read
  ON public.portal_identities FOR SELECT
  TO portal_f112_owner USING (true);
CREATE POLICY portal_f112_organization_read
  ON public.portal_organizations FOR SELECT
  TO portal_f112_owner USING (true);
CREATE POLICY portal_f112_membership_read
  ON public.portal_memberships FOR SELECT
  TO portal_f112_owner USING (true);
CREATE POLICY portal_f112_session_read
  ON public.portal_auth_sessions FOR SELECT
  TO portal_f112_owner USING (true);

CREATE FUNCTION public.portal_f112_verified_session_evidence(
  p_digest text, p_organization uuid
)
RETURNS TABLE (
  actor_id uuid,
  organization_id uuid,
  session_issued_at bigint,
  session_expires_at bigint,
  email_verified_at bigint,
  mfa_verified_at bigint,
  identity_revision bigint,
  session_identity_revision bigint,
  membership_revision bigint,
  session_membership_revision bigint,
  organization_revision bigint,
  session_organization_revision bigint
)
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f112$
  SELECT
    i.id,
    o.id,
    floor(extract(epoch FROM s.issued_at))::bigint,
    floor(extract(epoch FROM s.expires_at))::bigint,
    floor(extract(epoch FROM i.email_verified_at))::bigint,
    floor(extract(epoch FROM s.mfa_verified_at))::bigint,
    i.security_revision::bigint,
    s.identity_revision::bigint,
    m.membership_revision::bigint,
    s.membership_revision::bigint,
    o.security_revision::bigint,
    s.organization_revision::bigint
  FROM public.portal_auth_sessions AS s
  JOIN public.portal_identities AS i ON i.id=s.identity_id
  JOIN public.portal_organizations AS o ON o.id=s.organization_id
  JOIN public.portal_memberships AS m
    ON m.identity_id=s.identity_id AND m.organization_id=s.organization_id
  WHERE p_digest ~ '^[a-f0-9]{64}$'
    AND s.session_sha256=p_digest
    AND s.organization_id=p_organization
    AND s.status='active'
    AND s.revoked_at IS NULL
    AND s.issued_at<=clock_timestamp()
    AND s.expires_at>clock_timestamp()
    AND s.expires_at<=s.issued_at+interval '1 day'
    AND s.mfa_verified_at IS NOT NULL
    AND s.mfa_verified_at>=s.issued_at
    AND s.mfa_verified_at<=clock_timestamp()
    AND s.mfa_verified_at>=clock_timestamp()-interval '5 minutes'
    AND i.status='active'
    AND i.email_verified_at IS NOT NULL
    AND i.email_verified_at<=clock_timestamp()
    AND i.mfa_confirmed_at IS NOT NULL
    AND i.mfa_confirmed_at<=clock_timestamp()
    AND o.status='active'
    AND m.status='invited'
    AND s.organization_revision IS NOT NULL
    AND s.identity_revision=i.security_revision
    AND s.membership_revision=m.membership_revision
    AND s.organization_revision=o.security_revision
  LIMIT 1;
$f112$;

ALTER FUNCTION public.portal_f112_verified_session_evidence(text,uuid)
  OWNER TO portal_f112_owner;
REVOKE ALL ON FUNCTION public.portal_f112_verified_session_evidence(text,uuid)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f112_verified_session_evidence(text,uuid)
  TO portal_f112_reader;
REVOKE CREATE ON SCHEMA public FROM portal_f112_owner;
COMMIT;
