-- PORTAL-F1-02-B: disposable PostgreSQL 18 lab ONLY; never run on operational DB.
CREATE SCHEMA portal_lab;
CREATE TABLE portal_lab.account (
  actor_id text PRIMARY KEY,
  state text NOT NULL CHECK (state IN ('active','suspended')),
  session_epoch bigint NOT NULL CHECK (session_epoch > 0),
  identity_revision integer NOT NULL CHECK (identity_revision > 0),
  credential_revision integer NOT NULL CHECK (credential_revision > 0),
  mfa_revision integer NOT NULL CHECK (mfa_revision > 0)
);
CREATE TABLE portal_lab.challenge (
  challenge_id text PRIMARY KEY,
  actor_id text NOT NULL REFERENCES portal_lab.account(actor_id),
  purpose text NOT NULL CHECK (purpose IN ('login','step_up')),
  account_epoch bigint NOT NULL CHECK (account_epoch > 0),
  expires_at timestamptz NOT NULL,
  consumed_at timestamptz
);
CREATE TABLE portal_lab.session (
  verifier_sha256 text PRIMARY KEY CHECK (verifier_sha256 ~ '^[0-9a-f]{64}$'),
  actor_id text NOT NULL REFERENCES portal_lab.account(actor_id),
  state text NOT NULL CHECK (state IN ('active','revoked')),
  factor_verified boolean NOT NULL,
  session_epoch bigint NOT NULL CHECK (session_epoch > 0),
  identity_revision integer NOT NULL CHECK (identity_revision > 0),
  credential_revision integer NOT NULL CHECK (credential_revision > 0),
  mfa_revision integer NOT NULL CHECK (mfa_revision > 0),
  issued_at timestamptz NOT NULL,
  expires_at timestamptz NOT NULL,
  CHECK (expires_at > issued_at)
);
CREATE TABLE portal_lab.organization (organization_id text PRIMARY KEY);
CREATE TABLE portal_lab.membership (
  organization_id text NOT NULL REFERENCES portal_lab.organization(organization_id),
  actor_id text NOT NULL REFERENCES portal_lab.account(actor_id),
  state text NOT NULL CHECK (state IN ('active','revoked')),
  PRIMARY KEY (organization_id,actor_id)
);
CREATE TABLE portal_lab.resource (
  organization_id text NOT NULL REFERENCES portal_lab.organization(organization_id),
  resource_id text NOT NULL,
  synthetic_payload text NOT NULL,
  PRIMARY KEY (organization_id,resource_id)
);
CREATE TABLE portal_lab.effect (
  effect_id text PRIMARY KEY,
  organization_id text NOT NULL,
  resource_id text NOT NULL,
  actor_id text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  FOREIGN KEY (organization_id,resource_id)
    REFERENCES portal_lab.resource(organization_id,resource_id),
  FOREIGN KEY (organization_id,actor_id)
    REFERENCES portal_lab.membership(organization_id,actor_id)
);
-- RLS limits: the SQL runtime can forge custom GUC context. R01-10 stays OPEN.
ALTER TABLE portal_lab.resource ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_lab.resource FORCE ROW LEVEL SECURITY;
CREATE POLICY resource_read_scope ON portal_lab.resource FOR SELECT TO portal_lab_runtime
  USING (organization_id = current_setting('portal_lab.organization_id',true));
ALTER TABLE portal_lab.effect ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_lab.effect FORCE ROW LEVEL SECURITY;
CREATE POLICY effect_read_scope ON portal_lab.effect FOR SELECT TO portal_lab_runtime
  USING (organization_id = current_setting('portal_lab.organization_id',true));
CREATE POLICY effect_insert_scope ON portal_lab.effect FOR INSERT TO portal_lab_runtime
  WITH CHECK (organization_id = current_setting('portal_lab.organization_id',true));
GRANT USAGE ON SCHEMA portal_lab TO portal_lab_runtime;
GRANT SELECT ON portal_lab.resource, portal_lab.effect TO portal_lab_runtime;
GRANT INSERT ON portal_lab.effect TO portal_lab_runtime;
