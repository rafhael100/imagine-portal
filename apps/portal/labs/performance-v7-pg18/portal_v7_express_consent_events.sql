-- F830: disposable PG18 CI-only. Depends on F829 test-owned SQL authority
-- and real-shaped F66/F76/F113 Portal session/permission schema.
-- Demonstrates an explicit Boolean acceptance/revocation transition whose
-- ACTOR is extracted from the verified Portal SQL session, not supplied by
-- any client parameter. No HTTP endpoint, no actual customer assent, no GO.
BEGIN;

GRANT SELECT(identity_id) ON public.portal_auth_sessions
    TO portal_v7_lab_owner;

CREATE TABLE public.portal_v7_consent_events_lab (
    tenant_id text NOT NULL CHECK (tenant_id ~ '^tenant_[A-Za-z0-9_-]{8,64}$'),
    agreement_id text NOT NULL CHECK (agreement_id ~ '^agreement_[A-Za-z0-9_-]{8,64}$'),
    period text NOT NULL CHECK (period ~ '^20[0-9]{2}-(0[1-9]|1[0-2])$'),
    event_revision bigint NOT NULL CHECK (event_revision > 0),
    state text NOT NULL CHECK (state IN ('synthetic_accepted','synthetic_revoked')),
    plan_sha256 text NOT NULL CHECK (plan_sha256 ~ '^[a-f0-9]{64}$'),
    terms_sha256 text NOT NULL CHECK (terms_sha256 ~ '^[a-f0-9]{64}$'),
    actor_id uuid NOT NULL,
    organization_id uuid NOT NULL,
    occurred_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(tenant_id,agreement_id,period,event_revision)
);
ALTER TABLE public.portal_v7_consent_events_lab ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_v7_consent_events_lab FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_v7_consent_events_lab FROM PUBLIC;
GRANT SELECT,INSERT ON public.portal_v7_consent_events_lab TO portal_v7_lab_owner;
CREATE POLICY portal_v7_consent_internal_read
    ON public.portal_v7_consent_events_lab
    FOR SELECT TO portal_v7_lab_owner
    USING (true);
CREATE POLICY portal_v7_consent_internal_append
    ON public.portal_v7_consent_events_lab
    FOR INSERT TO portal_v7_lab_owner
    WITH CHECK (true);

-- Must be called only inside CLI testing adapter with an opaque Portal bearer
-- derived from an actual first-party session. The hash is bearer-equivalent;
-- DO NOT expose this function in any HTTP/RPC/database endpoint.
CREATE FUNCTION public.portal_v7_consent_record_lab(
    p_session_hash text,
    p_tenant text,
    p_agreement text,
    p_period text,
    p_plan_sha256 text,
    p_terms_sha256 text,
    p_agreement_revision bigint,
    p_expected_event_revision bigint,
    p_action text,
    p_express boolean
) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $v7$
DECLARE
    v_actor uuid;
    v_organization uuid;
    v_current_revision bigint;
    v_current_state text;
    v_new_revision bigint;
