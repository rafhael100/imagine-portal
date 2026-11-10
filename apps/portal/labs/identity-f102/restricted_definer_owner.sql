-- PORTAL-F102: Disposable PostgreSQL 18 CI ONLY.
-- Narrow both F76 and F101 SECURITY DEFINER owners from the postgres
-- superuser to one non-login, non-superuser, BYPASSRLS role. BYPASSRLS is
-- unavoidable in this closed/FORCE-RLS/no-policy lab, and remains sensitive.
-- Never install on a customer's or persistent database.
BEGIN;

CREATE ROLE portal_f102_security_owner
    NOLOGIN NOSUPERUSER NOCREATEROLE NOCREATEDB NOINHERIT BYPASSRLS;

-- PostgreSQL function ownership transfer may require CREATE on the schema.
-- Grant it only transiently inside this transaction, and REVOKE before COMMIT.
GRANT USAGE, CREATE ON SCHEMA public TO portal_f102_security_owner;
GRANT SELECT ON TABLE
    public.portal_identities,
    public.portal_organizations,
    public.portal_memberships,
    public.portal_membership_grants,
    public.portal_auth_sessions,
    public.portal_f101_resource_fixtures
    TO portal_f102_security_owner;

ALTER FUNCTION public.portal_f76_permission_check(text, uuid, text)
    OWNER TO portal_f102_security_owner;
ALTER FUNCTION public.portal_f101_resource_check(text, uuid, uuid, text)
    OWNER TO portal_f102_security_owner;

REVOKE CREATE ON SCHEMA public FROM portal_f102_security_owner;

-- Ownership transfer must preserve the deliberate EXECUTE-only ACL.
-- F101's reader NEVER gets EXECUTE on the lower-level F76 predicate.
REVOKE ALL ON FUNCTION public.portal_f76_permission_check(text, uuid, text)
    FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_f101_resource_check(text, uuid, uuid, text)
    FROM PUBLIC;

GRANT EXECUTE ON FUNCTION public.portal_f76_permission_check(text, uuid, text)
    TO portal_f76_reader;
GRANT EXECUTE ON FUNCTION public.portal_f101_resource_check(text, uuid, uuid, text)
    TO portal_f101_reader;

COMMIT;
