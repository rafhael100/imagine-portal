-- GSC-F109: disposable PostgreSQL 18 laboratory ONLY.
-- Demonstrate that a caller with EXECUTE permission cannot issue OAuth
-- state without an explicit, MFA-marked, nonrevoked tenant/property grant.
-- These are SYNTHETIC grants, NOT verified Portal sessions.
BEGIN;
GRANT CREATE ON SCHEMA public TO portal_gsc_f106_owner;
SET ROLE portal_gsc_f106_owner;

CREATE TABLE public.portal_gsc_f109_grants (
  session_digest text PRIMARY KEY CHECK (session_digest ~ '^[a-f0-9]{64}$'),
  actor_id uuid NOT NULL,
  organization_id uuid NOT NULL,
  property_fingerprint text NOT NULL
    CHECK (property_fingerprint ~ '^[a-f0-9]{64}$'),
  permission_code text NOT NULL CHECK (permission_code = 'seo.gsc.connect'),
  mfa_confirmed boolean NOT NULL DEFAULT false,
  organization_revision bigint NOT NULL CHECK (organization_revision > 0),
  issued_at bigint NOT NULL CHECK (issued_at > 0),
  expires_at bigint NOT NULL CHECK (expires_at > issued_at),
  revoked_at bigint
);
ALTER TABLE public.portal_gsc_f109_grants ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_gsc_f109_grants FORCE ROW LEVEL SECURITY;
CREATE POLICY portal_gsc_f109_issuer_owner_only
  ON public.portal_gsc_f109_grants
  FOR ALL TO portal_gsc_f106_owner
  USING (true) WITH CHECK (true);
REVOKE ALL ON TABLE public.portal_gsc_f109_grants FROM PUBLIC;

CREATE FUNCTION public.portal_gsc_f109_authorized_issue(
  p_session_digest text, p_state_digest text, p_actor uuid,
  p_organization uuid, p_property_fingerprint text,
  p_issued_at bigint, p_expires_at bigint,
  p_nonce text, p_ciphertext text
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $f109$
BEGIN
  IF p_session_digest IS NULL OR p_session_digest !~ '^[a-f0-9]{64}$'
    OR p_state_digest IS NULL OR p_state_digest !~ '^[a-f0-9]{64}$'
    OR p_actor IS NULL OR p_organization IS NULL
    OR p_property_fingerprint IS NULL
    OR p_property_fingerprint !~ '^[a-f0-9]{64}$'
    OR p_issued_at IS NULL OR p_issued_at <= 0
    OR p_expires_at IS NULL OR p_expires_at <> p_issued_at + 300
  THEN
    RETURN false;
  END IF;

  -- The identity, MFA, membership and consent are deliberately LAB fixtures.
  -- A real system must replace these fields with first-party IAM authority.
  IF NOT EXISTS (
    SELECT 1 FROM public.portal_gsc_f109_grants AS grant_row
    WHERE grant_row.session_digest = p_session_digest
      AND grant_row.actor_id = p_actor
      AND grant_row.organization_id = p_organization
      AND grant_row.property_fingerprint = p_property_fingerprint
      AND grant_row.permission_code = 'seo.gsc.connect'
      AND grant_row.mfa_confirmed IS TRUE
      AND grant_row.organization_revision > 0
      AND grant_row.revoked_at IS NULL
      AND grant_row.issued_at <= p_issued_at
      AND grant_row.expires_at > p_issued_at
  ) THEN
    RETURN false;
  END IF;

  -- Same security owner can invoke the underlying F108 issuer.
  -- The synthetic client cannot invoke F108 directly.
  RETURN public.portal_gsc_f108_issue(
    p_state_digest, p_actor, p_organization,
    p_property_fingerprint, p_issued_at, p_expires_at,
    p_nonce, p_ciphertext
  );
END;
$f109$;

REVOKE ALL ON FUNCTION public.portal_gsc_f109_authorized_issue(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f109_authorized_issue(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) TO portal_gsc_f106_client;

RESET ROLE;
-- This is essential: a direct F108/F106 issuer would bypass the permission
-- fixture even if the F109 wrapper had perfect grant validation.
REVOKE EXECUTE ON FUNCTION public.portal_gsc_f108_issue(
  text,uuid,uuid,text,bigint,bigint,text,text
) FROM portal_gsc_f106_client;
REVOKE EXECUTE ON FUNCTION public.portal_gsc_f106_issue(
  text,uuid,uuid,text,bigint,bigint
) FROM portal_gsc_f106_client;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f106_owner;
COMMIT;
