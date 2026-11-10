-- PORTAL-F107 / DISPOSABLE PG18 CI ONLY.
-- Integrates a successful F106 claim with one F89 append-only event in the
-- SAME PostgreSQL statement transaction. No trusted actor, no HTTP endpoint,
-- no delivery of email and no external SEC-R03 antirollback witness.
BEGIN;

-- The caller of F106 no longer has EXECUTE on the unaudited entrypoint.
REVOKE EXECUTE ON FUNCTION public.portal_f106_claim_invitation(text,uuid,uuid)
  FROM portal_f106_invitation_probe;

-- The NOLOGIN definer can append exactly the documented invitation event,
-- without SELECT/UPDATE/DELETE/TRUNCATE rights to security event rows.
GRANT INSERT (
  event_id, organization_id, identity_id, event_code,
  evidence_sha256, security_revision
) ON public.portal_security_events TO portal_f106_invitation_definer;
GRANT USAGE ON SEQUENCE public.portal_security_events_sequence_seq
  TO portal_f106_invitation_definer;
CREATE POLICY portal_f107_invite_event_append
  ON public.portal_security_events
  FOR INSERT TO portal_f106_invitation_definer
  WITH CHECK (
    event_code = 'invitation.consumed'
    AND identity_id IS NOT NULL
    AND security_revision > 0
  );

CREATE FUNCTION public.portal_f107_claim_with_audit(
  p_digest text, p_identity uuid, p_organization uuid
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f107$
DECLARE
  v_invitation_id uuid;
  v_revision bigint;
  v_event_id uuid;
  v_evidence text;
BEGIN
  -- F106 is the ONLY update; its verified synthetic email/MFA and revision
  -- checks are reused unchanged. It returns false for replays and invalids.
  IF NOT public.portal_f106_claim_invitation(
    p_digest, p_identity, p_organization
  ) THEN
    RETURN false;
  END IF;

  -- Event evidence binds an invitation UUID, event UUID and organization
  -- (NOT the bearer or bearer-equivalent digest). No personal fields leak.
  SELECT inv.id, inv.identity_revision
    INTO v_invitation_id, v_revision
    FROM public.portal_access_invitations inv
   WHERE inv.token_sha256 = p_digest
     AND inv.identity_id = p_identity
     AND inv.organization_id = p_organization
     AND inv.status = 'consumed';
  IF v_invitation_id IS NULL OR v_revision IS NULL THEN
    RAISE EXCEPTION 'F107 missing committed invitation source'
      USING ERRCODE = '23514';
  END IF;

  v_event_id := gen_random_uuid();
  v_evidence := encode(sha256(convert_to(
    'portal.f107.invitation.consumed.v1:' || v_invitation_id::text
    || ':' || v_event_id::text || ':' || p_organization::text
    || ':' || p_identity::text, 'UTF8')), 'hex');

  INSERT INTO public.portal_security_events (
    event_id, organization_id, identity_id, event_code,
    evidence_sha256, security_revision
  ) VALUES (
    v_event_id, p_organization, p_identity, 'invitation.consumed',
    v_evidence, v_revision
  );
  RETURN true;
EXCEPTION WHEN OTHERS THEN
  -- The entire PL/pgSQL exception block is a subtransaction: if audit INSERT
  -- fails, the F106 pending->consumed change rolls back as well. Never
  -- report success or disclose the tenant/invitation on a partial failure.
  RETURN false;
END;
$f107$;

ALTER FUNCTION public.portal_f107_claim_with_audit(text,uuid,uuid)
  OWNER TO portal_f106_invitation_definer;
REVOKE ALL ON FUNCTION public.portal_f107_claim_with_audit(text,uuid,uuid)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f107_claim_with_audit(text,uuid,uuid)
  TO portal_f106_invitation_probe;

COMMIT;
