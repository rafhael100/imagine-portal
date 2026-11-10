-- F118: PostgreSQL 18 ephemeral GitHub CI fixture. NEVER deploy/migrate.
-- No visitor PII, payload, credentials, consent evidence or CRM ACK.
CREATE SCHEMA portal_commercial_ci;
REVOKE ALL ON SCHEMA portal_commercial_ci FROM PUBLIC;

CREATE ROLE portal_commercial_f118_a NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE portal_commercial_f118_b NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

CREATE TABLE portal_commercial_ci.metadata_ledger (
  organization_reference uuid NOT NULL,
  submission_reference uuid NOT NULL,
  binding_reference uuid NOT NULL,
  site_reference uuid NOT NULL,
  form_reference uuid NOT NULL,
  destination_reference uuid NOT NULL,
  PRIMARY KEY (organization_reference,submission_reference)
);
CREATE TABLE portal_commercial_ci.pending_outbox (
  organization_reference uuid NOT NULL,
  submission_reference uuid NOT NULL,
  event_kind text NOT NULL CHECK(event_kind='pending_review'),
  PRIMARY KEY (organization_reference,submission_reference,event_kind),
  FOREIGN KEY (organization_reference,submission_reference)
    REFERENCES portal_commercial_ci.metadata_ledger
      (organization_reference,submission_reference)
);

REVOKE ALL ON portal_commercial_ci.metadata_ledger FROM PUBLIC;
REVOKE ALL ON portal_commercial_ci.pending_outbox FROM PUBLIC;
ALTER TABLE portal_commercial_ci.metadata_ledger ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_commercial_ci.metadata_ledger FORCE ROW LEVEL SECURITY;
ALTER TABLE portal_commercial_ci.pending_outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_commercial_ci.pending_outbox FORCE ROW LEVEL SECURITY;

GRANT USAGE ON SCHEMA portal_commercial_ci
  TO portal_commercial_f118_a,portal_commercial_f118_b;
GRANT SELECT,INSERT ON portal_commercial_ci.metadata_ledger
  TO portal_commercial_f118_a,portal_commercial_f118_b;
GRANT SELECT,INSERT ON portal_commercial_ci.pending_outbox
  TO portal_commercial_f118_a,portal_commercial_f118_b;

CREATE POLICY f118_ledger_a ON portal_commercial_ci.metadata_ledger
  FOR ALL TO portal_commercial_f118_a
  USING (organization_reference='11111111-1111-4111-8111-111111111111'::uuid)
  WITH CHECK (organization_reference='11111111-1111-4111-8111-111111111111'::uuid);
CREATE POLICY f118_ledger_b ON portal_commercial_ci.metadata_ledger
  FOR ALL TO portal_commercial_f118_b
  USING (organization_reference='99999999-9999-4999-8999-999999999999'::uuid)
  WITH CHECK (organization_reference='99999999-9999-4999-8999-999999999999'::uuid);
CREATE POLICY f118_outbox_a ON portal_commercial_ci.pending_outbox
  FOR ALL TO portal_commercial_f118_a
  USING (organization_reference='11111111-1111-4111-8111-111111111111'::uuid)
  WITH CHECK (organization_reference='11111111-1111-4111-8111-111111111111'::uuid);
CREATE POLICY f118_outbox_b ON portal_commercial_ci.pending_outbox
  FOR ALL TO portal_commercial_f118_b
  USING (organization_reference='99999999-9999-4999-8999-999999999999'::uuid)
  WITH CHECK (organization_reference='99999999-9999-4999-8999-999999999999'::uuid);

COMMENT ON SCHEMA portal_commercial_ci IS
  'F118 synthetic CI-only; no real Portal or CRM lead database.';
