-- PORTAL-F117: disposable PostgreSQL 18 only.
-- Atomic acceptance = F108 audited invitation claim + active membership +
-- revocation of the INVITATION-ONLY session + F89 membership audit event.
-- Identity is derived exclusively from a database session digest.
-- Digest arguments are bearer-equivalent; do not expose this SQL as HTTP.
-- NOT a customer authenticator, permission grant or production RLS policy.
BEGIN;

CREATE ROLE portal_f117_accept_owner
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_f117_accept_probe
  LOGIN PASSWORD 'ci-f117-probe-only'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE portal_identity_lab TO portal_f117_accept_probe;
GRANT USAGE ON SCHEMA public TO portal_f117_accept_probe,portal_f117_accept_owner;
GRANT CREATE ON SCHEMA public TO portal_f117_accept_owner;

GRANT SELECT (
  id,status,email_verified_at,mfa_confirmed_at,security_revision
) ON public.portal_identities TO portal_f117_accept_owner;
GRANT SELECT (id,status,security_revision)
  ON public.portal_organizations TO portal_f117_accept_owner;
GRANT SELECT (id,identity_id,organization_id,status,membership_revision)
  ON public.portal_memberships TO portal_f117_accept_owner;
GRANT SELECT (
  id,session_sha256,identity_id,organization_id,status,session_purpose,
  identity_revision,membership_revision,organization_revision,
  revoked_at,issued_at,expires_at,mfa_verified_at
) ON public.portal_auth_sessions TO portal_f117_accept_owner;

-- PostgreSQL requires UPDATE(column) to lock SELECT ... FOR SHARE.
-- These two authorities have WITH CHECK(false): the owner CANNOT change
-- identity or tenant status, even though it can lock their rows.
GRANT UPDATE (status) ON public.portal_identities TO portal_f117_accept_owner;
GRANT UPDATE (status) ON public.portal_organizations TO portal_f117_accept_owner;
GRANT UPDATE (status,membership_revision,updated_at)
  ON public.portal_memberships TO portal_f117_accept_owner;
GRANT UPDATE (status,revoked_at,updated_at)
  ON public.portal_auth_sessions TO portal_f117_accept_owner;

CREATE POLICY portal_f117_identity_read
  ON public.portal_identities FOR SELECT
  TO portal_f117_accept_owner USING (true);
CREATE POLICY portal_f117_identity_lock
  ON public.portal_identities FOR UPDATE
  TO portal_f117_accept_owner USING (true) WITH CHECK (false);
CREATE POLICY portal_f117_organization_read
  ON public.portal_organizations FOR SELECT
  TO portal_f117_accept_owner USING (true);
CREATE POLICY portal_f117_organization_lock
  ON public.portal_organizations FOR UPDATE
  TO portal_f117_accept_owner USING (true) WITH CHECK (false);
CREATE POLICY portal_f117_membership_read
  ON public.portal_memberships FOR SELECT
  TO portal_f117_accept_owner USING (true);
CREATE POLICY portal_f117_membership_activation
  ON public.portal_memberships FOR UPDATE
  TO portal_f117_accept_owner
  USING (status='invited')
  WITH CHECK (status='active' AND membership_revision>1);
CREATE POLICY portal_f117_session_read
  ON public.portal_auth_sessions FOR SELECT
  TO portal_f117_accept_owner
  USING (session_purpose='invitation_only');
CREATE POLICY portal_f117_session_revoke
  ON public.portal_auth_sessions FOR UPDATE
  TO portal_f117_accept_owner
  USING (session_purpose='invitation_only' AND status='active')
  WITH CHECK (session_purpose='invitation_only' AND status='revoked'
              AND revoked_at IS NOT NULL);

GRANT EXECUTE ON FUNCTION public.portal_f108_locked_claim_with_audit(text,uuid,uuid)
  TO portal_f117_accept_owner;
-- Reject unaudited or half-accepted invitation claims by the old probe.
REVOKE EXECUTE ON FUNCTION public.portal_f108_locked_claim_with_audit(text,uuid,uuid)
  FROM portal_f106_invitation_probe;

