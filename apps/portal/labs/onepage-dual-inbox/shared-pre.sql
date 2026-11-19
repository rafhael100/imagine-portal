-- Synthetic install fragment. Must execute only inside fixture installer's gate and transaction.
CREATE ROLE onepage_ingress_owner NOLOGIN NOSUPERUSER NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE onepage_ingress_capture LOGIN NOSUPERUSER NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE onepage_ingress_revoker LOGIN NOSUPERUSER NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
REVOKE ALL ON DATABASE portal_asaas_ci FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT CONNECT ON DATABASE portal_asaas_ci TO onepage_ingress_capture,onepage_ingress_revoker;
CREATE SCHEMA onepage_ingress_lab AUTHORIZATION portal_native_test_bootstrap;
REVOKE ALL ON SCHEMA onepage_ingress_lab FROM PUBLIC;
GRANT USAGE ON SCHEMA onepage_ingress_lab TO onepage_ingress_owner,onepage_ingress_capture,onepage_ingress_revoker;
CREATE TABLE onepage_ingress_lab.admissions (
 account_ref text NOT NULL, order_id text NOT NULL, payment_id text NOT NULL, revision text NOT NULL,
 environment text NOT NULL CHECK (environment='sandbox'), active boolean NOT NULL,
 PRIMARY KEY(account_ref,order_id), UNIQUE(account_ref,payment_id)
);
CREATE TABLE onepage_ingress_lab.inbox (
 account_ref text NOT NULL,event_id text NOT NULL,event_type text NOT NULL,payment_id text NOT NULL,
 order_id text NOT NULL,revision text NOT NULL,payload_sha256 text NOT NULL,
 received_at timestamptz NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(account_ref,event_id)
);
REVOKE ALL ON TABLE onepage_ingress_lab.admissions,onepage_ingress_lab.inbox FROM PUBLIC;
-- No admission is seeded by installation. Harness must use a verified native binding.
ALTER TABLE onepage_ingress_lab.admissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_ingress_lab.admissions FORCE ROW LEVEL SECURITY;
ALTER TABLE onepage_ingress_lab.inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_ingress_lab.inbox FORCE ROW LEVEL SECURITY;
GRANT SELECT,UPDATE(active) ON onepage_ingress_lab.admissions TO onepage_ingress_owner;
GRANT SELECT,INSERT ON onepage_ingress_lab.inbox TO onepage_ingress_owner;
CREATE POLICY admissions_owner ON onepage_ingress_lab.admissions TO onepage_ingress_owner USING (true) WITH CHECK (true);
CREATE POLICY admissions_exact ON onepage_ingress_lab.admissions AS RESTRICTIVE TO onepage_ingress_owner USING (
 account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd' AND environment='sandbox'
) WITH CHECK (
 account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd' AND environment='sandbox'
);
CREATE POLICY inbox_owner ON onepage_ingress_lab.inbox TO onepage_ingress_owner USING (true) WITH CHECK (true);
CREATE POLICY inbox_exact ON onepage_ingress_lab.inbox AS RESTRICTIVE TO onepage_ingress_owner USING (
 account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'
) WITH CHECK (
 account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'
);

-- Only the NOLOGIN definer accesses the shared queue. No worker mutation rights.
GRANT USAGE ON SCHEMA portal_psp TO onepage_ingress_owner;
GRANT SELECT,INSERT ON portal_psp.asaas_inbox TO onepage_ingress_owner;
CREATE POLICY onepage_shared_select ON portal_psp.asaas_inbox FOR SELECT TO onepage_ingress_owner
 USING (current_user='onepage_ingress_owner' AND account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'::uuid);
CREATE POLICY onepage_shared_insert ON portal_psp.asaas_inbox FOR INSERT TO onepage_ingress_owner
 WITH CHECK (current_user='onepage_ingress_owner' AND account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'::uuid);
CREATE POLICY onepage_shared_account ON portal_psp.asaas_inbox AS RESTRICTIVE TO onepage_ingress_owner
 USING (account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'::uuid)
 WITH CHECK (account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'::uuid);
