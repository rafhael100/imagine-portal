-- CI-ONLY. Depends on F66/F76/F113 migrations and F76 permission check.
-- Not installed by normal Laravel migrations; NO PROD DEPLOY.
-- Authenticates with actual Portal-shaped PG18 session, MFA, current identity,
-- organization and membership revisions via Portal's F76 predicate, then
-- requires an independently DB-owned agreement/tenant/plan/terms revision.
--
-- The session SHA256 digest is BEARER-EQUIVALENT. This function is restricted
-- to ONE local nonprivileged test login and is NEVER exposed as HTTP/RPC.
BEGIN;

CREATE ROLE portal_v7_lab_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE ON SCHEMA public TO portal_v7_lab_owner;
GRANT SELECT (
    session_sha256,organization_id,session_purpose,status,revoked_at,
    issued_at,expires_at,mfa_verified_at
) ON public.portal_auth_sessions TO portal_v7_lab_owner;
CREATE POLICY portal_v7_lab_business_session_read
    ON public.portal_auth_sessions
    FOR SELECT TO portal_v7_lab_owner
    USING (session_purpose='business');
GRANT EXECUTE ON FUNCTION public.portal_f76_permission_check(text,uuid,text)
    TO portal_v7_lab_owner;

CREATE TABLE public.portal_v7_agreement_authority_lab (
    tenant_id text NOT NULL CHECK (tenant_id ~ '^tenant_[A-Za-z0-9_-]{8,64}$'),
    agreement_id text NOT NULL CHECK (agreement_id ~ '^agreement_[A-Za-z0-9_-]{8,64}$'),
    period text NOT NULL CHECK (period ~ '^20[0-9]{2}-(0[1-9]|1[0-2])$'),
    organization_id uuid NOT NULL REFERENCES public.portal_organizations(id),
    state text NOT NULL CHECK (state IN ('synthetic_accepted','synthetic_revoked')),
    approval_revision bigint NOT NULL CHECK (approval_revision > 0),
    plan_sha256 text NOT NULL CHECK (plan_sha256 ~ '^[a-f0-9]{64}$'),
    terms_sha256 text NOT NULL CHECK (terms_sha256 ~ '^[a-f0-9]{64}$'),
    full_month_reviewed boolean NOT NULL DEFAULT false,
    PRIMARY KEY (tenant_id,agreement_id,period),
    UNIQUE (agreement_id,period)
);

ALTER TABLE public.portal_v7_agreement_authority_lab ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_v7_agreement_authority_lab FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_v7_agreement_authority_lab FROM PUBLIC;
GRANT SELECT ON public.portal_v7_agreement_authority_lab TO portal_v7_lab_owner;
CREATE POLICY portal_v7_agreement_owner_read
    ON public.portal_v7_agreement_authority_lab
    FOR SELECT TO portal_v7_lab_owner
    USING (true);

-- The SQL login does NOT receive SELECT on identity, membership or agreement.
-- Every call obtains session context only from the stored SHA256 and the
-- agreement's server-owned organization row, NOT a browser-supplied tenant.
CREATE FUNCTION public.portal_v7_agreement_preflight_lab(
    p_session_hash text,
    p_tenant text,
    p_agreement text,
    p_period text,
    p_plan_sha256 text,
    p_terms_sha256 text,
    p_expected_revision bigint
)
RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $v7$
    SELECT CASE WHEN
        session_user = 'portal_v7_lab_reader'
        AND p_session_hash ~ '^[a-f0-9]{64}$'
        AND p_tenant ~ '^tenant_[A-Za-z0-9_-]{8,64}$'
        AND p_agreement ~ '^agreement_[A-Za-z0-9_-]{8,64}$'
        AND p_period ~ '^20[0-9]{2}-(0[1-9]|1[0-2])$'
        AND p_plan_sha256 ~ '^[a-f0-9]{64}$'
        AND p_terms_sha256 ~ '^[a-f0-9]{64}$'
        AND p_expected_revision > 0
    THEN EXISTS (
        SELECT 1
        FROM public.portal_v7_agreement_authority_lab a
        JOIN public.portal_auth_sessions s
          ON s.organization_id=a.organization_id
        WHERE s.session_sha256 = p_session_hash
          AND s.session_purpose = 'business'
          AND s.status = 'active'
          AND s.revoked_at IS NULL
          AND s.issued_at <= clock_timestamp()
          AND s.expires_at > clock_timestamp()
          AND s.expires_at <= s.issued_at + interval '2 hours'
          AND s.mfa_verified_at >= s.issued_at
          AND s.mfa_verified_at <= clock_timestamp()
          AND s.mfa_verified_at > clock_timestamp() - interval '10 minutes'
          AND a.tenant_id = p_tenant
          AND a.agreement_id = p_agreement
          AND a.period = p_period
          AND a.plan_sha256 = p_plan_sha256
          AND a.terms_sha256 = p_terms_sha256
          AND a.approval_revision = p_expected_revision
          AND a.full_month_reviewed
          AND a.state = 'synthetic_accepted'
          AND public.portal_f76_permission_check(
                p_session_hash,a.organization_id,'services.manage'
          )
    ) ELSE false END;
$v7$;

ALTER FUNCTION public.portal_v7_agreement_preflight_lab(
    text,text,text,text,text,text,bigint
) OWNER TO portal_v7_lab_owner;
REVOKE ALL ON FUNCTION public.portal_v7_agreement_preflight_lab(
    text,text,text,text,text,text,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_v7_agreement_preflight_lab(
    text,text,text,text,text,text,bigint
) TO portal_v7_lab_reader;
COMMIT;
