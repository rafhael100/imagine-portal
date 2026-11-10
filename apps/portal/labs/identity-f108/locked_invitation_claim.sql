-- PORTAL-F108: disposable PostgreSQL 18 CI ONLY.
-- Lock identity, organization and membership rows while consuming an
-- invitation and appending F89 audit; protect against concurrent revocation
-- writers that UPDATE those rows. Not an HTTP login or trusted actor source.
BEGIN;

-- PostgreSQL requires UPDATE privilege on at least one column to perform
-- SELECT FOR SHARE. Grant only status, never secrets/revision updates.
-- No UPDATE RLS policy exists for this NOLOGIN owner on these tables:
-- UPDATE attempts are still blocked by FORCE RLS.
GRANT UPDATE (status) ON public.portal_identities
  TO portal_f106_invitation_definer;
GRANT UPDATE (status) ON public.portal_organizations
  TO portal_f106_invitation_definer;
GRANT UPDATE (status) ON public.portal_memberships
  TO portal_f106_invitation_definer;

-- SELECT FOR SHARE is subject to BOTH SELECT and UPDATE RLS policies.
-- The UPDATE USING must expose rows for locking. WITH CHECK(false) strictly
-- denies storing any updated row, including a no-op assignment.
-- This is a lab-only security design; verify as an actual SQL mutation test.
CREATE POLICY portal_f108_lock_identity
  ON public.portal_identities
  FOR UPDATE TO portal_f106_invitation_definer
  USING (true) WITH CHECK (false);
CREATE POLICY portal_f108_lock_organization
  ON public.portal_organizations
  FOR UPDATE TO portal_f106_invitation_definer
  USING (true) WITH CHECK (false);
CREATE POLICY portal_f108_lock_membership
  ON public.portal_memberships
  FOR UPDATE TO portal_f106_invitation_definer
  USING (true) WITH CHECK (false);

-- Invariant: no public/normal caller may bypass the protected F107 wrapper.
REVOKE EXECUTE ON FUNCTION public.portal_f107_claim_with_audit(text,uuid,uuid)
  FROM portal_f106_invitation_probe;

CREATE FUNCTION public.portal_f108_locked_claim_with_audit(
  p_digest text, p_identity uuid, p_organization uuid
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f108$
DECLARE
  v_ident_revision bigint;
  v_org_revision bigint;
  v_member_revision bigint;
  v_ident_status text;
  v_org_status text;
  v_member_status text;
  v_email_confirmed timestamptz;
  v_mfa_confirmed timestamptz;
BEGIN
  IF p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
     OR p_identity IS NULL OR p_organization IS NULL THEN
    RETURN false;
  END IF;

  -- Fixed lock order. Do not acquire a snapshot of permission data before
  -- acquiring the locks; under READ COMMITTED these locking reads observe
  -- the latest row versions committed before the lock is obtained.
  SELECT status, security_revision, email_verified_at, mfa_confirmed_at
    INTO v_ident_status, v_ident_revision, v_email_confirmed, v_mfa_confirmed
    FROM public.portal_identities
   WHERE id = p_identity FOR SHARE;
  IF NOT FOUND OR v_ident_status <> 'active'
     OR v_email_confirmed IS NULL OR v_mfa_confirmed IS NULL THEN
    RETURN false;
  END IF;

  SELECT status, security_revision
    INTO v_org_status, v_org_revision
    FROM public.portal_organizations
   WHERE id = p_organization FOR SHARE;
  IF NOT FOUND OR v_org_status <> 'active' THEN
    RETURN false;
  END IF;

  SELECT status, membership_revision
    INTO v_member_status, v_member_revision
    FROM public.portal_memberships
   WHERE identity_id = p_identity AND organization_id = p_organization
   FOR SHARE;
  IF NOT FOUND OR v_member_status <> 'invited' THEN
    RETURN false;
  END IF;

  -- F107 invokes F106 and atomically writes an append-only audit envelope.
  -- It rechecks all three revisions against the invitation's recorded ones
  -- while locks are held. No login, membership activation or grant is made.
  RETURN public.portal_f107_claim_with_audit(
    p_digest, p_identity, p_organization
  );

EXCEPTION WHEN OTHERS THEN
  -- Lock timeout, missing grant or any SQL error -> deny (and rollback work
  -- from this function's PL/pgSQL subtransaction).
  RETURN false;
END;
$f108$;

ALTER FUNCTION public.portal_f108_locked_claim_with_audit(text,uuid,uuid)
  OWNER TO portal_f106_invitation_definer;
REVOKE ALL ON FUNCTION public.portal_f108_locked_claim_with_audit(text,uuid,uuid)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f108_locked_claim_with_audit(text,uuid,uuid)
  TO portal_f106_invitation_probe;

COMMIT;
