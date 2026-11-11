-- PORTAL-F118: disposable PostgreSQL 18 lab, never a live customer adapter.
-- After a successful F117 invite claim + activation, atomically revoke ALL
-- remaining sessions for the same Portal identity AND organization, regardless
-- of purpose. This prevents pre-issued "business" tokens from acquiring a
-- service grant merely because a membership switched invited -> active.
--
-- Runtime Laravel still DENIES all identities and authorities, HTTP only /up.
-- The SECURITY DEFINER owner is NOLOGIN/NOSUPERUSER/NOBYPASSRLS and the
-- caller has EXECUTE only. SESSION DIGEST INPUT IS BEARER-EQUIVALENT.
BEGIN;

CREATE ROLE portal_f118_retirement_owner
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_f118_retirement_probe
  LOGIN PASSWORD 'ci-f118-probe-only'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE portal_identity_lab TO portal_f118_retirement_probe;
GRANT USAGE ON SCHEMA public TO
  portal_f118_retirement_owner,portal_f118_retirement_probe;
GRANT CREATE ON SCHEMA public TO portal_f118_retirement_owner;

GRANT SELECT (
  id,session_sha256,identity_id,organization_id,status,session_purpose,
  membership_revision
) ON public.portal_auth_sessions TO portal_f118_retirement_owner;
GRANT UPDATE (status,revoked_at,updated_at)
  ON public.portal_auth_sessions TO portal_f118_retirement_owner;
GRANT SELECT (identity_id,organization_id,status,membership_revision)
  ON public.portal_memberships TO portal_f118_retirement_owner;

-- Owner may see session rows only in its definer context; the reader cannot.
-- The policy permits ONLY status active|pending_mfa -> revoked. Column grants
-- prevent changing digest/identity/organization/purpose or issuing a session.
CREATE POLICY portal_f118_session_select
  ON public.portal_auth_sessions FOR SELECT
  TO portal_f118_retirement_owner USING (true);
CREATE POLICY portal_f118_session_retire
  ON public.portal_auth_sessions FOR UPDATE
  TO portal_f118_retirement_owner
  USING (status IN ('active','pending_mfa'))
  WITH CHECK (status='revoked' AND revoked_at IS NOT NULL);
CREATE POLICY portal_f118_membership_select
  ON public.portal_memberships FOR SELECT
  TO portal_f118_retirement_owner USING (true);

-- F117 has already obtained identity/org/membership locks and written the
-- two mandatory audit events. Only the F118 wrapper remains callable.
GRANT EXECUTE ON FUNCTION public.portal_f117_accept_with_audit(text,text,uuid)
  TO portal_f118_retirement_owner;
REVOKE EXECUTE ON FUNCTION public.portal_f117_accept_with_audit(text,text,uuid)
  FROM portal_f117_accept_probe;

GRANT INSERT (
  event_id,organization_id,identity_id,event_code,
  evidence_sha256,security_revision
) ON public.portal_security_events TO portal_f118_retirement_owner;
GRANT USAGE ON SEQUENCE public.portal_security_events_sequence_seq
  TO portal_f118_retirement_owner;
CREATE POLICY portal_f118_session_event_append
  ON public.portal_security_events FOR INSERT
  TO portal_f118_retirement_owner
  WITH CHECK (
    event_code='session.revoked'
    AND identity_id IS NOT NULL AND security_revision>1
  );

