-- PORTAL-F39: disposable outbox for f38 request commands.
-- Run ONLY after service-requests-f38/schema.sql in ephemeral PG18.
-- No real customer data, delivery worker, CRM, emails, webhooks or payment.
BEGIN;
CREATE SCHEMA f39;
REVOKE ALL ON SCHEMA f39 FROM PUBLIC;

CREATE TABLE f39.synthetic_request_outbox (
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    command_id text NOT NULL,
    outbox_reference text NOT NULL
       CHECK (outbox_reference ~ '^F39-DEMO-CMD-[A-Z0-9-]+$'),
    event_type text NOT NULL
       CHECK (event_type='service_request.status_changed.demo'),
    payload jsonb NOT NULL CHECK (jsonb_typeof(payload)='object'),
    occurred_at bigint NOT NULL
       CHECK (occurred_at BETWEEN 1800000000 AND 1800003600),
    PRIMARY KEY (organization_id,command_id),
    FOREIGN KEY (organization_id,command_id)
       REFERENCES f38.command_receipt(organization_id,command_id)
);

ALTER TABLE f39.synthetic_request_outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE f39.synthetic_request_outbox FORCE ROW LEVEL SECURITY;

CREATE POLICY f39_outbox_a ON f39.synthetic_request_outbox
  FOR ALL TO f38_writer_a
  USING (organization_id='org-a' AND session_user='f38_writer_a')
  WITH CHECK (organization_id='org-a' AND session_user='f38_writer_a');

CREATE POLICY f39_outbox_b ON f39.synthetic_request_outbox
  FOR ALL TO f38_writer_b
  USING (organization_id='org-b' AND session_user='f38_writer_b')
  WITH CHECK (organization_id='org-b' AND session_user='f38_writer_b');

GRANT USAGE ON SCHEMA f39 TO f38_writer_a,f38_writer_b;
GRANT SELECT,INSERT ON f39.synthetic_request_outbox TO f38_writer_a,f38_writer_b;
REVOKE ALL ON ALL TABLES IN SCHEMA f39 FROM PUBLIC;
COMMIT;
