-- F395: separate D1-like lease table for retry attempts; NOT a work-complete
-- certificate, not installed in production, never attached to Laravel HTTP.
-- Install AFTER F376 epochs and F386 intents; existing F393 ACK claims do
-- NOT change a lease's eligibility or authorize skipping an intent.
CREATE TABLE IF NOT EXISTS revocation_retry_leases (
  organization TEXT NOT NULL,
  instance TEXT NOT NULL,
  intent_id TEXT NOT NULL CHECK(length(intent_id)=32
    AND intent_id NOT GLOB '*[^0-9a-f]*'),
  session_sha256 TEXT NOT NULL CHECK(length(session_sha256)=64
    AND session_sha256 NOT GLOB '*[^0-9a-f]*'),
  intent_sequence INTEGER NOT NULL CHECK(intent_sequence BETWEEN 1 AND 1000000000),
  lease_sha256 TEXT NOT NULL CHECK(length(lease_sha256)=64
    AND lease_sha256 NOT GLOB '*[^0-9a-f]*'),
  acquired_at INTEGER NOT NULL CHECK(acquired_at BETWEEN 1 AND 9999999999),
  lease_until INTEGER NOT NULL CHECK(lease_until=acquired_at+30),
  attempt_count INTEGER NOT NULL CHECK(attempt_count BETWEEN 1 AND 5),
  PRIMARY KEY(organization,instance,intent_id),
  FOREIGN KEY(organization,instance,intent_id)
    REFERENCES revocation_intents(organization,instance,intent_id)
);
CREATE TRIGGER IF NOT EXISTS f395_lease_insert_guard
BEFORE INSERT ON revocation_retry_leases FOR EACH ROW BEGIN
  SELECT CASE WHEN NOT EXISTS (
    SELECT 1 FROM revocation_intents i
    JOIN witness_epochs w ON w.organization=i.organization
      AND w.instance=i.instance
    WHERE i.organization=NEW.organization AND i.instance=NEW.instance
      AND i.intent_id=NEW.intent_id
      AND i.session_sha256=NEW.session_sha256
      AND i.to_sequence=NEW.intent_sequence
      AND w.sequence>=i.to_sequence
      AND w.session_floor>=i.session_floor
  ) OR NEW.attempt_count<>1
  THEN RAISE(ABORT,'F395 unknown or unwitnessed intent') END;
END;
CREATE TRIGGER IF NOT EXISTS f395_lease_update_guard
BEFORE UPDATE ON revocation_retry_leases FOR EACH ROW BEGIN
  SELECT CASE WHEN
    NEW.organization<>OLD.organization OR NEW.instance<>OLD.instance
    OR NEW.intent_id<>OLD.intent_id
    OR NEW.session_sha256<>OLD.session_sha256
    OR NEW.intent_sequence<>OLD.intent_sequence
    OR NEW.lease_sha256=OLD.lease_sha256
    OR NEW.attempt_count<>OLD.attempt_count+1
    OR OLD.attempt_count>=5
    OR NEW.acquired_at<OLD.lease_until
    OR NEW.acquired_at<OLD.acquired_at
    OR NEW.lease_until<>NEW.acquired_at+30
    OR NOT EXISTS (
      SELECT 1 FROM revocation_intents i
      JOIN witness_epochs w ON w.organization=i.organization
        AND w.instance=i.instance
      WHERE i.organization=NEW.organization AND i.instance=NEW.instance
        AND i.intent_id=NEW.intent_id
        AND i.to_sequence=NEW.intent_sequence
        AND i.session_sha256=NEW.session_sha256
        AND w.sequence>=i.to_sequence AND w.session_floor>=i.session_floor
    )
  THEN RAISE(ABORT,'F395 unsafe lease re-acquisition') END;
END;
CREATE TRIGGER IF NOT EXISTS f395_lease_no_delete
BEFORE DELETE ON revocation_retry_leases FOR EACH ROW BEGIN
  SELECT RAISE(ABORT,'F395 lease deletion refused');
END;
