-- PORTAL-F83: DISPOSABLE LABORATORY ONLY.
-- A trusted migration role installs SQL, but its SECURITY DEFINER owner is
-- NOLOGIN non-superuser with RLS SELECT policies. A reader has EXECUTE only.
-- There is NO
-- application-facing route, token issuer, production grant, or RLS allow policy.
--
-- The session hash parameter is BEARER-EQUIVALENT! Never expose it to a browser,
-- client, error log, analytics event, or externally callable RPC. Real runtime
-- requires a verified first-party identity origin, protected cookie/session
-- handler, rate limits, rotation, and independent AppSec/DBA review.
BEGIN;

-- Purpose-built NOLOGIN NOINHERIT role cannot be a connection identity
-- and cannot BYPASS RLS. Only the trusted CI migration role grants its
-- SELECT privilege for five identity tables, further scoped by policy.
CREATE ROLE portal_f83_authority_owner NOLOGIN NOINHERIT NOSUPERUSER
    NOCREATEDB NOCREATEROLE NOBYPASSRLS;
GRANT SELECT ON TABLE
    public.portal_identities,
    public.portal_organizations,
    public.portal_memberships,
    public.portal_membership_grants,
    public.portal_auth_sessions
TO portal_f83_authority_owner;
CREATE POLICY portal_f83_owner_select ON public.portal_identities
    AS PERMISSIVE FOR SELECT TO portal_f83_authority_owner USING (true);
CREATE POLICY portal_f83_owner_select ON public.portal_organizations
    AS PERMISSIVE FOR SELECT TO portal_f83_authority_owner USING (true);
CREATE POLICY portal_f83_owner_select ON public.portal_memberships
    AS PERMISSIVE FOR SELECT TO portal_f83_authority_owner USING (true);
CREATE POLICY portal_f83_owner_select ON public.portal_membership_grants
    AS PERMISSIVE FOR SELECT TO portal_f83_authority_owner USING (true);
CREATE POLICY portal_f83_owner_select ON public.portal_auth_sessions
    AS PERMISSIVE FOR SELECT TO portal_f83_authority_owner USING (true);

CREATE FUNCTION public.portal_f83_permission_check(
    p_session_hash text,
    p_organization_id uuid,
    p_permission text
) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $f76$
    SELECT CASE WHEN
        p_session_hash ~ '^[a-f0-9]{64}$'
        AND p_permission IN (
            'services.view', 'services.manage', 'billing.view', 'members.manage'
        )
    THEN EXISTS (
        SELECT 1
        FROM public.portal_auth_sessions AS s
        JOIN public.portal_identities AS i ON i.id = s.identity_id
        JOIN public.portal_organizations AS o ON o.id = s.organization_id
        JOIN public.portal_memberships AS m
          ON m.identity_id = s.identity_id
         AND m.organization_id = s.organization_id
        JOIN public.portal_membership_grants AS g
          ON g.membership_id = m.id
        WHERE s.session_sha256 = p_session_hash
          AND s.organization_id = p_organization_id
          AND s.status = 'active'
          AND s.revoked_at IS NULL
          AND s.issued_at <= clock_timestamp()
          AND s.expires_at > clock_timestamp()
          AND s.expires_at <= s.issued_at + interval '1 day'
          AND s.mfa_verified_at IS NOT NULL
          AND s.mfa_verified_at <= clock_timestamp()
          AND i.status = 'active'
          AND i.email_verified_at IS NOT NULL
          AND i.mfa_confirmed_at IS NOT NULL
          AND i.security_revision = s.identity_revision
          AND o.status = 'active'
          AND s.organization_revision IS NOT NULL
          AND s.organization_revision = o.security_revision
          AND m.status = 'active'
          AND m.membership_revision = s.membership_revision
          AND g.permission_code = p_permission
    ) ELSE FALSE END;
$f76$;

-- PostgreSQL defaults grant EXECUTE on new functions to PUBLIC.
-- Do the REVOKE and narrow GRANT in the SAME transaction as CREATE.
ALTER FUNCTION public.portal_f83_permission_check(text,uuid,text)
    OWNER TO portal_f83_authority_owner;
REVOKE ALL ON FUNCTION public.portal_f83_permission_check(text,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f83_permission_check(text,uuid,text)
    TO portal_f83_reader;
COMMIT;
