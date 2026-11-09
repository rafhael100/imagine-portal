-- F7: laboratorio descartavel de barreira de revogacao e reserva de dispatch.
-- Esta camada e modelagem; NUNCA aplicar no banco real ou como migration.
\set ON_ERROR_STOP on
BEGIN;

CREATE TABLE commercial_publishing_lab.publication_fence (
  organization_id text PRIMARY KEY CHECK (organization_id ~ '^[a-z][a-z0-9_-]{5,79}$'),
  generation bigint NOT NULL DEFAULT 1 CHECK (generation > 0),
  enabled boolean NOT NULL DEFAULT false,
  changed_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE commercial_publishing_lab.release_fence (
  organization_id text NOT NULL,
  release_id text NOT NULL,
  payload_sha256 text NOT NULL,
  generation bigint NOT NULL CHECK (generation > 0),
  PRIMARY KEY (organization_id,release_id),
  UNIQUE (organization_id,release_id,payload_sha256,generation),
  FOREIGN KEY (organization_id,release_id,payload_sha256)
    REFERENCES commercial_publishing_lab.approvals
       (organization_id,release_id,payload_sha256)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  FOREIGN KEY (organization_id)
    REFERENCES commercial_publishing_lab.publication_fence(organization_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
);

-- Receipts significam somente reserva transacional local; NAO confirmam
-- entrega externa, deploy ou idempotencia no provedor de publicacao.
CREATE TABLE commercial_publishing_lab.dispatch_receipts (
  organization_id text NOT NULL,
  release_id text NOT NULL,
  payload_sha256 text NOT NULL,
  generation bigint NOT NULL,
  dispatch_id text NOT NULL CHECK (dispatch_id ~ '^[a-z][a-z0-9_-]{5,79}$'),
  reserved_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY (organization_id,release_id),
  UNIQUE (organization_id,dispatch_id),
  FOREIGN KEY (organization_id,release_id,payload_sha256,generation)
    REFERENCES commercial_publishing_lab.release_fence
       (organization_id,release_id,payload_sha256,generation)
    ON UPDATE RESTRICT ON DELETE RESTRICT
);

CREATE TRIGGER bound_release_immutable
  BEFORE UPDATE OR DELETE ON commercial_publishing_lab.release_fence
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.prevent_history_rewrite();
CREATE TRIGGER dispatch_receipt_immutable
  BEFORE UPDATE OR DELETE ON commercial_publishing_lab.dispatch_receipts
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.prevent_history_rewrite();

-- Faz lock no fence antes de verificar geracao e gravar a reserva,
-- serializando-se com UPDATE/REVOKE deste mesmo registro.
-- SECURITY INVOKER: sem elevacao automatica de privilegios.
CREATE FUNCTION commercial_publishing_lab.reserve_dispatch_once(
  p_organization text,
  p_release text,
  p_sha256 text,
  p_dispatch text
) RETURNS boolean
LANGUAGE plpgsql SECURITY INVOKER AS $lab$
DECLARE
  current_generation bigint;
  allowed boolean;
  affected integer;
BEGIN
  IF p_organization !~ '^[a-z][a-z0-9_-]{5,79}$' OR
     p_release !~ '^[a-z][a-z0-9_-]{5,79}$' OR
     p_dispatch !~ '^[a-z][a-z0-9_-]{5,79}$' OR
     p_sha256 !~ '^[a-f0-9]{64}$' THEN
    RAISE EXCEPTION 'invalid dispatch input';
  END IF;

  SELECT generation,enabled INTO current_generation,allowed
  FROM commercial_publishing_lab.publication_fence
  WHERE organization_id=p_organization FOR UPDATE;
  IF NOT FOUND OR allowed IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'publication authority is disabled or missing';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM commercial_publishing_lab.release_fence b
    INNER JOIN commercial_publishing_lab.publish_outbox q
      ON b.organization_id=q.organization_id AND b.release_id=q.release_id
        AND b.payload_sha256=q.payload_sha256
    WHERE b.organization_id=p_organization AND b.release_id=p_release
      AND b.payload_sha256=p_sha256 AND b.generation=current_generation
      AND q.state='pending'
  ) THEN
    RAISE EXCEPTION 'release not approved for current authority generation';
  END IF;

  INSERT INTO commercial_publishing_lab.dispatch_receipts
    (organization_id,release_id,payload_sha256,generation,dispatch_id)
  VALUES (p_organization,p_release,p_sha256,current_generation,p_dispatch)
  ON CONFLICT (organization_id,release_id) DO NOTHING;
  GET DIAGNOSTICS affected=ROW_COUNT;
  RETURN affected=1;
END
$lab$;

REVOKE ALL ON FUNCTION commercial_publishing_lab.reserve_dispatch_once(text,text,text,text) FROM PUBLIC;

-- Visibilidade estritamente por organizacao no leitor experimental.
ALTER TABLE commercial_publishing_lab.publication_fence ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.publication_fence FORCE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.release_fence ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.release_fence FORCE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.dispatch_receipts ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.dispatch_receipts FORCE ROW LEVEL SECURITY;

CREATE POLICY read_fence ON commercial_publishing_lab.publication_fence
  FOR SELECT TO commercial_lab_reader
  USING (organization_id = nullif(current_setting('commercial_lab.organization_id',true),''));
CREATE POLICY read_bindings ON commercial_publishing_lab.release_fence
  FOR SELECT TO commercial_lab_reader
  USING (organization_id = nullif(current_setting('commercial_lab.organization_id',true),''));
CREATE POLICY read_receipts ON commercial_publishing_lab.dispatch_receipts
  FOR SELECT TO commercial_lab_reader
  USING (organization_id = nullif(current_setting('commercial_lab.organization_id',true),''));

GRANT SELECT ON commercial_publishing_lab.publication_fence,
  commercial_publishing_lab.release_fence,
  commercial_publishing_lab.dispatch_receipts TO commercial_lab_reader;
COMMIT;
