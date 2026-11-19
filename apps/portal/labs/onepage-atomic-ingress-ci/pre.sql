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
-- Single immutable case. No runtime registration/rearm/replacement/deletion.
INSERT INTO onepage_ingress_lab.admissions VALUES
 ('11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-222222222222','pay_SyntheticOnly','r1','sandbox',true);
ALTER TABLE onepage_ingress_lab.admissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_ingress_lab.admissions FORCE ROW LEVEL SECURITY;
ALTER TABLE onepage_ingress_lab.inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_ingress_lab.inbox FORCE ROW LEVEL SECURITY;
GRANT SELECT,UPDATE(active) ON onepage_ingress_lab.admissions TO onepage_ingress_owner;
GRANT SELECT,INSERT ON onepage_ingress_lab.inbox TO onepage_ingress_owner;
CREATE POLICY admissions_owner ON onepage_ingress_lab.admissions TO onepage_ingress_owner USING (true) WITH CHECK (true);
CREATE POLICY admissions_exact ON onepage_ingress_lab.admissions AS RESTRICTIVE TO onepage_ingress_owner USING (
 account_ref='11111111-1111-4111-8111-111111111111' AND order_id='22222222-2222-4222-8222-222222222222'
 AND payment_id='pay_SyntheticOnly' AND revision='r1' AND environment='sandbox'
) WITH CHECK (
 account_ref='11111111-1111-4111-8111-111111111111' AND order_id='22222222-2222-4222-8222-222222222222'
 AND payment_id='pay_SyntheticOnly' AND revision='r1' AND environment='sandbox'
);
CREATE POLICY inbox_owner ON onepage_ingress_lab.inbox TO onepage_ingress_owner USING (true) WITH CHECK (true);
CREATE POLICY inbox_exact ON onepage_ingress_lab.inbox AS RESTRICTIVE TO onepage_ingress_owner USING (
 account_ref='11111111-1111-4111-8111-111111111111' AND order_id='22222222-2222-4222-8222-222222222222'
 AND payment_id='pay_SyntheticOnly' AND revision='r1'
) WITH CHECK (
 account_ref='11111111-1111-4111-8111-111111111111' AND order_id='22222222-2222-4222-8222-222222222222'
 AND payment_id='pay_SyntheticOnly' AND revision='r1'
);
