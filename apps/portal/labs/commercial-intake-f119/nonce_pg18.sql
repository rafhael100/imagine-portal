-- F119 ONLY: ephemeral PostgreSQL 18 GitHub Actions test. Never migrate.
-- Service request attempt nonce is distinct from logical submission key.
-- No personal data, HMAC, keys, headers, IPs or CRM receipt stored.
CREATE TABLE portal_commercial_ci.service_request_nonce (
  organization_reference uuid NOT NULL,
  nonce uuid NOT NULL,
  submission_reference uuid NOT NULL,
  observed_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY (organization_reference,nonce),
  CONSTRAINT f119_nonce_submission_fk
    FOREIGN KEY (organization_reference,submission_reference)
    REFERENCES portal_commercial_ci.metadata_ledger
    (organization_reference,submission_reference)
    DEFERRABLE INITIALLY DEFERRED
);
REVOKE ALL ON portal_commercial_ci.service_request_nonce FROM PUBLIC;
ALTER TABLE portal_commercial_ci.service_request_nonce ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_commercial_ci.service_request_nonce FORCE ROW LEVEL SECURITY;
GRANT SELECT,INSERT ON portal_commercial_ci.service_request_nonce TO
  portal_commercial_f118_a,portal_commercial_f118_b;

CREATE POLICY f119_nonce_a ON portal_commercial_ci.service_request_nonce
  FOR ALL TO portal_commercial_f118_a
  USING (organization_reference='11111111-1111-4111-8111-111111111111'::uuid)
  WITH CHECK (organization_reference='11111111-1111-4111-8111-111111111111'::uuid);
CREATE POLICY f119_nonce_b ON portal_commercial_ci.service_request_nonce
  FOR ALL TO portal_commercial_f118_b
  USING (organization_reference='99999999-9999-4999-8999-999999999999'::uuid)
  WITH CHECK (organization_reference='99999999-9999-4999-8999-999999999999'::uuid);

COMMENT ON TABLE portal_commercial_ci.service_request_nonce IS
  'F119 synthetic CI-only request attempt markers; not CRM delivery evidence.';
