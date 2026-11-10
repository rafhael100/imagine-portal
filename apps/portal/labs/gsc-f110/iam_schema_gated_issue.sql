-- GSC-F110 — disposable PG18 test ONLY, NEVER production migration.
-- Integration probe of actual Portal F66/F76 identity table schema and
-- explicit seo.gsc.connect grant before F108 encrypted state issuance.
-- No trusted identity issuer, HTTP, Google API or token exchange.
BEGIN;

-- The operating identity schema currently blocks SEO grant values. Extend
-- ONLY the disposable database constraint for this proof, not migrations.
ALTER TABLE public.portal_membership_grants
  DROP CONSTRAINT portal_grant_permission_ck;
ALTER TABLE public.portal_membership_grants
  ADD CONSTRAINT portal_grant_permission_ck CHECK (
    permission_code IN (
      'services.view', 'services.manage', 'billing.view', 'members.manage',
      'seo.gsc.connect'
    )
  );

CREATE ROLE portal_gsc_f110_owner
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE, CREATE ON SCHEMA public TO portal_gsc_f110_owner;

-- Column-level minimum SELECT, with *test-only* RLS policies. Real
-- production RLS must remain deny-all pending independent DBA review.
GRANT SELECT (
  id,status,email_verified_at,mfa_confirmed_at,security_revision
) ON public.portal_identities TO portal_gsc_f110_owner;
GRANT SELECT (
  id,status,security_revision
) ON public.portal_organizations TO portal_gsc_f110_owner;
GRANT SELECT (
  id,identity_id,organization_id,status,membership_revision
) ON public.portal_memberships TO portal_gsc_f110_owner;
GRANT SELECT (
  membership_id,permission_code
) ON public.portal_membership_grants TO portal_gsc_f110_owner;
GRANT SELECT (
  session_sha256,identity_id,organization_id,status,revoked_at,
  issued_at,expires_at,mfa_verified_at,identity_revision,
  membership_revision,organization_revision
) ON public.portal_auth_sessions TO portal_gsc_f110_owner;

CREATE POLICY gsc_f110_identity_read ON public.portal_identities
  FOR SELECT TO portal_gsc_f110_owner USING (true);
CREATE POLICY gsc_f110_org_read ON public.portal_organizations
  FOR SELECT TO portal_gsc_f110_owner USING (true);
CREATE POLICY gsc_f110_membership_read ON public.portal_memberships
  FOR SELECT TO portal_gsc_f110_owner USING (true);
CREATE POLICY gsc_f110_grant_read ON public.portal_membership_grants
  FOR SELECT TO portal_gsc_f110_owner USING (true);
CREATE POLICY gsc_f110_session_read ON public.portal_auth_sessions
  FOR SELECT TO portal_gsc_f110_owner USING (true);

-- F108 definer has its own limited owner. Its EXECUTE here does not grant
-- access to anyone else and keeps the final insert atomic.
GRANT EXECUTE ON FUNCTION public.portal_gsc_f108_issue(
  text,uuid,uuid,text,bigint,bigint,text,text
) TO portal_gsc_f110_owner;

SET ROLE portal_gsc_f110_owner;

-- Explicit per-organization candidate property, not proof of Google ownership.
CREATE TABLE public.portal_gsc_f110_allowed_properties (
  organization_id uuid NOT NULL,
  property_fingerprint text NOT NULL
    CHECK (property_fingerprint ~ '^[a-f0-9]{64}$'),
  active boolean NOT NULL DEFAULT false,
  PRIMARY KEY (organization_id, property_fingerprint)
);
ALTER TABLE public.portal_gsc_f110_allowed_properties ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_gsc_f110_allowed_properties FORCE ROW LEVEL SECURITY;
CREATE POLICY gsc_f110_property_owner_only
  ON public.portal_gsc_f110_allowed_properties
  FOR ALL TO portal_gsc_f110_owner USING (true) WITH CHECK (true);
REVOKE ALL ON TABLE public.portal_gsc_f110_allowed_properties FROM PUBLIC;

CREATE FUNCTION public.portal_gsc_f110_iam_issued_state(
  p_session_digest text, p_state_digest text, p_actor uuid,
  p_organization uuid, p_property_fingerprint text,
  p_issued_at bigint, p_expires_at bigint,
  p_nonce text, p_ciphertext text
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f110$
DECLARE
  db_now bigint := floor(extract(epoch FROM clock_timestamp()))::bigint;
BEGIN
  IF p_session_digest IS NULL OR p_session_digest !~ '^[a-f0-9]{64}$'
    OR p_state_digest IS NULL OR p_state_digest !~ '^[a-f0-9]{64}$'
    OR p_actor IS NULL OR p_organization IS NULL
    OR p_property_fingerprint IS NULL
    OR p_property_fingerprint !~ '^[a-f0-9]{64}$'
    OR p_issued_at IS NULL OR p_expires_at IS NULL
    OR p_expires_at <> p_issued_at + 300
    OR p_issued_at < db_now - 30 OR p_issued_at > db_now + 30
  THEN
    RETURN false;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM public.portal_gsc_f110_allowed_properties AS allowed
    WHERE allowed.organization_id = p_organization
      AND allowed.property_fingerprint = p_property_fingerprint
      AND allowed.active IS TRUE
  ) THEN
    RETURN false;
  END IF;

  IF NOT EXISTS (
    SELECT 1
    FROM public.portal_auth_sessions AS s
    JOIN public.portal_identities AS i ON i.id = s.identity_id
    JOIN public.portal_organizations AS o ON o.id = s.organization_id
    JOIN public.portal_memberships AS m
      ON m.identity_id = s.identity_id
      AND m.organization_id = s.organization_id
    JOIN public.portal_membership_grants AS g ON g.membership_id = m.id
    WHERE s.session_sha256 = p_session_digest
      AND s.identity_id = p_actor
      AND s.organization_id = p_organization
      AND s.status = 'active'
      AND s.revoked_at IS NULL
      AND s.issued_at <= clock_timestamp()
      AND s.expires_at > clock_timestamp()
      AND s.expires_at <= s.issued_at + interval '1 day'
      AND s.mfa_verified_at IS NOT NULL
      AND s.mfa_verified_at <= clock_timestamp()
      AND i.status = 'active'
      AND i.email_verified_at IS NOT NULL
      AND i.mfa_confirmed_at IS NOT NULL
      AND i.security_revision = s.identity_revision
      AND o.status = 'active'
      AND s.organization_revision IS NOT NULL
      AND s.organization_revision = o.security_revision
      AND m.status = 'active'
      AND m.membership_revision = s.membership_revision
      AND g.permission_code = 'seo.gsc.connect'
  ) THEN
    RETURN false;
  END IF;

  RETURN public.portal_gsc_f108_issue(
    p_state_digest,p_actor,p_organization,p_property_fingerprint,
    p_issued_at,p_expires_at,p_nonce,p_ciphertext
  );
END;
$f110$;

REVOKE ALL ON FUNCTION public.portal_gsc_f110_iam_issued_state(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f110_iam_issued_state(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) TO portal_gsc_f106_client;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f110_owner;

-- Remove F109's synthetic grant issuer: the reader cannot bypass F110
-- by inventing a fixture grant. F106/F108 emitters were already revoked.
REVOKE EXECUTE ON FUNCTION public.portal_gsc_f109_authorized_issue(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) FROM portal_gsc_f106_client;
COMMIT;
