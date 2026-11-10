-- PORTAL-F103: DISPOSABLE PG18 CI ONLY.
-- F102's dedicated NOLOGIN definer still has BYPASSRLS in the no-policy lab.
-- Reduce its six table-wide SELECT grants to specific columns REQUIRED by
-- the F76/F101 predicates. Password hashes and MFA ciphertext must never be
-- readable even by the restricted SECURITY DEFINER owner.
BEGIN;

REVOKE SELECT ON TABLE
    public.portal_identities,
    public.portal_organizations,
    public.portal_memberships,
    public.portal_membership_grants,
    public.portal_auth_sessions,
    public.portal_f101_resource_fixtures
    FROM portal_f102_security_owner;

GRANT SELECT (id, status, email_verified_at, mfa_confirmed_at, security_revision)
    ON TABLE public.portal_identities TO portal_f102_security_owner;

GRANT SELECT (id, status, security_revision)
    ON TABLE public.portal_organizations TO portal_f102_security_owner;

GRANT SELECT (id, identity_id, organization_id, status, membership_revision)
    ON TABLE public.portal_memberships TO portal_f102_security_owner;

GRANT SELECT (membership_id, permission_code)
    ON TABLE public.portal_membership_grants TO portal_f102_security_owner;

GRANT SELECT (
    session_sha256, identity_id, organization_id, status,
    revoked_at, issued_at, expires_at, mfa_verified_at,
    identity_revision, membership_revision, organization_revision
)
    ON TABLE public.portal_auth_sessions TO portal_f102_security_owner;

GRANT SELECT (id, organization_id, status)
    ON TABLE public.portal_f101_resource_fixtures
    TO portal_f102_security_owner;

COMMIT;
