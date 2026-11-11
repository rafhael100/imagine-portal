-- GSC-F111: disposable PostgreSQL 18 security laboratory ONLY.
-- State becomes bound to exactly the originating Portal session digest.
-- Callback consumes state once, then rechecks active IAM/grant/property before
-- releasing an encrypted PKCE envelope. No production OAuth, HTTP or Google.
BEGIN;

ALTER TABLE public.portal_gsc_f106_pending
  ADD COLUMN session_digest text
    CHECK (session_digest IS NULL OR session_digest ~ '^[a-f0-9]{64}$');

GRANT CREATE ON SCHEMA public TO portal_gsc_f110_owner;
SET ROLE portal_gsc_f110_owner;

CREATE FUNCTION public.portal_gsc_f111_recheck(
  p_session_digest text, p_actor uuid, p_organization uuid,
  p_property_fingerprint text
) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f111_guard$
  SELECT coalesce(
    p_session_digest ~ '^[a-f0-9]{64}$'
    AND p_actor IS NOT NULL AND p_organization IS NOT NULL
    AND p_property_fingerprint ~ '^[a-f0-9]{64}$'
    AND EXISTS (
      SELECT 1
      FROM public.portal_auth_sessions AS s
      JOIN public.portal_identities AS i ON i.id = s.identity_id
      JOIN public.portal_organizations AS o ON o.id = s.organization_id
      JOIN public.portal_memberships AS m
        ON m.identity_id = s.identity_id AND m.organization_id = s.organization_id
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
        AND o.security_revision = s.organization_revision
        AND m.status = 'active'
        AND m.membership_revision = s.membership_revision
        AND g.permission_code = 'seo.gsc.connect'
        AND property.active IS TRUE
    ),false
  );
$f111_guard$;
REVOKE ALL ON FUNCTION public.portal_gsc_f111_recheck(text,uuid,uuid,text)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f111_recheck(text,uuid,uuid,text)
  TO portal_gsc_f106_owner;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f110_owner;

GRANT EXECUTE ON FUNCTION public.portal_gsc_f110_iam_issued_state(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) TO portal_gsc_f106_owner;
GRANT CREATE ON SCHEMA public TO portal_gsc_f106_owner;
SET ROLE portal_gsc_f106_owner;

CREATE FUNCTION public.portal_gsc_f111_issue(
  p_session_digest text, p_state_digest text, p_actor uuid,
  p_organization uuid, p_property_fingerprint text,
  p_issued_at bigint, p_expires_at bigint,
  p_nonce text, p_ciphertext text
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f111_issue$
DECLARE
  changed integer := 0;
BEGIN
  IF p_session_digest IS NULL OR p_session_digest !~ '^[a-f0-9]{64}$' THEN
    RETURN false;
  END IF;
  IF NOT public.portal_gsc_f110_iam_issued_state(
    p_session_digest, p_state_digest, p_actor, p_organization,
    p_property_fingerprint, p_issued_at, p_expires_at,
    p_nonce, p_ciphertext
  ) THEN
    RETURN false;
  END IF;

  UPDATE public.portal_gsc_f106_pending AS state
     SET session_digest = p_session_digest
   WHERE state.state_digest = p_state_digest
     AND state.actor_id = p_actor
     AND state.organization_id = p_organization
     AND state.property_fingerprint = p_property_fingerprint
     AND state.consumed_at IS NULL
     AND state.session_digest IS NULL;
  GET DIAGNOSTICS changed = ROW_COUNT;
  IF changed <> 1 THEN
    RAISE EXCEPTION 'Synthetic GSC state binding failed';
  END IF;
  RETURN true;
END;
$f111_issue$;

CREATE FUNCTION public.portal_gsc_f111_consume(
  p_session_digest text, p_state_digest text, p_actor uuid,
  p_organization uuid, p_property_fingerprint text
) RETURNS TABLE (matched boolean, envelope_nonce text, envelope_ciphertext text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f111_consume$
DECLARE
  checked record;
  stored_session text;
  db_now bigint := floor(extract(epoch FROM clock_timestamp()))::bigint;
BEGIN
  IF p_session_digest IS NULL OR p_session_digest !~ '^[a-f0-9]{64}$'
    OR p_state_digest IS NULL OR p_state_digest !~ '^[a-f0-9]{64}$'
    OR p_actor IS NULL OR p_organization IS NULL
    OR p_property_fingerprint IS NULL
    OR p_property_fingerprint !~ '^[a-f0-9]{64}$'
  THEN
    RETURN QUERY SELECT false,NULL::text,NULL::text;
    RETURN;
  END IF;

  -- Burn state using DATABASE clock, not a caller-supplied timestamp.
  -- A mismatched session or revoked grant must not get even ciphertext.
  SELECT claim.matched,claim.envelope_nonce,claim.envelope_ciphertext
    INTO checked
  FROM public.portal_gsc_f108_consume(
    p_state_digest,p_actor,p_organization,p_property_fingerprint,db_now
  ) AS claim;

  IF NOT FOUND OR checked.matched IS NOT TRUE THEN
    RETURN QUERY SELECT false,NULL::text,NULL::text;
    RETURN;
  END IF;

  SELECT state.session_digest INTO stored_session
    FROM public.portal_gsc_f106_pending AS state
    WHERE state.state_digest = p_state_digest
      AND state.consumed_at IS NOT NULL;

  IF stored_session IS NULL
    OR stored_session <> p_session_digest
    OR NOT public.portal_gsc_f111_recheck(
      p_session_digest,p_actor,p_organization,p_property_fingerprint
    )
  THEN
    RETURN QUERY SELECT false,NULL::text,NULL::text;
    RETURN;
  END IF;
  RETURN QUERY SELECT true,checked.envelope_nonce::text,
    checked.envelope_ciphertext::text;
END;
$f111_consume$;

REVOKE ALL ON FUNCTION public.portal_gsc_f111_issue(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_gsc_f111_consume(
  text,text,uuid,uuid,text
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f111_issue(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) TO portal_gsc_f106_client;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f111_consume(
  text,text,uuid,uuid,text
) TO portal_gsc_f106_client;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f106_owner;
-- There must be NO direct client path around F111's session recheck.
REVOKE EXECUTE ON FUNCTION public.portal_gsc_f110_iam_issued_state(
  text,text,uuid,uuid,text,bigint,bigint,text,text
) FROM portal_gsc_f106_client;
REVOKE EXECUTE ON FUNCTION public.portal_gsc_f108_consume(
  text,uuid,uuid,text,bigint
) FROM portal_gsc_f106_client;
COMMIT;
