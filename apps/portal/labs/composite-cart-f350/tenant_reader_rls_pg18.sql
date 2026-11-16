-- PORTAL COMPOSITE-CART-F350: POSTGRESQL 18 DISPOSABLE CI ONLY.
-- Never run in production or shared databases. Dedicated database/roles
-- are created by the isolated GitHub Action on 127.0.0.1 only.
-- This is not an operational customer cart or authorization service.
BEGIN;
CREATE SCHEMA composite_cart_f350;
REVOKE ALL ON SCHEMA composite_cart_f350 FROM PUBLIC;

CREATE TABLE composite_cart_f350.draft_quote (
 id uuid PRIMARY KEY,
 tenant_role name NOT NULL,
 catalog_version char(64) NOT NULL,
 selection_hash char(64) NOT NULL,
 server_quote jsonb NOT NULL,
 quote_revision integer NOT NULL DEFAULT 1 CHECK (quote_revision=1),
 created_at timestamptz NOT NULL DEFAULT now(),
 expires_at timestamptz NOT NULL,
 revoked_at timestamptz,
 CHECK (catalog_version ~ '^[0-9a-f]{64}$'),
 CHECK (selection_hash ~ '^[0-9a-f]{64}$'),
 CHECK (tenant_role IN ('f350_tenant_a_reader','f350_tenant_b_reader')),
 CHECK (jsonb_typeof(server_quote)='object'),
 CHECK (server_quote->>'contract_version'='portal-composite-quote-v5-lab-v1'),
 CHECK (server_quote->>'public_checkout_authorized'='false'),
 CHECK (server_quote->>'real_charge_enabled'='false'),
 CHECK (server_quote->>'contract_accepted'='false'),
 CHECK (server_quote->>'customer_or_tenant_data_captured'='false'),
 CHECK (server_quote->>'payment_schedule_created'='false'),
 CHECK (server_quote->>'catalog_version'=catalog_version)
);
ALTER TABLE composite_cart_f350.draft_quote ENABLE ROW LEVEL SECURITY;
ALTER TABLE composite_cart_f350.draft_quote FORCE ROW LEVEL SECURITY;

-- One policy per immutable database login. No caller-supplied tenant UUID,
-- no mutable custom GUC, no session variables, no shared service login.
-- Neither login may inherit other roles or bypass RLS.
CREATE POLICY f350_tenant_self_read
 ON composite_cart_f350.draft_quote FOR SELECT
 TO f350_tenant_a_reader,f350_tenant_b_reader
 USING (
   tenant_role=current_user::name
   AND revoked_at IS NULL
   AND expires_at>statement_timestamp()
 );
GRANT USAGE ON SCHEMA composite_cart_f350
 TO f350_tenant_a_reader,f350_tenant_b_reader;
GRANT SELECT (id,tenant_role,catalog_version,selection_hash,server_quote,
 quote_revision,created_at,expires_at,revoked_at)
 ON composite_cart_f350.draft_quote
 TO f350_tenant_a_reader,f350_tenant_b_reader;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA composite_cart_f350 FROM PUBLIC;
COMMIT;