BEGIN
    IF session_user <> 'portal_v7_lab_reader'
       OR p_action NOT IN ('synthetic_accepted','synthetic_revoked')
       OR p_express IS DISTINCT FROM true
       OR p_expected_event_revision IS NULL
       OR p_expected_event_revision < 0
       OR p_agreement_revision IS NULL
       OR p_agreement_revision < 1
       OR p_session_hash IS NULL
       OR p_session_hash !~ '^[a-f0-9]{64}$'
    THEN RETURN 0; END IF;

    -- Check the latest independent Portal MFA/business session, services
    -- management permission and agreement owner/terms/plan before any write.
    IF public.portal_v7_agreement_preflight_lab(
        p_session_hash,p_tenant,p_agreement,p_period,
        p_plan_sha256,p_terms_sha256,p_agreement_revision
    ) IS DISTINCT FROM true THEN RETURN 0; END IF;

    -- PostgreSQL transaction-scoped lock serializes double-click and
    -- accept/revoke races for the same independent agreement and period.
    PERFORM pg_advisory_xact_lock(hashtextextended(
        'imagine:portal:v7:consent:'
        ||p_tenant||':'||p_agreement||':'||p_period,0
    ));

    SELECT s.identity_id,s.organization_id
      INTO v_actor,v_organization
      FROM public.portal_auth_sessions s
     WHERE s.session_sha256=p_session_hash
       AND s.session_purpose='business'
       AND s.status='active'
       AND s.revoked_at IS NULL
       AND s.expires_at>clock_timestamp()
       AND s.mfa_verified_at>clock_timestamp()-interval '10 minutes'
     LIMIT 1;
    IF v_actor IS NULL OR v_organization IS NULL THEN RETURN 0; END IF;

    SELECT e.event_revision,e.state
      INTO v_current_revision,v_current_state
      FROM public.portal_v7_consent_events_lab e
     WHERE e.tenant_id=p_tenant AND e.agreement_id=p_agreement
       AND e.period=p_period
     ORDER BY e.event_revision DESC LIMIT 1;
    IF COALESCE(v_current_revision,0)<>p_expected_event_revision THEN RETURN 0; END IF;

    IF (p_action='synthetic_accepted' AND v_current_state='synthetic_accepted')
       OR (p_action='synthetic_revoked' AND v_current_state IS DISTINCT FROM 'synthetic_accepted')
    THEN RETURN 0; END IF;

    -- The admin-seeded agreement row is the ONLY source of organization,
    -- terms, media-plan hash and revision. The caller cannot assert actor_id.
    INSERT INTO public.portal_v7_consent_events_lab(
        tenant_id,agreement_id,period,event_revision,state,
        plan_sha256,terms_sha256,actor_id,organization_id
    )
    SELECT a.tenant_id,a.agreement_id,a.period,
           p_expected_event_revision+1,p_action,a.plan_sha256,a.terms_sha256,
           v_actor,a.organization_id
      FROM public.portal_v7_agreement_authority_lab a
     WHERE a.tenant_id=p_tenant AND a.agreement_id=p_agreement
       AND a.period=p_period
       AND a.organization_id=v_organization
       AND a.approval_revision=p_agreement_revision
       AND a.plan_sha256=p_plan_sha256 AND a.terms_sha256=p_terms_sha256
       AND a.state='synthetic_accepted' AND a.full_month_reviewed
    RETURNING event_revision INTO v_new_revision;
    RETURN COALESCE(v_new_revision,0);
END;
$v7$;

CREATE FUNCTION public.portal_v7_consent_current_lab(
    p_session_hash text,
    p_tenant text,
    p_agreement text,
    p_period text,
    p_plan_sha256 text,
    p_terms_sha256 text,
    p_agreement_revision bigint,
    p_expected_event_revision bigint
) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $v7$
    SELECT CASE WHEN
        session_user='portal_v7_lab_reader'
        AND p_expected_event_revision IS NOT NULL
        AND p_expected_event_revision>0
        AND public.portal_v7_agreement_preflight_lab(
            p_session_hash,p_tenant,p_agreement,p_period,
            p_plan_sha256,p_terms_sha256,p_agreement_revision
        )
    THEN EXISTS (
        SELECT 1 FROM public.portal_v7_consent_events_lab e
        WHERE e.tenant_id=p_tenant AND e.agreement_id=p_agreement
          AND e.period=p_period
          AND e.event_revision=p_expected_event_revision
          AND e.state='synthetic_accepted'
          AND e.plan_sha256=p_plan_sha256
          AND e.terms_sha256=p_terms_sha256
          AND e.event_revision=(
              SELECT max(latest.event_revision)
              FROM public.portal_v7_consent_events_lab latest
              WHERE latest.tenant_id=e.tenant_id
                AND latest.agreement_id=e.agreement_id
                AND latest.period=e.period
          )
    ) ELSE false END;
$v7$;

ALTER FUNCTION public.portal_v7_consent_record_lab(
    text,text,text,text,text,text,bigint,bigint,text,boolean
) OWNER TO portal_v7_lab_owner;
ALTER FUNCTION public.portal_v7_consent_current_lab(
    text,text,text,text,text,text,bigint,bigint
) OWNER TO portal_v7_lab_owner;
REVOKE ALL ON FUNCTION public.portal_v7_consent_record_lab(
    text,text,text,text,text,text,bigint,bigint,text,boolean
) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_v7_consent_current_lab(
    text,text,text,text,text,text,bigint,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_v7_consent_record_lab(
    text,text,text,text,text,text,bigint,bigint,text,boolean
) TO portal_v7_lab_reader;
GRANT EXECUTE ON FUNCTION public.portal_v7_consent_current_lab(
    text,text,text,text,text,text,bigint,bigint
) TO portal_v7_lab_reader;
COMMIT;
