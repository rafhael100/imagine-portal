-- F5: ledger PostgreSQL descartável, isolado do banco Portal e da VPS.
-- Nunca executar como migration Laravel nem em banco existente.
\set ON_ERROR_STOP on
BEGIN;

DO $roles$
BEGIN
  CREATE ROLE commercial_lab_reader NOLOGIN NOBYPASSRLS;
EXCEPTION WHEN duplicate_object THEN
  NULL;
END
$roles$;

CREATE SCHEMA commercial_publishing_lab;
REVOKE ALL ON SCHEMA commercial_publishing_lab FROM PUBLIC;
GRANT USAGE ON SCHEMA commercial_publishing_lab TO commercial_lab_reader;

CREATE TABLE commercial_publishing_lab.releases (
  organization_id text NOT NULL CHECK (organization_id ~ '^[a-z][a-z0-9_-]{5,79}$'),
  release_id text NOT NULL CHECK (release_id ~ '^[a-z][a-z0-9_-]{5,79}$'),
  payload_sha256 text NOT NULL CHECK (payload_sha256 ~ '^[a-f0-9]{64}$'),
  editor_actor text NOT NULL CHECK (editor_actor ~ '^[a-z][a-z0-9_-]{5,79}$'),
  requested_at timestamptz NOT NULL,
  status text NOT NULL DEFAULT 'submitted' CHECK (status = 'submitted'),
  PRIMARY KEY (organization_id, release_id),
  UNIQUE (organization_id, release_id, payload_sha256, editor_actor)
);

CREATE TABLE commercial_publishing_lab.approvals (
  organization_id text NOT NULL,
  release_id text NOT NULL,
  payload_sha256 text NOT NULL,
  editor_actor text NOT NULL,
  approver_actor text NOT NULL CHECK (approver_actor ~ '^[a-z][a-z0-9_-]{5,79}$'),
  approved_at timestamptz NOT NULL,
  PRIMARY KEY (organization_id, release_id),
  UNIQUE (organization_id, release_id, payload_sha256),
  CHECK (editor_actor <> approver_actor),
  FOREIGN KEY (organization_id, release_id, payload_sha256, editor_actor)
    REFERENCES commercial_publishing_lab.releases
      (organization_id, release_id, payload_sha256, editor_actor)
    ON UPDATE RESTRICT ON DELETE RESTRICT
);

CREATE TABLE commercial_publishing_lab.publish_outbox (
  organization_id text NOT NULL,
  release_id text NOT NULL,
  payload_sha256 text NOT NULL,
  publisher_actor text NOT NULL CHECK (publisher_actor ~ '^[a-z][a-z0-9_-]{5,79}$'),
  queued_at timestamptz NOT NULL,
  idempotency_key text NOT NULL CHECK (idempotency_key ~ '^[a-z][a-z0-9_-]{5,79}$'),
  state text NOT NULL DEFAULT 'pending' CHECK (state = 'pending'),
  PRIMARY KEY (organization_id, release_id),
  UNIQUE (organization_id, idempotency_key),
  FOREIGN KEY (organization_id, release_id, payload_sha256)
    REFERENCES commercial_publishing_lab.approvals
      (organization_id, release_id, payload_sha256)
    ON UPDATE RESTRICT ON DELETE RESTRICT
);

CREATE FUNCTION commercial_publishing_lab.check_approval_chronology()
RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE requested timestamptz;
BEGIN
  SELECT requested_at INTO requested FROM commercial_publishing_lab.releases
    WHERE organization_id = NEW.organization_id AND release_id = NEW.release_id
      AND payload_sha256 = NEW.payload_sha256 AND editor_actor = NEW.editor_actor;
  -- Unknown or foreign-tenant release must be rejected by composite FK.
  IF requested IS NULL THEN RETURN NEW; END IF;
  IF NEW.approved_at <= requested OR
     NEW.approved_at > requested + interval '24 hours' THEN
    RAISE EXCEPTION 'editorial approval order/window violation';
  END IF;
  RETURN NEW;
END $fn$;
CREATE TRIGGER approval_chronology BEFORE INSERT ON commercial_publishing_lab.approvals
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.check_approval_chronology();

CREATE FUNCTION commercial_publishing_lab.check_outbox_chronology()
RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE approved timestamptz;
DECLARE approver text;
DECLARE editor text;
BEGIN
  SELECT approved_at, approver_actor, editor_actor INTO approved, approver, editor
  FROM commercial_publishing_lab.approvals
  WHERE organization_id = NEW.organization_id AND release_id = NEW.release_id
    AND payload_sha256 = NEW.payload_sha256;
  -- Non-approved release must fail at composite FK before creating outbox.
  IF approved IS NULL THEN RETURN NEW; END IF;
  IF NEW.queued_at <= approved OR
     NEW.queued_at > approved + interval '24 hours' OR
     NEW.publisher_actor = approver OR NEW.publisher_actor = editor THEN
    RAISE EXCEPTION 'publisher segregation or queue chronology violation';
  END IF;
  RETURN NEW;
END $fn$;
CREATE TRIGGER outbox_chronology BEFORE INSERT ON commercial_publishing_lab.publish_outbox
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.check_outbox_chronology();

CREATE FUNCTION commercial_publishing_lab.prevent_history_rewrite()
RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
  RAISE EXCEPTION 'commercial release history is append-only';
END $fn$;
CREATE TRIGGER approvals_immutable BEFORE UPDATE OR DELETE ON commercial_publishing_lab.approvals
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.prevent_history_rewrite();
CREATE TRIGGER outbox_immutable BEFORE UPDATE OR DELETE ON commercial_publishing_lab.publish_outbox
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.prevent_history_rewrite();

CREATE FUNCTION commercial_publishing_lab.prevent_approved_release_rewrite()
RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
  IF EXISTS(SELECT 1 FROM commercial_publishing_lab.approvals
            WHERE organization_id=OLD.organization_id AND release_id=OLD.release_id) THEN
    RAISE EXCEPTION 'approved release cannot be rewritten';
  END IF;
  RETURN OLD;
END $fn$;
CREATE TRIGGER release_history_immutable BEFORE UPDATE OR DELETE ON commercial_publishing_lab.releases
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.prevent_approved_release_rewrite();

-- FORCE RLS even for table owner, except privileged superuser/BYPASSRLS roles.
-- The custom setting is untrusted until populated from a verified Portal session.
ALTER TABLE commercial_publishing_lab.releases ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.releases FORCE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.approvals ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.approvals FORCE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.publish_outbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.publish_outbox FORCE ROW LEVEL SECURITY;

CREATE POLICY read_releases ON commercial_publishing_lab.releases
  FOR SELECT TO commercial_lab_reader
  USING (organization_id = nullif(current_setting('commercial_lab.organization_id',true),''));
CREATE POLICY read_approvals ON commercial_publishing_lab.approvals
  FOR SELECT TO commercial_lab_reader
  USING (organization_id = nullif(current_setting('commercial_lab.organization_id',true),''));
CREATE POLICY read_outbox ON commercial_publishing_lab.publish_outbox
  FOR SELECT TO commercial_lab_reader
  USING (organization_id = nullif(current_setting('commercial_lab.organization_id',true),''));

GRANT SELECT ON commercial_publishing_lab.releases,
  commercial_publishing_lab.approvals,
  commercial_publishing_lab.publish_outbox TO commercial_lab_reader;

COMMIT;
