-- PORTAL-F119: disposable PostgreSQL 18 test only; no runtime issuer.
-- The future issuer and F118 retirement MUST serialize on the same membership
-- row. A trigger guards session INSERT/UPDATE to prevent pre-activation
-- business credentials and post-activation invitation-only sessions.
-- Real credential provenance, KMS and customer IAM still do NOT exist.
BEGIN;

CREATE ROLE portal_f119_admission_owner
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE,CREATE ON SCHEMA public TO portal_f119_admission_owner;
GRANT SELECT (id,status,email_verified_at,mfa_confirmed_at,security_revision)
  ON public.portal_identities TO portal_f119_admission_owner;
GRANT SELECT (id,status,security_revision)
  ON public.portal_organizations TO portal_f119_admission_owner;
GRANT SELECT (identity_id,organization_id,status,membership_revision,updated_at)
  ON public.portal_memberships TO portal_f119_admission_owner;
-- SELECT ... FOR SHARE in PG18 requires UPDATE privilege and an UPDATE RLS
-- policy; WITH CHECK(false) prevents the definer changing any of these rows.
GRANT UPDATE (status) ON public.portal_identities TO portal_f119_admission_owner;
GRANT UPDATE (status) ON public.portal_organizations TO portal_f119_admission_owner;
GRANT UPDATE (status) ON public.portal_memberships TO portal_f119_admission_owner;
CREATE POLICY portal_f119_identity_select
  ON public.portal_identities FOR SELECT
  TO portal_f119_admission_owner USING (true);
CREATE POLICY portal_f119_identity_lock
  ON public.portal_identities FOR UPDATE
  TO portal_f119_admission_owner USING (true) WITH CHECK (false);
CREATE POLICY portal_f119_org_select
  ON public.portal_organizations FOR SELECT
  TO portal_f119_admission_owner USING (true);
CREATE POLICY portal_f119_org_lock
  ON public.portal_organizations FOR UPDATE
  TO portal_f119_admission_owner USING (true) WITH CHECK (false);
CREATE POLICY portal_f119_member_select
  ON public.portal_memberships FOR SELECT
  TO portal_f119_admission_owner USING (true);
CREATE POLICY portal_f119_member_lock
  ON public.portal_memberships FOR UPDATE
  TO portal_f119_admission_owner USING (true) WITH CHECK (false);

CREATE FUNCTION public.portal_f119_guard_session_issuance()
RETURNS trigger
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog,pg_temp
SET row_security = on
AS $f119$
DECLARE
  v_identity_status text;
  v_identity_revision bigint;
  v_email_verified timestamptz;
  v_mfa_enrolled timestamptz;
  v_org_status text;
  v_org_revision bigint;
  v_member_status text;
  v_member_revision bigint;
  v_member_updated timestamptz;
  v_now timestamptz;
