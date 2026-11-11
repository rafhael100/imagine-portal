-- GSC-F108: encrypted PKCE envelope attached atomically to F106 state.
-- Only disposable PG18 lab. Never deploy to a customer database.
BEGIN;

ALTER TABLE public.portal_gsc_f106_pending
  ADD COLUMN envelope_nonce text
    CHECK (envelope_nonce IS NULL OR envelope_nonce ~ '^[A-Za-z0-9_-]{32}$'),
  ADD COLUMN envelope_ciphertext text
    CHECK (envelope_ciphertext IS NULL
      OR (length(envelope_ciphertext) BETWEEN 80 AND 4096
        AND envelope_ciphertext ~ '^[A-Za-z0-9_-]+$'));

GRANT CREATE ON SCHEMA public TO portal_gsc_f106_owner;
SET ROLE portal_gsc_f106_owner;

CREATE FUNCTION public.portal_gsc_f108_issue(
  p_state_digest text, p_actor uuid, p_organization uuid,
  p_property_fingerprint text, p_issued_at bigint, p_expires_at bigint,
  p_nonce text, p_ciphertext text
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $f108$
DECLARE
  inserted integer := 0;
BEGIN
  IF p_state_digest IS NULL OR p_state_digest !~ '^[a-f0-9]{64}$'
    OR p_actor IS NULL OR p_organization IS NULL
    OR p_property_fingerprint IS NULL OR p_property_fingerprint !~ '^[a-f0-9]{64}$'
    OR p_issued_at IS NULL OR p_issued_at <= 0
    OR p_expires_at IS NULL OR p_expires_at <> p_issued_at + 300
    OR p_nonce IS NULL OR p_nonce !~ '^[A-Za-z0-9_-]{32}$'
    OR p_ciphertext IS NULL OR length(p_ciphertext) NOT BETWEEN 80 AND 4096
    OR p_ciphertext !~ '^[A-Za-z0-9_-]+$'
  THEN
    RETURN false;
  END IF;

  INSERT INTO public.portal_gsc_f106_pending
    (state_digest, actor_id, organization_id, property_fingerprint,
     issued_at, expires_at, envelope_nonce, envelope_ciphertext)
  VALUES
    (p_state_digest, p_actor, p_organization, p_property_fingerprint,
     p_issued_at, p_expires_at, p_nonce, p_ciphertext)
  ON CONFLICT (state_digest) DO NOTHING;
  GET DIAGNOSTICS inserted = ROW_COUNT;
  RETURN inserted = 1;
END;
$f108$;

CREATE FUNCTION public.portal_gsc_f108_consume(
  p_state_digest text, p_actor uuid, p_organization uuid,
  p_property_fingerprint text, p_now bigint
) RETURNS TABLE (matched boolean, envelope_nonce text, envelope_ciphertext text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $f108$
DECLARE
  claimed record;
BEGIN
  IF p_state_digest IS NULL OR p_state_digest !~ '^[a-f0-9]{64}$'
    OR p_actor IS NULL OR p_organization IS NULL
    OR p_property_fingerprint IS NULL OR p_property_fingerprint !~ '^[a-f0-9]{64}$'
    OR p_now IS NULL OR p_now <= 0
  THEN
    RETURN QUERY SELECT false, NULL::text, NULL::text;
    RETURN;
  END IF;

  -- Claims at most one row. Competing callbacks serialize at the row lock.
  -- Wrong actor/org/property or expired claim burns the state without
  -- revealing even the encrypted verifier envelope.
  WITH claim AS (
    UPDATE public.portal_gsc_f106_pending AS pending
       SET consumed_at = p_now
     WHERE pending.state_digest = p_state_digest
       AND pending.consumed_at IS NULL
     RETURNING (
        pending.actor_id = p_actor
        AND pending.organization_id = p_organization
        AND pending.property_fingerprint = p_property_fingerprint
        AND p_now >= pending.issued_at
        AND p_now <= pending.expires_at
        AND pending.envelope_nonce IS NOT NULL
        AND pending.envelope_ciphertext IS NOT NULL
      ) AS ok, pending.envelope_nonce AS nonce,
      pending.envelope_ciphertext AS cipher
  )
  SELECT ok, nonce, cipher INTO claimed FROM claim;

  IF NOT FOUND OR claimed.ok IS NOT TRUE THEN
    RETURN QUERY SELECT false, NULL::text, NULL::text;
    RETURN;
  END IF;
  RETURN QUERY SELECT true, claimed.nonce::text, claimed.cipher::text;
END;
$f108$;

REVOKE ALL ON FUNCTION public.portal_gsc_f108_issue(text,uuid,uuid,text,bigint,bigint,text,text)
  FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_gsc_f108_consume(text,uuid,uuid,text,bigint)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f108_issue(text,uuid,uuid,text,bigint,bigint,text,text)
  TO portal_gsc_f106_client;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f108_consume(text,uuid,uuid,text,bigint)
  TO portal_gsc_f106_client;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f106_owner;

COMMIT;
