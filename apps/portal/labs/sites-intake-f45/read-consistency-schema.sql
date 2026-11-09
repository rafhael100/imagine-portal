-- PORTAL-F45: read-only editorial consistency check, NOT a publish permit.
-- Requires f44/schema.sql in disposable PG18 only.
BEGIN;
CREATE ROLE f45_consistency_reader_a LOGIN NOINHERIT;
CREATE ROLE f45_consistency_reader_b LOGIN NOINHERIT;

CREATE POLICY f45_sites_read_a ON f44.site_review
  FOR SELECT TO f45_consistency_reader_a
  USING (organization_id='org-a' AND session_user='f45_consistency_reader_a');
CREATE POLICY f45_sites_read_b ON f44.site_review
  FOR SELECT TO f45_consistency_reader_b
  USING (organization_id='org-b' AND session_user='f45_consistency_reader_b');

CREATE POLICY f45_receipts_read_a ON f44.review_receipt
  FOR SELECT TO f45_consistency_reader_a
  USING (organization_id='org-a' AND session_user='f45_consistency_reader_a');
CREATE POLICY f45_receipts_read_b ON f44.review_receipt
  FOR SELECT TO f45_consistency_reader_b
  USING (organization_id='org-b' AND session_user='f45_consistency_reader_b');

GRANT USAGE ON SCHEMA f44 TO f45_consistency_reader_a,f45_consistency_reader_b;
GRANT SELECT ON f44.site_review,f44.review_receipt
  TO f45_consistency_reader_a,f45_consistency_reader_b;
-- IMPORTANT: no UPDATE/INSERT/DELETE/DDL/EXECUTE grants for either role.
COMMIT;
