-- F832: CLI/testing ONLY; disposable PG18 schema F66/F76/F113/F829/F831.
-- A short-lived database-issued receipt proves a specific commercial terms
-- digest was *registered as presented* BEFORE a new explicit acceptance.
-- No actual browser display, user action, legal consent, PSP or Ads authority.
BEGIN;

CREATE TABLE public.portal_v7_terms_presentation_lab (
  challenge uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  session_sha256 char(64) NOT NULL CHECK (session_sha256 ~ '^[a-f0-9]{64}$'),
  tenant_id text NOT NULL,
  agreement_id text NOT NULL,
  period text NOT NULL,
  organization_id uuid NOT NULL,
  actor_id uuid NOT NULL,
  agreement_revision bigint NOT NULL CHECK (agreement_revision>0),
  previous_consent_revision bigint NOT NULL CHECK (previous_consent_revision>=0),
  plan_sha256 char(64) NOT NULL CHECK (plan_sha256 ~ '^[a-f0-9]{64}$'),
  terms_sha256 char(64) NOT NULL CHECK (terms_sha256 ~ '^[a-f0-9]{64}$'),
  registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  expires_at timestamptz NOT NULL,
  CHECK (expires_at>registered_at AND expires_at<=registered_at+interval '3 minutes')
);

CREATE TABLE public.portal_v7_terms_acceptance_link_lab (
  tenant_id text NOT NULL,
  agreement_id text NOT NULL,
  period text NOT NULL,
  consent_revision bigint NOT NULL CHECK(consent_revision>0),
  presentation_challenge uuid NOT NULL UNIQUE
    REFERENCES public.portal_v7_terms_presentation_lab(challenge),
  linked_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY(tenant_id,agreement_id,period,consent_revision)
);

ALTER TABLE public.portal_v7_terms_presentation_lab ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_v7_terms_presentation_lab FORCE ROW LEVEL SECURITY;
ALTER TABLE public.portal_v7_terms_acceptance_link_lab ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_v7_terms_acceptance_link_lab FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_v7_terms_presentation_lab FROM PUBLIC;
REVOKE ALL ON public.portal_v7_terms_acceptance_link_lab FROM PUBLIC;
GRANT SELECT,INSERT ON public.portal_v7_terms_presentation_lab TO portal_v7_lab_owner;
GRANT SELECT,INSERT ON public.portal_v7_terms_acceptance_link_lab TO portal_v7_lab_owner;
CREATE POLICY portal_v7_terms_receipt_private ON public.portal_v7_terms_presentation_lab
  FOR SELECT TO portal_v7_lab_owner USING(true);
CREATE POLICY portal_v7_terms_receipt_append ON public.portal_v7_terms_presentation_lab
  FOR INSERT TO portal_v7_lab_owner WITH CHECK(true);
CREATE POLICY portal_v7_terms_link_private ON public.portal_v7_terms_acceptance_link_lab
  FOR SELECT TO portal_v7_lab_owner USING(true);
CREATE POLICY portal_v7_terms_link_append ON public.portal_v7_terms_acceptance_link_lab
  FOR INSERT TO portal_v7_lab_owner WITH CHECK(true);

CREATE FUNCTION public.portal_v7_terms_register_presentation_lab(
  p_session_hash text,p_tenant text,p_agreement text,p_period text,
  p_plan text,p_terms text,p_contract_revision bigint,p_previous_consent bigint
) RETURNS uuid
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $body$
DECLARE v_challenge uuid; v_actor uuid; v_org uuid; v_latest bigint;
BEGIN
 IF session_user<>'portal_v7_lab_reader'
    OR p_previous_consent IS NULL OR p_previous_consent<0
    OR p_session_hash IS NULL OR p_session_hash !~ '^[a-f0-9]{64}$'
    OR public.portal_v7_agreement_preflight_lab(
      p_session_hash,p_tenant,p_agreement,p_period,
      p_plan,p_terms,p_contract_revision
    ) IS DISTINCT FROM true
 THEN RETURN NULL; END IF;
 SELECT COALESCE(max(event_revision),0) INTO v_latest
   FROM public.portal_v7_consent_events_lab
  WHERE tenant_id=p_tenant AND agreement_id=p_agreement AND period=p_period;
 IF v_latest<>p_previous_consent THEN RETURN NULL; END IF;
 SELECT identity_id,organization_id INTO v_actor,v_org
   FROM public.portal_auth_sessions
  WHERE session_sha256=p_session_hash AND session_purpose='business'
    AND status='active' AND revoked_at IS NULL
  LIMIT 1;
 IF v_actor IS NULL OR v_org IS NULL THEN RETURN NULL; END IF;
 INSERT INTO public.portal_v7_terms_presentation_lab(
   session_sha256,tenant_id,agreement_id,period,organization_id,actor_id,
   agreement_revision,previous_consent_revision,plan_sha256,terms_sha256,
   expires_at
 ) VALUES (
   p_session_hash,p_tenant,p_agreement,p_period,v_org,v_actor,
   p_contract_revision,p_previous_consent,p_plan,p_terms,
   clock_timestamp()+interval '3 minutes'
 ) RETURNING challenge INTO v_challenge;
 RETURN v_challenge;
END;
$body$;

