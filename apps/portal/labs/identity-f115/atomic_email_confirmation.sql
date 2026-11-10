-- PORTAL-F115 / DISPOSABLE PG18 LAB ONLY.
-- An opaque email challenge digest is bearer-equivalent; accept it only inside
-- a future trusted first-party server, NEVER a browser-facing SQL function.
-- No message is delivered and no mailbox ownership is proven by this lab.
BEGIN;

CREATE ROLE portal_f115_email_definer
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_f115_email_probe
  LOGIN PASSWORD 'ci-f115-email-only'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE portal_identity_lab TO portal_f115_email_probe;
GRANT USAGE ON SCHEMA public TO
  portal_f115_email_definer,portal_f115_email_probe;
GRANT CREATE ON SCHEMA public TO portal_f115_email_definer;

GRANT SELECT (id,email_normalized,status,security_revision,email_verified_at)
  ON public.portal_identities TO portal_f115_email_definer;
GRANT UPDATE (email_verified_at,updated_at)
  ON public.portal_identities TO portal_f115_email_definer;
GRANT SELECT (
  id,token_sha256,identity_id,email_sha256,identity_revision,status,
  issued_at,expires_at,consumed_at,revoked_at
) ON public.portal_email_verification_challenges TO portal_f115_email_definer;
GRANT UPDATE (status,consumed_at,updated_at)
  ON public.portal_email_verification_challenges TO portal_f115_email_definer;

-- Lab-only policies for a NOLOGIN owner. No caller reads identities.
CREATE POLICY portal_f115_identity_read
  ON public.portal_identities FOR SELECT
  TO portal_f115_email_definer USING (true);
CREATE POLICY portal_f115_identity_update
  ON public.portal_identities FOR UPDATE
  TO portal_f115_email_definer
  USING (true)
  WITH CHECK (status='invited' AND email_verified_at IS NOT NULL);
CREATE POLICY portal_f115_challenge_read
  ON public.portal_email_verification_challenges FOR SELECT
  TO portal_f115_email_definer USING (true);
CREATE POLICY portal_f115_challenge_update
  ON public.portal_email_verification_challenges FOR UPDATE
  TO portal_f115_email_definer
  USING (true)
  WITH CHECK (status='consumed' AND consumed_at IS NOT NULL);

CREATE FUNCTION public.portal_f115_confirm_email(
  p_digest text,p_identity uuid
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f115$
DECLARE
  v_identity_status text;
  v_identity_revision bigint;
  v_email_verified_at timestamptz;
  v_email_normalized text;
  v_challenge_id uuid;
  v_challenge_revision bigint;
  v_challenge_email_digest text;
  v_issued_at timestamptz;
  v_expires_at timestamptz;
  v_now timestamptz;
  v_rows integer;
BEGIN
  IF p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
     OR p_identity IS NULL THEN
    RETURN false;
  END IF;

  -- Lock identity FIRST so email changes, revocations, or revisions
  -- cannot cross the claim in the same PostgreSQL transaction.
  SELECT i.status,i.security_revision,i.email_verified_at,i.email_normalized
    INTO v_identity_status,v_identity_revision,v_email_verified_at,
         v_email_normalized
    FROM public.portal_identities i
   WHERE i.id=p_identity
   FOR UPDATE;
  IF NOT FOUND OR v_identity_status<>'invited'
     OR v_email_verified_at IS NOT NULL
     OR v_identity_revision IS NULL OR v_identity_revision<1 THEN
    RETURN false;
  END IF;

  SELECT c.id,c.identity_revision,c.email_sha256,c.issued_at,c.expires_at
    INTO v_challenge_id,v_challenge_revision,v_challenge_email_digest,
         v_issued_at,v_expires_at
    FROM public.portal_email_verification_challenges c
   WHERE c.token_sha256=p_digest
     AND c.identity_id=p_identity
     AND c.status='pending'
     AND c.consumed_at IS NULL
     AND c.revoked_at IS NULL
   FOR UPDATE;
  v_now:=clock_timestamp();
  IF NOT FOUND OR v_challenge_revision<>v_identity_revision
     OR v_challenge_email_digest IS DISTINCT FROM
        encode(sha256(convert_to(v_email_normalized,'UTF8')),'hex')
     OR v_issued_at>v_now OR v_expires_at<=v_now THEN
    RETURN false;
  END IF;

  UPDATE public.portal_identities
     SET email_verified_at=v_now,updated_at=v_now
   WHERE id=p_identity AND status='invited'
     AND email_verified_at IS NULL AND security_revision=v_identity_revision;
  GET DIAGNOSTICS v_rows=ROW_COUNT;
  IF v_rows<>1 THEN
    RAISE EXCEPTION 'Identity verification conflict' USING ERRCODE='23514';
  END IF;

  UPDATE public.portal_email_verification_challenges
     SET status='consumed',consumed_at=v_now,updated_at=v_now
   WHERE id=v_challenge_id
     AND status='pending'
     AND issued_at<=v_now AND expires_at>v_now;
  GET DIAGNOSTICS v_rows=ROW_COUNT;
  IF v_rows<>1 THEN
    -- Exception subtransaction rolls back the email_verified_at write.
    RAISE EXCEPTION 'Email challenge conflict' USING ERRCODE='23514';
  END IF;
  RETURN true;

EXCEPTION WHEN OTHERS THEN
  -- PL/pgSQL's exception block is a subtransaction: a failed challenge
  -- write cannot leave email verified without consuming the token.
  RETURN false;
END;
$f115$;

ALTER FUNCTION public.portal_f115_confirm_email(text,uuid)
  OWNER TO portal_f115_email_definer;
REVOKE ALL ON FUNCTION public.portal_f115_confirm_email(text,uuid)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f115_confirm_email(text,uuid)
  TO portal_f115_email_probe;
REVOKE CREATE ON SCHEMA public FROM portal_f115_email_definer;

COMMIT;
