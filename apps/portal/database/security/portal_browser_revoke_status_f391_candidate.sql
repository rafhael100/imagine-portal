-- F391 REVIEW-ONLY, explicit CI installation ONLY.
-- NOT registered in Laravel migrations or VPS deployment manifest.
-- Hardened SQL status read for digest-only, signed external intent recovery.
--
-- This is not a serving authorization. It returns only an enumerated state
-- with a server-side proof HMAC, and only through portal_browser_runtime.
BEGIN;

DO $preflight$
BEGIN
  IF current_database() <> 'portal_identity_dev'
    OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='portal_browser_owner'
      AND rolcanlogin=false AND rolsuper=false AND rolbypassrls=false)
    OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='portal_browser_runtime'
      AND rolsuper=false AND rolbypassrls=false)
    OR NOT EXISTS (SELECT 1 FROM pg_class c
      JOIN pg_namespace n ON n.oid=c.relnamespace
      WHERE n.nspname='public' AND c.relname='portal_auth_sessions'
      AND c.relrowsecurity=true AND c.relforcerowsecurity=true)
    OR to_regprocedure('public.portal_browser_proof_ok(text,text)') IS NULL
    OR to_regprocedure('public.portal_browser_revoke(text,text)') IS NULL
  THEN
    RAISE EXCEPTION 'F391 disposable identity security foundation missing'
      USING ERRCODE='42501';
  END IF;
END;
$preflight$;

CREATE FUNCTION public.portal_browser_revoke_status_candidate(
    p_digest text,
    p_organization uuid,
    p_proof text
)
RETURNS text
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
SET search_path=pg_catalog,pg_temp
SET row_security=on
AS $f391$
DECLARE
  current_state text;
  current_revocation timestamptz;
BEGIN
  IF p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
    OR p_organization IS NULL
    OR NOT COALESCE(public.portal_browser_proof_ok(
       'portal-browser-v1|revoke-status|'
       ||p_organization::text||'|'||p_digest,p_proof
    ),false)
  THEN
    RETURN 'denied';
  END IF;

  SELECT s.status,s.revoked_at
    INTO current_state,current_revocation
  FROM public.portal_auth_sessions s
  WHERE s.session_sha256=p_digest
    AND s.organization_id=p_organization;

  IF NOT FOUND THEN RETURN 'missing'; END IF;

  IF current_state='revoked' AND current_revocation IS NOT NULL THEN
    RETURN 'revoked';
  END IF;
  IF current_state IN ('pending_mfa','active')
     AND current_revocation IS NULL THEN
    RETURN 'not_revoked';
  END IF;
  RETURN 'inconsistent';
END;
$f391$;

ALTER FUNCTION public.portal_browser_revoke_status_candidate(text,uuid,text)
  OWNER TO portal_browser_owner;

REVOKE ALL ON FUNCTION public.portal_browser_revoke_status_candidate(text,uuid,text)
  FROM PUBLIC;

GRANT EXECUTE ON FUNCTION public.portal_browser_revoke_status_candidate(text,uuid,text)
  TO portal_browser_runtime;

COMMIT;
