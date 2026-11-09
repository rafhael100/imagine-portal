-- PORTAL-F38: disposable service-request transactional WRITE fixture.
-- Never run on production, VPS or customer databases.
-- Requires PostgreSQL 18 ephemeral container, separate DEMO roles.
BEGIN;
CREATE ROLE f38_writer_a LOGIN NOINHERIT;
CREATE ROLE f38_writer_b LOGIN NOINHERIT;
CREATE SCHEMA f38;
REVOKE ALL ON SCHEMA f38 FROM PUBLIC;

CREATE TABLE f38.service_request (
    organization_id text NOT NULL
       CHECK (organization_id IN ('org-a','org-b')),
    request_id text NOT NULL CHECK (request_id ~ '^DEMO-REQUEST-[A-Z0-9-]+$'),
    service_reference text NOT NULL CHECK (service_reference ~ '^DEMO-SVC-[A-Z0-9-]+$'),
    current_status text NOT NULL CHECK (current_status IN
       ('draft','submitted','in_review','waiting_on_customer','resolved','closed')),
    revision integer NOT NULL CHECK (revision BETWEEN 0 AND 100000),
    updated_at bigint NOT NULL CHECK (updated_at BETWEEN 1800000000 AND 1800003600),
    PRIMARY KEY (organization_id, request_id)
);

CREATE TABLE f38.command_receipt (
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    request_id text NOT NULL,
    command_id text NOT NULL CHECK (command_id ~ '^DEMO-CMD-[A-Z0-9-]+$'),
    payload_fingerprint text NOT NULL CHECK (payload_fingerprint ~ '^[a-f0-9]{64}$'),
    action text NOT NULL,
    actor_kind text NOT NULL,
    previous_revision integer NOT NULL CHECK (previous_revision BETWEEN 0 AND 100000),
    result_revision integer NOT NULL CHECK (result_revision BETWEEN 1 AND 100000),
    result_status text NOT NULL CHECK (result_status IN
       ('submitted','in_review','waiting_on_customer','resolved','closed')),
    occurred_at bigint NOT NULL CHECK (occurred_at BETWEEN 1800000000 AND 1800003600),
    PRIMARY KEY (organization_id, command_id),
    FOREIGN KEY (organization_id,request_id)
       REFERENCES f38.service_request(organization_id,request_id)
);

INSERT INTO f38.service_request VALUES
('org-a','DEMO-REQUEST-A','DEMO-SVC-ONEPAGE','draft',0,1800000000),
('org-b','DEMO-REQUEST-B','DEMO-SVC-PRIVATE-B','draft',0,1800000000);

ALTER TABLE f38.service_request ENABLE ROW LEVEL SECURITY;
ALTER TABLE f38.service_request FORCE ROW LEVEL SECURITY;
ALTER TABLE f38.command_receipt ENABLE ROW LEVEL SECURITY;
ALTER TABLE f38.command_receipt FORCE ROW LEVEL SECURITY;

CREATE POLICY f38_request_a ON f38.service_request FOR ALL TO f38_writer_a
  USING (organization_id='org-a' AND session_user='f38_writer_a')
  WITH CHECK (organization_id='org-a' AND session_user='f38_writer_a');
CREATE POLICY f38_request_b ON f38.service_request FOR ALL TO f38_writer_b
  USING (organization_id='org-b' AND session_user='f38_writer_b')
  WITH CHECK (organization_id='org-b' AND session_user='f38_writer_b');

CREATE POLICY f38_receipt_a ON f38.command_receipt FOR ALL TO f38_writer_a
  USING (organization_id='org-a' AND session_user='f38_writer_a')
  WITH CHECK (organization_id='org-a' AND session_user='f38_writer_a');
CREATE POLICY f38_receipt_b ON f38.command_receipt FOR ALL TO f38_writer_b
  USING (organization_id='org-b' AND session_user='f38_writer_b')
  WITH CHECK (organization_id='org-b' AND session_user='f38_writer_b');

GRANT USAGE ON SCHEMA f38 TO f38_writer_a,f38_writer_b;
GRANT SELECT,INSERT,UPDATE ON f38.service_request,f38.command_receipt
   TO f38_writer_a,f38_writer_b;
REVOKE ALL ON ALL TABLES IN SCHEMA f38 FROM PUBLIC;
COMMIT;
