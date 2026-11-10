-- PORTAL-F21: DISPOSABLE PostgreSQL 18 synthetic lab ONLY.
-- NEVER deploy or copy this DDL into Laravel migrations or real databases.
-- Runs as a synthetic postgres superuser in an isolated, no-published-port CI container.
BEGIN;

-- Separate *actual* unprivileged local database sessions, not a
-- SUPERUSER-authenticated session with SET SESSION AUTHORIZATION.
-- Authentication is via an isolated Docker UNIX socket (no TCP port).
CREATE ROLE f21_org_a LOGIN NOINHERIT;
CREATE ROLE f21_org_b LOGIN NOINHERIT;

CREATE SCHEMA f21;
REVOKE ALL ON SCHEMA f21 FROM PUBLIC;

CREATE TABLE f21.service (
    organization_id text NOT NULL,
    service_reference text NOT NULL,
    label text NOT NULL,
    PRIMARY KEY (organization_id, service_reference),
    CONSTRAINT f21_service_org CHECK (organization_id IN ('org-a', 'org-b')),
    CONSTRAINT f21_service_ref CHECK (service_reference ~ '^DEMO-SVC-[A-Z0-9-]+$')
);

CREATE TABLE f21.request (
    organization_id text NOT NULL,
    request_reference text NOT NULL,
    service_reference text NOT NULL,
    mock_state text NOT NULL,
    PRIMARY KEY (organization_id, request_reference),
    FOREIGN KEY (organization_id, service_reference)
       REFERENCES f21.service (organization_id, service_reference),
    CONSTRAINT f21_request_ref CHECK (request_reference ~ '^DEMO-REQ-[A-Z0-9-]+$')
);

CREATE TABLE f21.contract (
    organization_id text NOT NULL,
    contract_reference text NOT NULL,
    service_reference text NOT NULL,
    mock_state text NOT NULL,
    PRIMARY KEY (organization_id, contract_reference),
    FOREIGN KEY (organization_id, service_reference)
       REFERENCES f21.service (organization_id, service_reference),
    CONSTRAINT f21_contract_ref CHECK (contract_reference ~ '^DEMO-CTR-[A-Z0-9-]+$')
);

INSERT INTO f21.service VALUES
('org-a','DEMO-SVC-LANDING','Landing Page fictícia'),
('org-a','DEMO-SVC-ONEPAGE','Onepage fictícia'),
('org-a','DEMO-SVC-PERFORMANCE','Performance AI fictício'),
('org-a','DEMO-SVC-ZERO','Sem solicitações nem contratos'),
('org-b','DEMO-SVC-PRIVATE-B','Serviço fictício de outra empresa');

INSERT INTO f21.request VALUES
('org-a','DEMO-REQ-ONEPAGE-1','DEMO-SVC-ONEPAGE','draft'),
('org-a','DEMO-REQ-ONEPAGE-2','DEMO-SVC-ONEPAGE','review'),
('org-a','DEMO-REQ-PERFORMANCE-1','DEMO-SVC-PERFORMANCE','resolved'),
('org-b','DEMO-REQ-B-1','DEMO-SVC-PRIVATE-B','draft');

INSERT INTO f21.contract VALUES
('org-a','DEMO-CTR-ONEPAGE-1','DEMO-SVC-ONEPAGE','draft'),
('org-a','DEMO-CTR-LANDING-1','DEMO-SVC-LANDING','review'),
('org-a','DEMO-CTR-LANDING-2','DEMO-SVC-LANDING','draft'),
('org-b','DEMO-CTR-B-1','DEMO-SVC-PRIVATE-B','draft');

-- Cross-organization ownership is enforced at the DB composite FK level.
ALTER TABLE f21.service ENABLE ROW LEVEL SECURITY;
ALTER TABLE f21.request ENABLE ROW LEVEL SECURITY;
ALTER TABLE f21.contract ENABLE ROW LEVEL SECURITY;
ALTER TABLE f21.service FORCE ROW LEVEL SECURITY;
ALTER TABLE f21.request FORCE ROW LEVEL SECURITY;
ALTER TABLE f21.contract FORCE ROW LEVEL SECURITY;

-- Both identities connect as genuine unprivileged PostgreSQL sessions via
-- the isolated container's local UNIX socket. SESSION_USER is set by the
-- database connection, not by a caller-controlled GUC. Docker access is an
-- operator-level trust boundary: this is NOT proof of application IAM.
CREATE POLICY f21_service_a ON f21.service FOR SELECT TO f21_org_a
    USING (organization_id = 'org-a' AND session_user = 'f21_org_a');
CREATE POLICY f21_service_b ON f21.service FOR SELECT TO f21_org_b
    USING (organization_id = 'org-b' AND session_user = 'f21_org_b');
CREATE POLICY f21_request_a ON f21.request FOR SELECT TO f21_org_a
    USING (organization_id = 'org-a' AND session_user = 'f21_org_a');
CREATE POLICY f21_request_b ON f21.request FOR SELECT TO f21_org_b
    USING (organization_id = 'org-b' AND session_user = 'f21_org_b');
CREATE POLICY f21_contract_a ON f21.contract FOR SELECT TO f21_org_a
    USING (organization_id = 'org-a' AND session_user = 'f21_org_a');
CREATE POLICY f21_contract_b ON f21.contract FOR SELECT TO f21_org_b
    USING (organization_id = 'org-b' AND session_user = 'f21_org_b');

-- security_invoker avoids the usual view-owner bypass of underlying RLS.
-- Pre-aggregating 0-N children prevents COUNT multiplication by joins.
CREATE VIEW f21.service_summary WITH (security_invoker = true) AS
SELECT s.organization_id, s.service_reference, s.label,
       coalesce(r.requests_count, 0)::bigint AS requests_count,
       coalesce(c.contracts_count, 0)::bigint AS contracts_count
  FROM f21.service s
  LEFT JOIN (
    SELECT organization_id, service_reference, count(*) AS requests_count
      FROM f21.request GROUP BY organization_id, service_reference
  ) r ON r.organization_id = s.organization_id
     AND r.service_reference = s.service_reference
  LEFT JOIN (
    SELECT organization_id, service_reference, count(*) AS contracts_count
      FROM f21.contract GROUP BY organization_id, service_reference
  ) c ON c.organization_id = s.organization_id
     AND c.service_reference = s.service_reference;

GRANT USAGE ON SCHEMA f21 TO f21_org_a, f21_org_b;
GRANT SELECT ON f21.service, f21.request, f21.contract, f21.service_summary
    TO f21_org_a, f21_org_b;
REVOKE ALL ON ALL TABLES IN SCHEMA f21 FROM PUBLIC;

COMMIT;