CREATE FUNCTION public.portal_f118_accept_and_retire_all(
  p_session_digest text,p_invitation_digest text,p_organization uuid
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f118$
DECLARE
  v_identity uuid;
  v_old_revision bigint;
  v_new_revision bigint;
  v_count bigint;
  v_retired bigint:=0;
  v_session_id uuid;
  v_event_id uuid;
  v_now timestamptz;
  v_evidence text;
BEGIN
  IF p_session_digest IS NULL OR p_session_digest !~ '^[a-f0-9]{64}$'
    OR p_invitation_digest IS NULL
    OR p_invitation_digest !~ '^[a-f0-9]{64}$'
    OR p_organization IS NULL THEN
    RETURN false;
  END IF;

  -- F117 does ALL trustworthy verification, locking and event creation.
  -- This nested call is in the SAME statement transaction/subtransaction.
  IF NOT public.portal_f117_accept_with_audit(
    p_session_digest,p_invitation_digest,p_organization
  ) THEN
    RETURN false;
  END IF;

  -- Any failure AFTER F117 succeeds MUST RAISE (and be caught by the outer
  -- EXCEPTION block) to undo the F117 invitation+membership+audit changes.
  SELECT s.identity_id,s.membership_revision,m.membership_revision
    INTO v_identity,v_old_revision,v_new_revision
    FROM public.portal_auth_sessions AS s
    JOIN public.portal_memberships AS m
      ON m.identity_id=s.identity_id AND m.organization_id=s.organization_id
   WHERE s.session_sha256=p_session_digest
     AND s.organization_id=p_organization
     AND s.session_purpose='invitation_only'
     AND s.status='revoked'
     AND m.status='active';
  IF NOT FOUND OR v_identity IS NULL
    OR v_new_revision IS DISTINCT FROM v_old_revision+1 THEN
    RAISE EXCEPTION 'F118 post-acceptance provenance mismatch'
      USING ERRCODE='23514';
  END IF;

  -- Cap a potentially abusive session fleet. Return no partial activation
  -- on overflow; a controlled cleanup/recovery path will be necessary.
  SELECT count(*) INTO v_count
    FROM public.portal_auth_sessions AS s
   WHERE s.identity_id=v_identity AND s.organization_id=p_organization
     AND s.status IN ('active','pending_mfa');
  IF v_count>50 THEN
    RAISE EXCEPTION 'F118 session fleet exceeds bounded retirement'
      USING ERRCODE='23514';
  END IF;

  -- F117 holds the membership row lock through commit; any future trusted
  -- issuer MUST obtain that lock before inserting or confirming a session.
  -- Stable ordering also avoids lock inversions between sibling rows.
  FOR v_session_id IN
    SELECT s.id FROM public.portal_auth_sessions AS s
     WHERE s.identity_id=v_identity AND s.organization_id=p_organization
       AND s.status IN ('active','pending_mfa')
     ORDER BY s.id FOR UPDATE
  LOOP
    v_now:=clock_timestamp();
    UPDATE public.portal_auth_sessions AS s
       SET status='revoked',revoked_at=v_now,updated_at=v_now
     WHERE s.id=v_session_id
       AND s.identity_id=v_identity AND s.organization_id=p_organization
       AND s.status IN ('active','pending_mfa');
    GET DIAGNOSTICS v_count=ROW_COUNT;
    IF v_count<>1 THEN
      RAISE EXCEPTION 'F118 sibling session retirement conflict'
        USING ERRCODE='23514';
    END IF;

    -- The F89 append-only security event never contains a token or its hash.
    v_event_id:=gen_random_uuid();
    v_evidence:=encode(sha256(convert_to(
      'portal.f118.session.revoked.v1:'||v_session_id::text
      ||':'||v_event_id::text||':'||p_organization::text
      ||':'||v_identity::text,'UTF8')),'hex');
    INSERT INTO public.portal_security_events (
      event_id,organization_id,identity_id,event_code,
      evidence_sha256,security_revision
    ) VALUES (
      v_event_id,p_organization,v_identity,'session.revoked',
      v_evidence,v_new_revision
    );
    v_retired:=v_retired+1;
  END LOOP;

  -- Recheck at the end; never let a missed sibling survive a "success".
  SELECT count(*) INTO v_count
    FROM public.portal_auth_sessions AS s
   WHERE s.identity_id=v_identity AND s.organization_id=p_organization
     AND s.status IN ('active','pending_mfa');
  IF v_count<>0 THEN
    RAISE EXCEPTION 'F118 incomplete credential retirement'
      USING ERRCODE='23514';
  END IF;

  RETURN true;
EXCEPTION WHEN OTHERS THEN
  -- Exception block is a PL/pgSQL subtransaction. It atomically reverses
  -- the F117 claim, F89 events, membership activation and every revocation.
  RETURN false;
END;
$f118$;
ALTER FUNCTION public.portal_f118_accept_and_retire_all(text,text,uuid)
  OWNER TO portal_f118_retirement_owner;
REVOKE ALL ON FUNCTION public.portal_f118_accept_and_retire_all(text,text,uuid)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f118_accept_and_retire_all(text,text,uuid)
  TO portal_f118_retirement_probe;
REVOKE CREATE ON SCHEMA public FROM portal_f118_retirement_owner;
COMMIT;
