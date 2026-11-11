-- F121: ephemeral GitHub CI ONLY, a mock CRM receiver in a separate DB.
-- Execute exclusively against portal_crm_receiver_lab (not Portal DB).
-- The fixture represents six synthetic UUIDs, NEVER a real lead/PII or ACK.
CREATE SCHEMA mock_crm_ci;
REVOKE ALL ON SCHEMA mock_crm_ci FROM PUBLIC;
CREATE ROLE portal_f121_mock_crm_a NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE portal_f121_mock_crm_b NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

CREATE TABLE mock_crm_ci.received_submissions (
  organization_reference uuid NOT NULL,
  submission_reference uuid NOT NULL,
  binding_reference uuid NOT NULL,
  site_reference uuid NOT NULL,
  form_reference uuid NOT NULL,
  destination_reference uuid NOT NULL,
  stored_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY (organization_reference,submission_reference)
);
CREATE TABLE mock_crm_ci.request_nonces (
  organization_reference uuid NOT NULL,
  nonce uuid NOT NULL,
  submission_reference uuid NOT NULL,
  observed_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY (organization_reference,nonce),
  FOREIGN KEY (organization_reference,submission_reference)
    REFERENCES mock_crm_ci.received_submissions
    (organization_reference,submission_reference)
    DEFERRABLE INITIALLY DEFERRED
);

REVOKE ALL ON mock_crm_ci.received_submissions FROM PUBLIC;
REVOKE ALL ON mock_crm_ci.request_nonces FROM PUBLIC;
ALTER TABLE mock_crm_ci.received_submissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE mock_crm_ci.received_submissions FORCE ROW LEVEL SECURITY;
ALTER TABLE mock_crm_ci.request_nonces ENABLE ROW LEVEL SECURITY;
ALTER TABLE mock_crm_ci.request_nonces FORCE ROW LEVEL SECURITY;

GRANT USAGE ON SCHEMA mock_crm_ci TO portal_f121_mock_crm_a,portal_f121_mock_crm_b;
GRANT SELECT,INSERT ON mock_crm_ci.received_submissions
  TO portal_f121_mock_crm_a,portal_f121_mock_crm_b;
GRANT SELECT,INSERT ON mock_crm_ci.request_nonces
  TO portal_f121_mock_crm_a,portal_f121_mock_crm_b;
CREATE POLICY f121_receiver_a ON mock_crm_ci.received_submissions
  FOR ALL TO portal_f121_mock_crm_a
  USING (organization_reference='11111111-1111-4111-8111-111111111111'::uuid)
  WITH CHECK (organization_reference='11111111-1111-4111-8111-111111111111'::uuid);
CREATE POLICY f121_receiver_b ON mock_crm_ci.received_submissions
  FOR ALL TO portal_f121_mock_crm_b
  USING (organization_reference='99999999-9999-4999-8999-999999999999'::uuid)
  WITH CHECK (organization_reference='99999999-9999-4999-8999-999999999999'::uuid);
CREATE POLICY f121_nonce_a ON mock_crm_ci.request_nonces
  FOR ALL TO portal_f121_mock_crm_a
  USING (organization_reference='11111111-1111-4111-8111-111111111111'::uuid)
  WITH CHECK (organization_reference='11111111-1111-4111-8111-111111111111'::uuid);
CREATE POLICY f121_nonce_b ON mock_crm_ci.request_nonces
  FOR ALL TO portal_f121_mock_crm_b
  USING (organization_reference='99999999-9999-4999-8999-999999999999'::uuid)
  WITH CHECK (organization_reference='99999999-9999-4999-8999-999999999999'::uuid);

COMMENT ON SCHEMA mock_crm_ci IS
  'F121 independent disposable CRM mock DB. No real CRM, PII or production migrations.';
