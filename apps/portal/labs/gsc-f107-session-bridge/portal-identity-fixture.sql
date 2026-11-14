-- F107: first-party session evidence schema for disposable PostgreSQL only.
-- The identity reader SQL is subsequently loaded UNCHANGED from a pinned
-- Portal identity branch. This fixture is NOT a migration or deployment.
\set ON_ERROR_STOP on
BEGIN;
CREATE ROLE portal_browser_owner NOLOGIN NOSUPERUSER NOCREATEDB
 NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_browser_runtime LOGIN PASSWORD 'f107_local_only'
 NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE imagine_gsc_ci_test TO portal_browser_runtime;
GRANT USAGE ON SCHEMA public TO portal_browser_owner,portal_browser_runtime;
GRANT CREATE ON SCHEMA public TO portal_browser_owner;

CREATE TABLE public.portal_identities (
 id uuid PRIMARY KEY,
 status text NOT NULL,
 security_revision bigint NOT NULL,
 email_verified_at timestamptz,
 mfa_confirmed_at timestamptz
);
CREATE TABLE public.portal_organizations (
 id uuid PRIMARY KEY, status text NOT NULL,
 security_revision bigint NOT NULL
);
CREATE TABLE public.portal_memberships (
 id uuid PRIMARY KEY,
 identity_id uuid NOT NULL, organization_id uuid NOT NULL,
 status text NOT NULL, membership_revision bigint NOT NULL,
 UNIQUE(identity_id,organization_id)
);
CREATE TABLE public.portal_auth_sessions (
 session_sha256 text PRIMARY KEY,
 identity_id uuid NOT NULL,organization_id uuid NOT NULL,
 status text NOT NULL,session_purpose text NOT NULL,
 revoked_at timestamptz,issued_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,mfa_verified_at timestamptz,
 identity_revision bigint NOT NULL,membership_revision bigint NOT NULL,
 organization_revision bigint NOT NULL
);
ALTER TABLE public.portal_identities ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_identities FORCE ROW LEVEL SECURITY;
ALTER TABLE public.portal_organizations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_organizations FORCE ROW LEVEL SECURITY;
ALTER TABLE public.portal_memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_memberships FORCE ROW LEVEL SECURITY;
ALTER TABLE public.portal_auth_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_auth_sessions FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_identities,public.portal_organizations,
 public.portal_memberships,public.portal_auth_sessions FROM PUBLIC;
GRANT SELECT(id,status,security_revision,email_verified_at,mfa_confirmed_at)
 ON public.portal_identities TO portal_browser_owner;
GRANT SELECT(id,status,security_revision)
 ON public.portal_organizations TO portal_browser_owner;
GRANT SELECT(id,identity_id,organization_id,status,membership_revision)
 ON public.portal_memberships TO portal_browser_owner;
GRANT SELECT(session_sha256,identity_id,organization_id,status,
 session_purpose,revoked_at,issued_at,expires_at,mfa_verified_at,
 identity_revision,membership_revision,organization_revision)
 ON public.portal_auth_sessions TO portal_browser_owner;
CREATE POLICY f107_identity_read ON public.portal_identities
 FOR SELECT TO portal_browser_owner USING(true);
CREATE POLICY f107_org_read ON public.portal_organizations
 FOR SELECT TO portal_browser_owner USING(true);
CREATE POLICY f107_member_read ON public.portal_memberships
 FOR SELECT TO portal_browser_owner USING(true);
CREATE POLICY f107_session_read ON public.portal_auth_sessions
 FOR SELECT TO portal_browser_owner USING(true);
COMMIT;