GRANT INSERT (
  event_id,organization_id,identity_id,event_code,
  evidence_sha256,security_revision
) ON public.portal_security_events TO portal_f117_accept_owner;
GRANT USAGE ON SEQUENCE public.portal_security_events_sequence_seq
  TO portal_f117_accept_owner;
CREATE POLICY portal_f117_membership_audit_append
  ON public.portal_security_events FOR INSERT
  TO portal_f117_accept_owner
  WITH CHECK (
    event_code='membership.activated'
    AND identity_id IS NOT NULL AND security_revision>1
  );

CREATE FUNCTION public.portal_f117_accept_with_audit(
  p_session_digest text,
  p_invitation_digest text,
  p_organization uuid
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f117$
DECLARE
  v_actor uuid;
  v_id_status text;
  v_id_revision bigint;
  v_email timestamptz;
  v_enrolled_mfa timestamptz;
  v_org_status text;
  v_org_revision bigint;
  v_member_id uuid;
  v_member_status text;
  v_member_revision bigint;
  v_session_id uuid;
  v_session_identity uuid;
  v_session_organization uuid;
  v_session_status text;
  v_session_purpose text;
  v_session_id_rev bigint;
  v_session_member_rev bigint;
  v_session_org_rev bigint;
  v_session_issue timestamptz;
  v_session_expiry timestamptz;
  v_session_mfa timestamptz;
  v_session_revoked timestamptz;
  v_now timestamptz;
  v_count bigint;
  v_event_id uuid;
  v_evidence text;
BEGIN
  IF p_session_digest IS NULL OR p_session_digest !~ '^[a-f0-9]{64}$'
     OR p_invitation_digest IS NULL
     OR p_invitation_digest !~ '^[a-f0-9]{64}$'
     OR p_organization IS NULL THEN
    RETURN false;
  END IF;

  -- First lookup does NOT establish authority. After locking, recheck the
  -- session hash, actor, organization, purpose, revisions and timestamps.
  SELECT s.identity_id INTO v_actor
    FROM public.portal_auth_sessions AS s
   WHERE s.session_sha256=p_session_digest
     AND s.organization_id=p_organization
     AND s.session_purpose='invitation_only';
  IF NOT FOUND OR v_actor IS NULL THEN
    RETURN false;
  END IF;

  -- Fixed order matches F108: identity -> organization -> membership.
  -- The session is locked LAST, and all conditions are checked after locks.
  SELECT i.status,i.security_revision,i.email_verified_at,i.mfa_confirmed_at
    INTO v_id_status,v_id_revision,v_email,v_enrolled_mfa
    FROM public.portal_identities AS i
   WHERE i.id=v_actor
   FOR SHARE;
  IF NOT FOUND OR v_id_status<>'active'
     OR v_email IS NULL OR v_enrolled_mfa IS NULL THEN
    RETURN false;
  END IF;

  SELECT o.status,o.security_revision
    INTO v_org_status,v_org_revision
    FROM public.portal_organizations AS o
   WHERE o.id=p_organization FOR SHARE;
  IF NOT FOUND OR v_org_status<>'active' THEN
    RETURN false;
  END IF;

  SELECT m.id,m.status,m.membership_revision
    INTO v_member_id,v_member_status,v_member_revision
    FROM public.portal_memberships AS m
   WHERE m.identity_id=v_actor AND m.organization_id=p_organization
   FOR UPDATE;
  IF NOT FOUND OR v_member_status<>'invited' THEN
    RETURN false;
  END IF;

  SELECT s.id,s.identity_id,s.organization_id,s.status,s.session_purpose,
         s.identity_revision,s.membership_revision,s.organization_revision,
         s.issued_at,s.expires_at,s.mfa_verified_at,s.revoked_at
    INTO v_session_id,v_session_identity,v_session_organization,
         v_session_status,v_session_purpose,
         v_session_id_rev,v_session_member_rev,v_session_org_rev,
         v_session_issue,v_session_expiry,v_session_mfa,v_session_revoked
    FROM public.portal_auth_sessions AS s
   WHERE s.session_sha256=p_session_digest
   FOR UPDATE;
  v_now:=clock_timestamp();
  IF NOT FOUND
     OR v_session_identity IS DISTINCT FROM v_actor
     OR v_session_organization IS DISTINCT FROM p_organization
     OR v_session_status<>'active'
     OR v_session_purpose<>'invitation_only'
     OR v_session_revoked IS NOT NULL
     OR v_session_id_rev IS DISTINCT FROM v_id_revision
     OR v_session_member_rev IS DISTINCT FROM v_member_revision
     OR v_session_org_rev IS DISTINCT FROM v_org_revision
     OR v_session_issue>v_now OR v_session_expiry<=v_now
     OR v_session_expiry>v_session_issue+interval '1 hour'
     OR v_session_mfa IS NULL OR v_session_mfa<v_session_issue
     OR v_session_mfa>v_now
     OR v_session_mfa<v_now-interval '5 minutes'
     OR v_email>v_now OR v_enrolled_mfa>v_now THEN
    RETURN false;
  END IF;

  -- F108->F107->F106 claims the invitation and appends its event.
  -- It rechecks identity/org/membership revisions while locks are held.
  IF NOT public.portal_f108_locked_claim_with_audit(
    p_invitation_digest,v_actor,p_organization
  ) THEN
    RETURN false;
  END IF;

  UPDATE public.portal_memberships
     SET status='active',membership_revision=membership_revision+1,
         updated_at=v_now
   WHERE id=v_member_id AND identity_id=v_actor
     AND organization_id=p_organization
     AND status='invited' AND membership_revision=v_member_revision;
  GET DIAGNOSTICS v_count=ROW_COUNT;
  IF v_count<>1 THEN
    RAISE EXCEPTION 'F117 activation conflict' USING ERRCODE='23514';
  END IF;

  -- Retire the temporary credential: it MUST NOT turn into a business
  -- credential when the business membership becomes active.
  UPDATE public.portal_auth_sessions
     SET status='revoked',revoked_at=v_now,updated_at=v_now
   WHERE id=v_session_id AND status='active'
     AND session_purpose='invitation_only'
     AND identity_revision=v_id_revision
     AND membership_revision=v_member_revision
     AND organization_revision=v_org_revision;
  GET DIAGNOSTICS v_count=ROW_COUNT;
  IF v_count<>1 THEN
    RAISE EXCEPTION 'F117 temporary session revocation conflict'
      USING ERRCODE='23514';
  END IF;

  -- A distinct F89 event proves the membership transition in this
  -- database transaction; not an externally witnessed SEC-R03 signature.
  v_event_id:=gen_random_uuid();
  v_evidence:=encode(sha256(convert_to(
    'portal.f117.membership.activated.v1:'||v_member_id::text
    ||':'||v_event_id::text||':'||p_organization::text
    ||':'||v_actor::text, 'UTF8')), 'hex');
  INSERT INTO public.portal_security_events (
    event_id,organization_id,identity_id,event_code,
    evidence_sha256,security_revision
  ) VALUES (
    v_event_id,p_organization,v_actor,'membership.activated',
    v_evidence,v_member_revision+1
  );

  RETURN true;
EXCEPTION WHEN OTHERS THEN
  -- PL/pgSQL exception subtransaction unwinds the F108 invitation/event,
  -- membership change, provisional session revoke and activation event.
  RETURN false;
END;
$f117$;

ALTER FUNCTION public.portal_f117_accept_with_audit(text,text,uuid)
  OWNER TO portal_f117_accept_owner;
REVOKE ALL ON FUNCTION public.portal_f117_accept_with_audit(text,text,uuid)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f117_accept_with_audit(text,text,uuid)
  TO portal_f117_accept_probe;
REVOKE CREATE ON SCHEMA public FROM portal_f117_accept_owner;
COMMIT;
