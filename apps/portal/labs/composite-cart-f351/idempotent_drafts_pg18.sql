-- F351: isolated extension of the F350 DISPOSABLE PG18 LAB ONLY.
-- Never run as an application migration or on shared/production databases.
BEGIN;
ALTER TABLE composite_cart_f350.draft_quote
 ADD COLUMN request_hash char(64),
 ADD COLUMN quote_digest char(64),
 ADD CONSTRAINT f351_pair_guard CHECK (
  (request_hash IS NULL AND quote_digest IS NULL) OR
  (request_hash ~ '^[0-9a-f]{64}$' AND quote_digest ~ '^[0-9a-f]{64}$')
 );
-- Scoped by actual database tenant role, NOT arbitrary tenant identifiers.
-- Unique even when the previous draft was revoked or expired.
CREATE UNIQUE INDEX f351_one_draft_per_tenant_request
 ON composite_cart_f350.draft_quote (tenant_role,request_hash)
 WHERE request_hash IS NOT NULL;
-- Existing tenant readers intentionally retain only their previously
-- reviewed F350 column permissions. New hashes are internal-only.
REVOKE ALL (request_hash,quote_digest)
 ON composite_cart_f350.draft_quote
 FROM f350_tenant_a_reader,f350_tenant_b_reader;
COMMIT;
