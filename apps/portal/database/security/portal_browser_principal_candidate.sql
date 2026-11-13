-- READ-ONLY CANDIDATE. Install only in disposable CI; not an activation/restore fence.
BEGIN;
CREATE FUNCTION public.portal_browser_principal_candidate(p_digest text)
RETURNS TABLE(identity_id uuid, organization_id uuid, membership_id uuid,
 identity_revision bigint, membership_revision bigint, organization_revision bigint,
 expires_at text)
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
 SELECT i.id, o.id, m.id, i.security_revision::bigint,
        m.membership_revision::bigint, o.security_revision::bigint, s.expires_at::text
 FROM public.portal_auth_sessions s
 JOIN public.portal_identities i ON i.id=s.identity_id
 JOIN public.portal_memberships m ON m.identity_id=i.id AND m.organization_id=s.organization_id
 JOIN public.portal_organizations o ON o.id=s.organization_id
 WHERE p_digest ~ '^[a-f0-9]{64}$' AND s.session_sha256=p_digest
 AND s.status='active' AND s.session_purpose='business' AND s.revoked_at IS NULL
 AND s.issued_at<=clock_timestamp() AND s.expires_at>clock_timestamp()
 AND s.expires_at<=s.issued_at+interval '2 hours'
 AND s.mfa_verified_at>=s.issued_at AND s.mfa_verified_at<=clock_timestamp()
 AND i.status='active' AND m.status='active' AND o.status='active'
 AND i.email_verified_at<=clock_timestamp() AND i.mfa_confirmed_at<=clock_timestamp()
 AND s.identity_revision=i.security_revision
 AND s.membership_revision=m.membership_revision
 AND s.organization_revision=o.security_revision;
$fn$;
ALTER FUNCTION public.portal_browser_principal_candidate(text) OWNER TO portal_browser_owner;
REVOKE ALL ON FUNCTION public.portal_browser_principal_candidate(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_browser_principal_candidate(text) TO portal_browser_runtime;
COMMIT;