BEGIN
  IF TG_OP='DELETE' THEN
    RAISE EXCEPTION 'F119: deleting issued sessions is forbidden'
      USING ERRCODE='23514';
  END IF;

  IF TG_OP='UPDATE' THEN
    -- A token's principal, tenant, digest, purpose, revisions and clock
    -- cannot be rewritten. Revocation is the only change allowed after
    -- the membership ceases to match the saved revision.
    IF NEW.id IS DISTINCT FROM OLD.id
      OR NEW.session_sha256 IS DISTINCT FROM OLD.session_sha256
      OR NEW.identity_id IS DISTINCT FROM OLD.identity_id
      OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
      OR NEW.session_purpose IS DISTINCT FROM OLD.session_purpose
      OR NEW.identity_revision IS DISTINCT FROM OLD.identity_revision
      OR NEW.membership_revision IS DISTINCT FROM OLD.membership_revision
      OR NEW.organization_revision IS DISTINCT FROM OLD.organization_revision
      OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
      OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
      OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
      RAISE EXCEPTION 'F119: session provenance is immutable'
        USING ERRCODE='23514';
    END IF;
    IF OLD.status='revoked' THEN
      RAISE EXCEPTION 'F119: retired credentials cannot be changed'
        USING ERRCODE='23514';
    END IF;
  END IF;

  -- SAME ORDER as F117/F118: identity -> organization -> membership.
  -- Locks prevent a trusted issuer from inserting or promoting a session
  -- while F118 changes the membership and sweeps old session rows.
  SELECT i.status,i.security_revision,i.email_verified_at,i.mfa_confirmed_at
    INTO v_identity_status,v_identity_revision,v_email_verified,v_mfa_enrolled
    FROM public.portal_identities i
   WHERE i.id=NEW.identity_id FOR SHARE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F119: missing identity' USING ERRCODE='23514';
  END IF;
  SELECT o.status,o.security_revision
    INTO v_org_status,v_org_revision
    FROM public.portal_organizations o
   WHERE o.id=NEW.organization_id FOR SHARE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F119: missing company' USING ERRCODE='23514';
  END IF;
  SELECT m.status,m.membership_revision,m.updated_at
    INTO v_member_status,v_member_revision,v_member_updated
    FROM public.portal_memberships m
   WHERE m.identity_id=NEW.identity_id
     AND m.organization_id=NEW.organization_id FOR SHARE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'F119: missing membership' USING ERRCODE='23514';
  END IF;

  v_now:=clock_timestamp();

  -- A legitimate activation/retirement may change the membership revision
  -- before the old credential is revoked. Permit *only* terminal revocation
  -- in that circumstance; it cannot create or upgrade an authorization.
  IF TG_OP='UPDATE' AND NEW.status='revoked'
    AND OLD.status IN ('active','pending_mfa')
    AND NEW.revoked_at IS NOT NULL
    AND NEW.mfa_verified_at IS NOT DISTINCT FROM OLD.mfa_verified_at THEN
    RETURN NEW;
  END IF;

  IF v_identity_status<>'active'
     OR v_org_status<>'active'
     OR v_email_verified IS NULL
     OR v_mfa_enrolled IS NULL
     OR NEW.identity_revision IS DISTINCT FROM v_identity_revision
     OR NEW.membership_revision IS DISTINCT FROM v_member_revision
     OR NEW.organization_revision IS DISTINCT FROM v_org_revision
     OR NEW.session_purpose IS NULL
     OR NEW.session_purpose NOT IN ('invitation_only','business')
     OR NEW.revoked_at IS NOT NULL
     OR NEW.issued_at>v_now
     OR NEW.expires_at<=v_now THEN
    RAISE EXCEPTION 'F119: session not eligible for issuance'
      USING ERRCODE='23514';
  END IF;

  IF NEW.session_purpose='invitation_only' THEN
    IF v_member_status<>'invited' THEN
      RAISE EXCEPTION 'F119: invitation-only requires invited membership'
        USING ERRCODE='23514';
    END IF;
  ELSE
    -- Business credentials can only be ISSUED AFTER membership activation.
    -- A pre-activation business bearer cannot later gain authority by a
    -- membership status change. F118 additionally retires all earlier rows.
    IF v_member_status<>'active' OR NEW.issued_at<v_member_updated THEN
      RAISE EXCEPTION 'F119: business requires fresh active membership'
        USING ERRCODE='23514';
    END IF;
  END IF;

  IF TG_OP='INSERT' THEN
    IF NEW.issued_at<v_now-interval '5 minutes' THEN
      RAISE EXCEPTION 'F119: new credential issuance is stale'
        USING ERRCODE='23514';
    END IF;
    IF NEW.status<>'pending_mfa' OR NEW.mfa_verified_at IS NOT NULL THEN
      RAISE EXCEPTION 'F119: new sessions must pass through MFA'
        USING ERRCODE='23514';
    END IF;
  ELSIF TG_OP='UPDATE' THEN
    IF NEW.status='active' AND OLD.status='pending_mfa' THEN
      IF NEW.mfa_verified_at IS NULL
        OR NEW.mfa_verified_at<NEW.issued_at
        OR NEW.mfa_verified_at>v_now
        OR NEW.mfa_verified_at<v_now-interval '5 minutes' THEN
        RAISE EXCEPTION 'F119: fresh MFA is required'
          USING ERRCODE='23514';
      END IF;
    ELSIF NEW.status IS DISTINCT FROM OLD.status
      OR NEW.mfa_verified_at IS DISTINCT FROM OLD.mfa_verified_at
      OR NEW.status NOT IN ('pending_mfa','active') THEN
      RAISE EXCEPTION 'F119: invalid session transition'
        USING ERRCODE='23514';
    END IF;
  END IF;

  RETURN NEW;
END;
$f119$;
ALTER FUNCTION public.portal_f119_guard_session_issuance()
  OWNER TO portal_f119_admission_owner;
REVOKE ALL ON FUNCTION public.portal_f119_guard_session_issuance()
  FROM PUBLIC;
CREATE TRIGGER portal_f119_session_admission_guard
  BEFORE INSERT OR UPDATE OR DELETE ON public.portal_auth_sessions
  FOR EACH ROW EXECUTE FUNCTION public.portal_f119_guard_session_issuance();
REVOKE CREATE ON SCHEMA public FROM portal_f119_admission_owner;
COMMIT;
