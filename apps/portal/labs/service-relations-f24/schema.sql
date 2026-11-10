-- PORTAL-F24: 100% disposable, synthetic membership/session state.
-- NEVER apply to real Portal databases. Run only after F21/schema.sql.
BEGIN;

CREATE SCHEMA f24;
REVOKE ALL ON SCHEMA f24 FROM PUBLIC;

CREATE TABLE f24.membership_fixture (
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    actor_id text NOT NULL CHECK (actor_id ~ '^DEMO-USER-[A-Z0-9-]+$'),
    membership_revision integer NOT NULL CHECK (membership_revision > 0),
    mock_state text NOT NULL CHECK (mock_state IN ('active','suspended','revoked')),
    can_view_services boolean NOT NULL DEFAULT false,
    PRIMARY KEY (organization_id, actor_id)
);

CREATE TABLE f24.session_fixture (
    organization_id text NOT NULL,
    actor_id text NOT NULL,
    session_id text NOT NULL CHECK (session_id ~ '^DEMO-SESSION-[A-Z0-9-]+$'),
    session_revision integer NOT NULL CHECK (session_revision > 0),
    mock_state text NOT NULL CHECK (mock_state IN ('active','revoked')),
    expires_at bigint NOT NULL,
    mfa_at bigint NOT NULL,
    PRIMARY KEY (organization_id, session_id),
    FOREIGN KEY (organization_id, actor_id)
      REFERENCES f24.membership_fixture (organization_id, actor_id)
);

INSERT INTO f24.membership_fixture VALUES
('org-a', 'DEMO-USER-A', 1, 'active', true),
('org-b', 'DEMO-USER-B', 1, 'active', true);

INSERT INTO f24.session_fixture VALUES
('org-a','DEMO-USER-A','DEMO-SESSION-A',1,'active',1800000900,1800000000),
('org-b','DEMO-USER-B','DEMO-SESSION-B',1,'active',1800000900,1800000000);

ALTER TABLE f24.membership_fixture ENABLE ROW LEVEL SECURITY;
ALTER TABLE f24.session_fixture ENABLE ROW LEVEL SECURITY;
ALTER TABLE f24.membership_fixture FORCE ROW LEVEL SECURITY;
ALTER TABLE f24.session_fixture FORCE ROW LEVEL SECURITY;

CREATE POLICY f24_member_a ON f24.membership_fixture FOR SELECT TO f21_org_a
  USING (organization_id = 'org-a' AND session_user = 'f21_org_a');
CREATE POLICY f24_member_b ON f24.membership_fixture FOR SELECT TO f21_org_b
  USING (organization_id = 'org-b' AND session_user = 'f21_org_b');
CREATE POLICY f24_session_a ON f24.session_fixture FOR SELECT TO f21_org_a
  USING (organization_id = 'org-a' AND session_user = 'f21_org_a');
CREATE POLICY f24_session_b ON f24.session_fixture FOR SELECT TO f21_org_b
  USING (organization_id = 'org-b' AND session_user = 'f21_org_b');

GRANT USAGE ON SCHEMA f24 TO f21_org_a, f21_org_b;
GRANT SELECT ON f24.membership_fixture, f24.session_fixture TO f21_org_a, f21_org_b;
REVOKE ALL ON ALL TABLES IN SCHEMA f24 FROM PUBLIC;

COMMIT;
