-- PORTAL-F104 / PostgreSQL 18 DISPOSABLE CI ONLY.
-- Replace the F102 owner's BYPASSRLS with SELECT policies that apply only
-- to the dedicated NOLOGIN function owner. F103 column grants still restrict
-- what the owner can inspect. NO customer data, production migrations, or API.
--
-- A broad USING(true) policy is only acceptable here because:
--   (1) its sole grantee is the NOLOGIN definer role,
--   (2) the role has narrowly scoped column SELECT grants,
--   (3) callers do not inherit the owner or have direct table access,
--   (4) F76/F101 validate every session, organization, grant and resource.
-- Not a production RLS design: real per-tenant policies and independent DBA
-- review are required before any operational release.
BEGIN;

CREATE POLICY portal_f104_definer_read
  ON public.portal_identities
  FOR SELECT TO portal_f102_security_owner USING (true);

CREATE POLICY portal_f104_definer_read
  ON public.portal_organizations
  FOR SELECT TO portal_f102_security_owner USING (true);

CREATE POLICY portal_f104_definer_read
  ON public.portal_memberships
  FOR SELECT TO portal_f102_security_owner USING (true);

CREATE POLICY portal_f104_definer_read
  ON public.portal_membership_grants
  FOR SELECT TO portal_f102_security_owner USING (true);

CREATE POLICY portal_f104_definer_read
  ON public.portal_auth_sessions
  FOR SELECT TO portal_f102_security_owner USING (true);

CREATE POLICY portal_f104_definer_read
  ON public.portal_f101_resource_fixtures
  FOR SELECT TO portal_f102_security_owner USING (true);

-- PostgreSQL applies FORCE RLS to the definer from this point onward.
-- The dedicated role must NEVER retain blanket BYPASSRLS access.
ALTER ROLE portal_f102_security_owner NOBYPASSRLS;

-- Force RLS to remain enabled within nested SECURITY DEFINER function calls,
-- irrespective of external connection GUC settings.
ALTER FUNCTION public.portal_f76_permission_check(text,uuid,text)
  SET row_security = on;
ALTER FUNCTION public.portal_f101_resource_check(text,uuid,uuid,text)
  SET row_security = on;

COMMIT;
