-- F138: Run MANUALLY as the migration owner AFTER reviewed migrations.
-- The :app_role variable comes from the trusted operator, never HTTP.
-- No admin bootstrap, OAuth key issuance, GSC grants or customer access.

\set ON_ERROR_STOP on
BEGIN;

-- Runtime reads the admin account and its organization grants. It can
-- advance only the TOTP replay counter, not edit credentials/session version.
SELECT format(
    'GRANT SELECT ON TABLE public.portal_admin_users TO %I',
    :'app_role'
) \gexec
SELECT format(
    'GRANT UPDATE(last_totp_counter,updated_at) ON TABLE public.portal_admin_users TO %I',
    :'app_role'
) \gexec
SELECT format(
    'GRANT SELECT ON TABLE public.portal_admin_org_grants TO %I',
    :'app_role'
) \gexec

-- Laravel encrypted database sessions and shared DB rate limiter need
-- narrowly selected CRUD, but no table DDL or unrestricted admin UPDATE.
SELECT format(
    'GRANT SELECT,INSERT,UPDATE,DELETE ON TABLE public.sessions TO %I',
    :'app_role'
) \gexec
SELECT format(
    'GRANT SELECT,INSERT,UPDATE,DELETE ON TABLE public.cache TO %I',
    :'app_role'
) \gexec
SELECT format(
    'GRANT SELECT,INSERT,UPDATE,DELETE ON TABLE public.cache_locks TO %I',
    :'app_role'
) \gexec

COMMIT;