CREATE FUNCTION public.portal_v7_terms_accept_with_receipt_lab(
  p_session_hash text,p_challenge uuid,p_tenant text,p_agreement text,
  p_period text,p_plan text,p_terms text,p_contract_revision bigint,
  p_previous_consent bigint,p_confirm boolean
) RETURNS bigint
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $body$
DECLARE v_receipt record; v_event_revision bigint; v_actor uuid; v_org uuid;
BEGIN
 IF session_user<>'portal_v7_lab_reader'
    OR p_confirm IS DISTINCT FROM true OR p_challenge IS NULL
    OR p_previous_consent IS NULL OR p_previous_consent<0
    OR p_session_hash IS NULL OR p_session_hash !~ '^[a-f0-9]{64}$'
 THEN RETURN 0; END IF;
 -- Serializes accept/revoke and double-click with existing F831 lock.
 PERFORM pg_advisory_xact_lock(hashtextextended(
   'imagine:portal:v7:consent:'||
     p_tenant||':'||p_agreement||':'||p_period,0
 ));
 IF public.portal_v7_agreement_preflight_lab(
      p_session_hash,p_tenant,p_agreement,p_period,
      p_plan,p_terms,p_contract_revision
    ) IS DISTINCT FROM true THEN RETURN 0; END IF;
 SELECT identity_id,organization_id INTO v_actor,v_org
   FROM public.portal_auth_sessions
  WHERE session_sha256=p_session_hash AND session_purpose='business'
    AND status='active' AND revoked_at IS NULL
  LIMIT 1;
 IF v_actor IS NULL OR v_org IS NULL THEN RETURN 0; END IF;
 SELECT * INTO v_receipt FROM public.portal_v7_terms_presentation_lab
  WHERE challenge=p_challenge
    AND session_sha256=p_session_hash
    AND tenant_id=p_tenant AND agreement_id=p_agreement AND period=p_period
    AND actor_id=v_actor AND organization_id=v_org
    AND agreement_revision=p_contract_revision
    AND previous_consent_revision=p_previous_consent
    AND plan_sha256=p_plan AND terms_sha256=p_terms
    AND registered_at<=clock_timestamp() AND expires_at>clock_timestamp();
 IF NOT FOUND OR EXISTS (
   SELECT 1 FROM public.portal_v7_terms_acceptance_link_lab
    WHERE presentation_challenge=p_challenge
 ) THEN RETURN 0; END IF;

 -- Existing F831 function authenticates session again and appends the
 -- explicit acceptance event atomically in the SAME SQL transaction.
 v_event_revision := public.portal_v7_consent_record_lab(
   p_session_hash,p_tenant,p_agreement,p_period,
   p_plan,p_terms,p_contract_revision,p_previous_consent,
   'synthetic_accepted',true
 );
 IF v_event_revision IS NULL OR v_event_revision<>p_previous_consent+1
 THEN RETURN 0; END IF;
 INSERT INTO public.portal_v7_terms_acceptance_link_lab(
   tenant_id,agreement_id,period,consent_revision,presentation_challenge
 ) VALUES (
   p_tenant,p_agreement,p_period,v_event_revision,p_challenge
 );
 RETURN v_event_revision;
END;
$body$;

CREATE FUNCTION public.portal_v7_terms_current_with_receipt_lab(
  p_session_hash text,p_tenant text,p_agreement text,p_period text,
  p_plan text,p_terms text,p_contract_revision bigint,p_consent_revision bigint
) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $body$
 SELECT COALESCE(
   session_user='portal_v7_lab_reader'
   AND public.portal_v7_consent_current_lab(
     p_session_hash,p_tenant,p_agreement,p_period,p_plan,p_terms,
     p_contract_revision,p_consent_revision
   )
   AND EXISTS (
     SELECT 1 FROM public.portal_v7_terms_acceptance_link_lab l
     JOIN public.portal_v7_terms_presentation_lab r
       ON r.challenge=l.presentation_challenge
     WHERE l.tenant_id=p_tenant AND l.agreement_id=p_agreement
       AND l.period=p_period AND l.consent_revision=p_consent_revision
       AND r.session_sha256=p_session_hash
       AND r.tenant_id=p_tenant AND r.agreement_id=p_agreement
       AND r.period=p_period AND r.plan_sha256=p_plan
       AND r.terms_sha256=p_terms
       AND r.agreement_revision=p_contract_revision
       AND r.registered_at<=l.linked_at
       AND l.linked_at<r.expires_at
   ),false);
$body$;

ALTER FUNCTION public.portal_v7_terms_register_presentation_lab(
 text,text,text,text,text,text,bigint,bigint
) OWNER TO portal_v7_lab_owner;
ALTER FUNCTION public.portal_v7_terms_accept_with_receipt_lab(
 text,uuid,text,text,text,text,text,bigint,bigint,boolean
) OWNER TO portal_v7_lab_owner;
ALTER FUNCTION public.portal_v7_terms_current_with_receipt_lab(
 text,text,text,text,text,text,bigint,bigint
) OWNER TO portal_v7_lab_owner;
REVOKE ALL ON FUNCTION public.portal_v7_terms_register_presentation_lab(
 text,text,text,text,text,text,bigint,bigint
) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_v7_terms_accept_with_receipt_lab(
 text,uuid,text,text,text,text,text,bigint,bigint,boolean
) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_v7_terms_current_with_receipt_lab(
 text,text,text,text,text,text,bigint,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_v7_terms_register_presentation_lab(
 text,text,text,text,text,text,bigint,bigint
) TO portal_v7_lab_reader;
GRANT EXECUTE ON FUNCTION public.portal_v7_terms_accept_with_receipt_lab(
 text,uuid,text,text,text,text,text,bigint,bigint,boolean
) TO portal_v7_lab_reader;
GRANT EXECUTE ON FUNCTION public.portal_v7_terms_current_with_receipt_lab(
 text,text,text,text,text,text,bigint,bigint
) TO portal_v7_lab_reader;
COMMIT;
