-- F29 — PostgreSQL 18 ephemeral CI only. NEVER apply to an existing DB.
-- PASSWORD must be a freshly generated CI value (not committed).
-- The connection logs in as a real non-superuser, then SET LOCAL ROLE.
\set ON_ERROR_STOP on
\if :{?f29_password}
\else
  \echo 'F29: missing ephemeral role password'
  \quit 4
\endif

BEGIN;
SELECT format(
  'CREATE ROLE commercial_lab_receipt_login LOGIN NOINHERIT NOBYPASSRLS
   NOCREATEROLE NOCREATEDB NOREPLICATION CONNECTION LIMIT 2 PASSWORD %L',
  :'f29_password'
) \gexec

GRANT commercial_lab_receipt_consumer TO commercial_lab_receipt_login;
GRANT CONNECT ON DATABASE commercial_e2e_lab TO commercial_lab_receipt_login;
ALTER ROLE commercial_lab_receipt_login SET search_path=pg_catalog;

-- The login has no grants to the tables and cannot inherit EXECUTE without
-- SET ROLE to its explicitly authorized, NOLOGIN service role.
DO $assert$
DECLARE
  direct_read boolean;
  direct_write boolean;
  inherited_wrapper boolean;
  allowed_to_set boolean;
BEGIN
  SELECT has_table_privilege(
    'commercial_lab_receipt_login','commercial_publishing_lab.delivery_leases','SELECT'
  ) INTO direct_read;
  SELECT has_table_privilege(
    'commercial_lab_receipt_login','commercial_publishing_lab.delivery_leases','UPDATE'
  ) INTO direct_write;
  SELECT has_function_privilege(
    'commercial_lab_receipt_login',
    'commercial_publishing_lab.read_current_delivery_scope(text,text)','EXECUTE'
  ) INTO inherited_wrapper;
  IF direct_read OR direct_write OR inherited_wrapper THEN
    RAISE EXCEPTION 'F29: login inherited excessive privileges';
  END IF;
  SELECT pg_has_role(
    'commercial_lab_receipt_login','commercial_lab_receipt_consumer','SET'
  ) INTO allowed_to_set;
  IF allowed_to_set IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'F29: login cannot assume restricted role';
  END IF;
END $assert$;
COMMIT;
