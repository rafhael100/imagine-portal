-- F11 - disposable Postgres lab only, never a Laravel migration.
\set ON_ERROR_STOP on
BEGIN;
CREATE TABLE commercial_publishing_lab.delivery_leases (
  organization_id text NOT NULL,
  release_id text NOT NULL,
  payload_sha256 text NOT NULL CHECK(payload_sha256 ~ '^[a-f0-9]{64}$'),
  authority_generation bigint NOT NULL CHECK(authority_generation>0),
  attempt_number bigint NOT NULL CHECK(attempt_number>0),
  lease_until timestamptz NOT NULL,
  acknowledged_at timestamptz,
  PRIMARY KEY(organization_id,release_id),
  FOREIGN KEY(organization_id,release_id)
    REFERENCES commercial_publishing_lab.dispatch_receipts(organization_id,release_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
);
CREATE FUNCTION commercial_publishing_lab.guard_delivery_lease_update()
RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF NEW.organization_id IS DISTINCT FROM OLD.organization_id OR
     NEW.release_id IS DISTINCT FROM OLD.release_id OR
     NEW.payload_sha256 IS DISTINCT FROM OLD.payload_sha256 OR
     NEW.authority_generation IS DISTINCT FROM OLD.authority_generation OR
     NEW.attempt_number<OLD.attempt_number OR
     (OLD.acknowledged_at IS NOT NULL AND
       NEW.acknowledged_at IS DISTINCT FROM OLD.acknowledged_at) THEN
    RAISE EXCEPTION 'lease immutable identity/ack violated';
  END IF;
  RETURN NEW;
END $f$;
CREATE TRIGGER guard_delivery_lease BEFORE UPDATE ON commercial_publishing_lab.delivery_leases
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.guard_delivery_lease_update();

-- Returns 1,2,... for new/recovered attempt; 0 for busy; -1 if locally acked.
-- Serializes on the same authority row used by publication revocation.
CREATE FUNCTION commercial_publishing_lab.acquire_delivery_lease(
  p_org text,p_release text,p_digest text
) RETURNS bigint LANGUAGE plpgsql SECURITY INVOKER AS $lease$
DECLARE
  gen bigint; enabled_now boolean; receipt_gen bigint; receipt_digest text;
  old_attempt bigint; expires timestamptz; ack timestamptz; stamp timestamptz;
BEGIN
  IF p_org !~ '^[a-z][a-z0-9_-]{5,79}$' OR
     p_release !~ '^[a-z][a-z0-9_-]{5,79}$' OR
     p_digest !~ '^[a-f0-9]{64}$' THEN
    RAISE EXCEPTION 'invalid delivery lease request';
  END IF;
  SELECT f.generation,f.enabled INTO gen,enabled_now
    FROM commercial_publishing_lab.publication_fence f
    WHERE f.organization_id=p_org FOR UPDATE;
  IF NOT FOUND OR enabled_now IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'lease refused: publication disabled';
  END IF;
  SELECT r.generation,r.payload_sha256 INTO receipt_gen,receipt_digest
    FROM commercial_publishing_lab.dispatch_receipts r
    JOIN commercial_publishing_lab.publish_outbox q
      ON q.organization_id=r.organization_id AND q.release_id=r.release_id
        AND q.payload_sha256=r.payload_sha256
    WHERE r.organization_id=p_org AND r.release_id=p_release AND q.state='pending';
  IF NOT FOUND OR receipt_gen<>gen OR receipt_digest IS DISTINCT FROM p_digest THEN
    RAISE EXCEPTION 'lease refused: missing current approved receipt';
  END IF;
  stamp:=clock_timestamp();
  SELECT l.attempt_number,l.lease_until,l.acknowledged_at
    INTO old_attempt,expires,ack
    FROM commercial_publishing_lab.delivery_leases l
    WHERE l.organization_id=p_org AND l.release_id=p_release FOR UPDATE;
  IF NOT FOUND THEN
    INSERT INTO commercial_publishing_lab.delivery_leases
      (organization_id,release_id,payload_sha256,authority_generation,
       attempt_number,lease_until)
    VALUES(p_org,p_release,p_digest,gen,1,stamp+interval '5 minutes');
    RETURN 1;
  END IF;
  IF ack IS NOT NULL THEN RETURN -1; END IF;
  IF expires>stamp THEN RETURN 0; END IF;
  UPDATE commercial_publishing_lab.delivery_leases
    SET attempt_number=attempt_number+1,lease_until=stamp+interval '5 minutes'
    WHERE organization_id=p_org AND release_id=p_release
    RETURNING attempt_number INTO old_attempt;
  RETURN old_attempt;
END $lease$;

-- Caller MUST verify a trusted remote delivery confirmation before acking.
CREATE FUNCTION commercial_publishing_lab.ack_delivery_lease(
  p_org text,p_release text,p_digest text,p_attempt bigint
) RETURNS boolean LANGUAGE plpgsql SECURITY INVOKER AS $ack$
DECLARE gen bigint; enabled_now boolean;
        current_attempt bigint; expires timestamptz; acked timestamptz;
BEGIN
  SELECT f.generation,f.enabled INTO gen,enabled_now
    FROM commercial_publishing_lab.publication_fence f
    WHERE f.organization_id=p_org FOR UPDATE;
  IF NOT FOUND OR enabled_now IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'ack refused: authority revoked';
  END IF;
  SELECT l.attempt_number,l.lease_until,l.acknowledged_at
    INTO current_attempt,expires,acked
    FROM commercial_publishing_lab.delivery_leases l
    WHERE l.organization_id=p_org AND l.release_id=p_release
      AND l.payload_sha256=p_digest AND l.authority_generation=gen FOR UPDATE;
  IF NOT FOUND OR current_attempt IS DISTINCT FROM p_attempt OR
     expires<=clock_timestamp() THEN
    RAISE EXCEPTION 'ack refused: stale or expired attempt';
  END IF;
  IF acked IS NOT NULL THEN RETURN false; END IF;
  UPDATE commercial_publishing_lab.delivery_leases
    SET acknowledged_at=clock_timestamp()
    WHERE organization_id=p_org AND release_id=p_release;
  RETURN true;
END $ack$;

REVOKE ALL ON FUNCTION commercial_publishing_lab.acquire_delivery_lease(text,text,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION commercial_publishing_lab.ack_delivery_lease(text,text,text,bigint) FROM PUBLIC;
ALTER TABLE commercial_publishing_lab.delivery_leases ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.delivery_leases FORCE ROW LEVEL SECURITY;
CREATE POLICY read_delivery_leases ON commercial_publishing_lab.delivery_leases
  FOR SELECT TO commercial_lab_reader
  USING(organization_id=nullif(current_setting('commercial_lab.organization_id',true),''));
GRANT SELECT ON commercial_publishing_lab.delivery_leases TO commercial_lab_reader;
COMMIT;
