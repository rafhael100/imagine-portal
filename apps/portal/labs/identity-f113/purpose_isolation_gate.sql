-- PORTAL-F113: POSTGRESQL 18 DISPOSABLE CI ONLY.
-- Only a newly issued purpose='invitation_only' credential may be used by
-- the F112 identity preflight. Business access needs purpose='business'.
-- Neither function issues a customer session, and direct lower-level F76
-- execution is revoked from its original reader when this proof is enabled.
BEGIN;

-- Tighten the existing F112 evidence reader WITHOUT changing its API or
-- returning sensitive session data. FORCE RLS enforces the row predicate.
GRANT SELECT (session_purpose)
  ON public.portal_auth_sessions TO portal_f112_owner;
ALTER POLICY portal_f112_session_read ON public.portal_auth_sessions
  USING (session_purpose = 'invitation_only');

CREATE ROLE portal_f113_business_owner
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE, CREATE ON SCHEMA public TO portal_f113_business_owner;
GRANT SELECT (session_sha256,session_purpose)
  ON public.portal_auth_sessions TO portal_f113_business_owner;
CREATE POLICY portal_f113_business_only_read
  ON public.portal_auth_sessions
  FOR SELECT TO portal_f113_business_owner
  USING (session_purpose = 'business');
GRANT EXECUTE ON FUNCTION public.portal_f76_permission_check(text,uuid,text)
  TO portal_f113_business_owner;

CREATE FUNCTION public.portal_f113_business_permission_check(
  p_digest text, p_organization uuid, p_permission text
) RETURNS boolean
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
SET row_security = on
AS $f113$
  SELECT CASE WHEN p_digest ~ '^[a-f0-9]{64}$'
  THEN EXISTS (
    SELECT 1 FROM public.portal_auth_sessions AS session
    WHERE session.session_sha256 = p_digest
      AND session.session_purpose = 'business'
  ) AND public.portal_f76_permission_check(
    p_digest,p_organization,p_permission
  ) ELSE FALSE END;
$f113$;

ALTER FUNCTION public.portal_f113_business_permission_check(text,uuid,text)
  OWNER TO portal_f113_business_owner;
REVOKE ALL ON FUNCTION
  public.portal_f113_business_permission_check(text,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
  public.portal_f113_business_permission_check(text,uuid,text)
  TO portal_f76_reader;

-- No business reader may bypass the purpose-aware gate to call F76.
REVOKE EXECUTE ON FUNCTION public.portal_f76_permission_check(text,uuid,text)
  FROM portal_f76_reader;
REVOKE CREATE ON SCHEMA public FROM portal_f113_business_owner;

COMMIT;
