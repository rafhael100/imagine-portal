-- R07-F1 demonstration-only PostgreSQL 18 schema.
CREATE SCHEMA r07;
REVOKE ALL ON SCHEMA r07 FROM PUBLIC;
CREATE TABLE r07.account (
  actor_id text PRIMARY KEY,
  active boolean NOT NULL,
  session_epoch bigint NOT NULL CHECK (session_epoch > 0)
);
CREATE TABLE r07.session (
  verifier_sha256 text PRIMARY KEY CHECK (verifier_sha256 ~ '^[a-f0-9]{64}$'),
  actor_id text NOT NULL REFERENCES r07.account(actor_id),
  session_epoch bigint NOT NULL CHECK (session_epoch > 0),
  active boolean NOT NULL,
  expires_at timestamptz NOT NULL
);
CREATE TABLE r07.membership (
  organization_id text NOT NULL,
  actor_id text NOT NULL REFERENCES r07.account(actor_id),
  can_read boolean NOT NULL,
  can_write boolean NOT NULL,
  PRIMARY KEY (organization_id, actor_id)
);
CREATE TABLE r07.resource_index (
  resource_id text PRIMARY KEY,
  organization_id text NOT NULL,
  UNIQUE (organization_id, resource_id)
);
CREATE TABLE r07.private_resource (
  organization_id text NOT NULL,
  resource_id text NOT NULL,
  payload text NOT NULL,
  PRIMARY KEY (organization_id, resource_id),
  FOREIGN KEY (organization_id, resource_id)
    REFERENCES r07.resource_index(organization_id, resource_id)
);
CREATE TABLE r07.effect (
  organization_id text NOT NULL,
  effect_id text NOT NULL,
  resource_id text NOT NULL,
  actor_id text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY (organization_id, effect_id),
  FOREIGN KEY (organization_id, resource_id)
    REFERENCES r07.private_resource(organization_id, resource_id),
  FOREIGN KEY (organization_id, actor_id)
    REFERENCES r07.membership(organization_id, actor_id)
);
-- RLS is only defense-in-depth inside Z2; not proof of user identity.
ALTER TABLE r07.private_resource ENABLE ROW LEVEL SECURITY;
ALTER TABLE r07.private_resource FORCE ROW LEVEL SECURITY;
CREATE POLICY resource_read ON r07.private_resource
  FOR SELECT TO r07_authority
  USING (organization_id = current_setting('r07.tenant', true));
ALTER TABLE r07.effect ENABLE ROW LEVEL SECURITY;
ALTER TABLE r07.effect FORCE ROW LEVEL SECURITY;
CREATE POLICY effect_select ON r07.effect
  FOR SELECT TO r07_authority
  USING (organization_id = current_setting('r07.tenant', true));
CREATE POLICY effect_insert ON r07.effect
  FOR INSERT TO r07_authority
  WITH CHECK (organization_id = current_setting('r07.tenant', true));
REVOKE ALL ON DATABASE portal_r07_f1 FROM PUBLIC;
GRANT CONNECT ON DATABASE portal_r07_f1 TO r07_authority;
GRANT USAGE ON SCHEMA r07 TO r07_authority;
GRANT SELECT ON r07.account, r07.session, r07.membership,
                r07.resource_index, r07.private_resource, r07.effect TO r07_authority;
GRANT INSERT ON r07.effect TO r07_authority;
