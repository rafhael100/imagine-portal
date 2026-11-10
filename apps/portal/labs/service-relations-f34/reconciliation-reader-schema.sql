-- PORTAL-F34: restricted ledger-reader for explicit CI-only replay.
-- Run AFTER F31 and F33 schemas on the disposable WITNESS container only.
-- No scheduled task, customer data, real queue or production authority.
BEGIN;
CREATE ROLE f34_reconciliation_reader LOGIN NOINHERIT;
GRANT USAGE ON SCHEMA f33 TO f34_reconciliation_reader;

-- Existing F33 writer role has only EXECUTE on a constrained function.
-- Only this new role may SELECT fictitious ledger entries.
ALTER TABLE f33.publication_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE f33.publication_ledger FORCE ROW LEVEL SECURITY;
CREATE POLICY f34_reader_ledger ON f33.publication_ledger
  FOR SELECT TO f34_reconciliation_reader
  USING (session_user = 'f34_reconciliation_reader');

GRANT SELECT ON f33.publication_ledger TO f34_reconciliation_reader;
REVOKE ALL ON f33.publication_ledger FROM PUBLIC;
COMMIT;
