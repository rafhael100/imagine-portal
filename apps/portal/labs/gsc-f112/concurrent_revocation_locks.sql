-- GSC-F112 — PostgreSQL 18 disposable concurrency laboratory ONLY.
-- Acquire row-level FOR SHARE locks on every authority witness before OAuth
-- issue or release of encrypted PKCE bytes, to serialize revocation writers.
-- Never apply these GRANTs or RLS policies to production migrations.
BEGIN;

-- PostgreSQL requires UPDATE on at least one column for SELECT FOR SHARE.
-- Provide a narrowly confined column privilege to the NOLOGIN test owner.
-- Application/reader roles receive zero new UPDATE privileges.
GRANT UPDATE(updated_at) ON public.portal_auth_sessions TO portal_gsc_f110_owner;
GRANT UPDATE(updated_at) ON public.portal_identities TO portal_gsc_f110_owner;
GRANT UPDATE(updated_at) ON public.portal_organizations TO portal_gsc_f110_owner;
GRANT UPDATE(updated_at) ON public.portal_memberships TO portal_gsc_f110_owner;
GRANT UPDATE(updated_at) ON public.portal_membership_grants TO portal_gsc_f110_owner;

CREATE POLICY gsc_f112_session_lock
  ON public.portal_auth_sessions FOR UPDATE
  TO portal_gsc_f110_owner USING (true) WITH CHECK (true);
CREATE POLICY gsc_f112_identity_lock
  ON public.portal_identities FOR UPDATE
  TO portal_gsc_f110_owner USING (true) WITH CHECK (true);
CREATE POLICY gsc_f112_org_lock
  ON public.portal_organizations FOR UPDATE
  TO portal_gsc_f110_owner USING (true) WITH CHECK (true);
CREATE POLICY gsc_f112_membership_lock
  ON public.portal_memberships FOR UPDATE
  TO portal_gsc_f110_owner USING (true) WITH CHECK (true);
CREATE POLICY gsc_f112_grant_lock
  ON public.portal_membership_grants FOR UPDATE
  TO portal_gsc_f110_owner USING (true) WITH CHECK (true);

GRANT CREATE ON SCHEMA public TO portal_gsc_f110_owner;
SET ROLE portal_gsc_f110_owner;

CREATE FUNCTION public.portal_gsc_f112_lock_recheck(
  p_session_digest text, p_actor uuid, p_organization uuid,
  p_property_fingerprint text
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f112_lock$
BEGIN
  IF p_session_digest IS NULL OR p_session_digest !~ '^[a-f0-9]{64}$'
    OR p_actor IS NULL OR p_organization IS NULL
    OR p_property_fingerprint IS NULL
    OR p_property_fingerprint !~ '^[a-f0-9]{64}$'
  THEN
    RETURN false;
  END IF;

  -- SHARE locks conflict with UPDATE/DELETE of these authority rows.
  -- A concurrent revocation either commits before this check (then denies)
  -- or waits until this transaction finishes issuing/returning ciphertext.
  PERFORM 1
  FROM public.portal_auth_sessions AS s
  JOIN public.portal_identities AS i ON i.id = s.identity_id
  JOIN public.portal_organizations AS o ON o.id = s.organization_id
  JOIN public.portal_memberships AS m
    ON m.identity_id = s.identity_id
    AND m.organization_id = s.organization_id
  JOIN public.portal_membership_grants AS g ON g.membership_id = m.id
  JOIN public.portal_gsc_f110_allowed_properties AS property
    ON property.organization_id = s.organization_id
    AND property.property_fingerprint = p_property_fingerprint
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
    AND o.security_revision = s.organization_revision
    AND m.status = 'active'
    AND m.membership_revision = s.membership_revision
    AND g.permission_code = 'seo.gsc.connect'
    AND property.active IS TRUE
  FOR SHARE OF s,i,o,m,g,property;

  RETURN FOUND;
END;
$f112_lock$;

REVOKE ALL ON FUNCTION public.portal_gsc_f112_lock_recheck(text,uuid,uuid,text)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f112_lock_recheck(text,uuid,uuid,text)
  TO portal_gsc_f106_owner;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f110_owner;

GRANT CREATE ON SCHEMA public TO portal_gsc_f106_owner;
SET ROLE portal_gsc_f106_owner;

CREATE FUNCTION public.portal_gsc_f112_issue(
  p_session_digest text, p_state_digest text, p_actor uuid,
  p_organization uuid, p_property_fingerprint text,
  p_issued_at bigint, p_expires_at bigint,
  p_nonce text, p_ciphertext text
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f112_issue$
BEGIN
  IF NOT public.portal_gsc_f112_lock_recheck(
    p_session_digest,p_actor,p_organization,p_property_fingerprint
  ) THEN
    RETURN false;
  END IF;

  -- Row locks remain held through this call/transaction.
  RETURN public.portal_gsc_f111_issue(
    p_session_digest,p_state_digest,p_actor,p_organization,
    p_property_fingerprint,p_issued_at,p_expires_at,p_nonce,p_ciphertext
  );
END;
$f112_issue$;

CREATE FUNCTION public.portal_gsc_f112_consume(
  p_session_digest text, p_state_digest text, p_actor uuid,
  p_organization uuid, p_property_fingerprint text
) RETURNS TABLE (matched boolean, envelope_nonce text, envelope_ciphertext text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f112_consume$
DECLARE
  claim record;
BEGIN
  -- F111 burns the state even for invalid/revoked sessions.
  SELECT c.matched,c.envelope_nonce,c.envelope_ciphertext INTO claim
  FROM public.portal_gsc_f111_consume(
    p_session_digest,p_state_digest,p_actor,p_organization,p_property_fingerprint
  ) AS c;

  IF NOT FOUND OR claim.matched IS NOT TRUE
    OR NOT public.portal_gsc_f112_lock_recheck(
      p_session_digest,p_actor,p_organization,p_property_fingerprint
    )
  THEN
    RETURN QUERY SELECT false,NULL::text,NULL::text;
    RETURN;
  END IF;
  RETURN QUERY SELECT true,claim.envelope_nonce::text,
    claim.envelope_ciphertext::text;
END;
$f112_consume$;

REVOKE ALL ON FUNCTION public.portal_gsc_f112_issue(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_gsc_f112_consume(
  text,text,uuid,uuid,text
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f112_issue(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) TO portal_gsc_f106_client;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f112_consume(
  text,text,uuid,uuid,text
) TO portal_gsc_f106_client;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f106_owner;

-- Prevent the client from bypassing transaction-serialized guards.
REVOKE EXECUTE ON FUNCTION public.portal_gsc_f111_issue(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) FROM portal_gsc_f106_client;
REVOKE EXECUTE ON FUNCTION public.portal_gsc_f111_consume(
  text,text,uuid,uuid,text
) FROM portal_gsc_f106_client;

COMMIT;
