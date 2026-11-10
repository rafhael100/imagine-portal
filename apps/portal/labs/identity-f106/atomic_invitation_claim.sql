-- PORTAL-F106: disposable PostgreSQL 18 CI LAB ONLY.
-- This does NOT grant Portal access, activate membership, create a session,
-- verify an email/MFA by itself, or publish an invitation redemption endpoint.
-- Digest input is a BEARER-EQUIVALENT secret; never send it to browser/logs.
BEGIN;

CREATE ROLE portal_f106_invitation_definer
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_f106_invitation_probe
  LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS
  PASSWORD 'ci-f106-probe-only';

GRANT CONNECT ON DATABASE portal_identity_lab TO portal_f106_invitation_probe;
GRANT USAGE ON SCHEMA public TO portal_f106_invitation_probe;
GRANT USAGE, CREATE ON SCHEMA public TO portal_f106_invitation_definer;

-- The NOLOGIN definer has the smallest set of columns needed by the SQL
-- predicate. Password hashes, encrypted MFA material, email address and
-- descriptive business fields are never granted.
GRANT SELECT (id, status, email_verified_at, mfa_confirmed_at, security_revision)
  ON public.portal_identities TO portal_f106_invitation_definer;
GRANT SELECT (id, status, security_revision)
  ON public.portal_organizations TO portal_f106_invitation_definer;
GRANT SELECT (identity_id, organization_id, status, membership_revision)
  ON public.portal_memberships TO portal_f106_invitation_definer;
GRANT SELECT (
  id, token_sha256, identity_id, organization_id, status, issued_at,
  expires_at, consumed_at, revoked_at, identity_revision,
  membership_revision, organization_revision
) ON public.portal_access_invitations TO portal_f106_invitation_definer;
GRANT UPDATE (status, consumed_at, updated_at)
  ON public.portal_access_invitations TO portal_f106_invitation_definer;

-- FORCE RLS remains enabled on all four tables. Policies apply ONLY to the
-- non-inheritable NOLOGIN owner; probe/app roles cannot SELECT invitation rows.
CREATE POLICY portal_f106_identity_probe_read
  ON public.portal_identities FOR SELECT
  TO portal_f106_invitation_definer USING (true);
CREATE POLICY portal_f106_org_probe_read
  ON public.portal_organizations FOR SELECT
  TO portal_f106_invitation_definer USING (true);
CREATE POLICY portal_f106_membership_probe_read
  ON public.portal_memberships FOR SELECT
  TO portal_f106_invitation_definer USING (true);
CREATE POLICY portal_f106_invitation_probe_read
  ON public.portal_access_invitations FOR SELECT
  TO portal_f106_invitation_definer USING (true);
CREATE POLICY portal_f106_invitation_probe_update
  ON public.portal_access_invitations FOR UPDATE
  TO portal_f106_invitation_definer
  USING (status = 'pending' AND revoked_at IS NULL)
  WITH CHECK (status = 'consumed' AND consumed_at IS NOT NULL AND revoked_at IS NULL);

CREATE FUNCTION public.portal_f106_claim_invitation(
  p_digest text, p_identity uuid, p_organization uuid
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f106$
DECLARE
  v_now timestamptz := clock_timestamp();
  v_claimed_id uuid;
BEGIN
  IF p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
     OR p_identity IS NULL OR p_organization IS NULL THEN
    RETURN false;
  END IF;

  -- A single UPDATE is the linearization point for **the invitation row**.
  -- PostgreSQL rechecks the WHERE predicate after waiting on row locks.
  -- This does not prove linearizable revocation of related membership rows;
  -- that requires an additional cross-table transactional protocol.
  UPDATE public.portal_access_invitations AS inv
     SET status = 'consumed', consumed_at = v_now, updated_at = v_now
   WHERE inv.token_sha256 = p_digest
     AND inv.identity_id = p_identity
     AND inv.organization_id = p_organization
     AND inv.status = 'pending'
     AND inv.consumed_at IS NULL
     AND inv.revoked_at IS NULL
     AND inv.issued_at <= v_now
     AND inv.expires_at > v_now
     AND EXISTS (
       SELECT 1
         FROM public.portal_identities AS ident
         JOIN public.portal_memberships AS member
           ON member.identity_id = ident.id
         JOIN public.portal_organizations AS org
           ON org.id = member.organization_id
        WHERE ident.id = inv.identity_id
          AND member.organization_id = inv.organization_id
          AND org.id = inv.organization_id
          AND ident.status = 'active'
          AND ident.email_verified_at IS NOT NULL
          AND ident.mfa_confirmed_at IS NOT NULL
          AND ident.security_revision = inv.identity_revision
          AND member.status = 'invited'
          AND member.membership_revision = inv.membership_revision
          AND org.status = 'active'
          AND org.security_revision = inv.organization_revision
     )
  RETURNING inv.id INTO v_claimed_id;

  RETURN v_claimed_id IS NOT NULL;
EXCEPTION WHEN OTHERS THEN
  -- Fail closed, do not disclose tenant existence or SQL internals.
  -- PL/pgSQL exception subtransaction rolls back changes made in this block.
  RETURN false;
END;
$f106$;

ALTER FUNCTION public.portal_f106_claim_invitation(text,uuid,uuid)
  OWNER TO portal_f106_invitation_definer;
REVOKE ALL ON FUNCTION public.portal_f106_claim_invitation(text,uuid,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f106_claim_invitation(text,uuid,uuid)
  TO portal_f106_invitation_probe;
REVOKE CREATE ON SCHEMA public FROM portal_f106_invitation_definer;

COMMIT;
