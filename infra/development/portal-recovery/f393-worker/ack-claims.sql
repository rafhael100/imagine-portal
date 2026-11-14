-- F393 -- independently stored *CLAIM* of local PostgreSQL revocation.
-- Install ONLY after F376 witness_epochs and F386 revocation_intents in
-- disposable CI. Do NOT deploy as an actual remote security guarantee.
-- A separate ACK writer HMAC proves identity of publisher, NOT local PG truth.
CREATE TABLE IF NOT EXISTS revocation_ack_claims (
    organization TEXT NOT NULL,
    instance TEXT NOT NULL,
    intent_id TEXT NOT NULL CHECK(length(intent_id)=32
        AND intent_id NOT GLOB '*[^0-9a-f]*'),
    session_sha256 TEXT NOT NULL CHECK(length(session_sha256)=64
        AND session_sha256 NOT GLOB '*[^0-9a-f]*'),
    intent_sequence INTEGER NOT NULL CHECK(intent_sequence BETWEEN 1 AND 1000000000),
    local_status TEXT NOT NULL CHECK(local_status='revoked_claimed'),
    claimed_at INTEGER NOT NULL CHECK(claimed_at>0),
    PRIMARY KEY(organization,instance,intent_id),
    FOREIGN KEY(organization,instance,intent_id)
        REFERENCES revocation_intents(organization,instance,intent_id)
);

CREATE TRIGGER IF NOT EXISTS revocation_ack_claim_validate
BEFORE INSERT ON revocation_ack_claims FOR EACH ROW BEGIN
    SELECT CASE WHEN NOT EXISTS (
      SELECT 1 FROM revocation_intents i
      JOIN witness_epochs w ON w.organization=i.organization
       AND w.instance=i.instance
      WHERE i.organization=NEW.organization
        AND i.instance=NEW.instance
        AND i.intent_id=NEW.intent_id
        AND i.session_sha256=NEW.session_sha256
        AND i.to_sequence=NEW.intent_sequence
        AND w.sequence>=i.to_sequence
        AND w.session_floor>=i.session_floor
    ) THEN RAISE(ABORT, 'ack without matching witnessed intent') END;
END;

CREATE TRIGGER IF NOT EXISTS revocation_ack_claim_no_update
BEFORE UPDATE ON revocation_ack_claims FOR EACH ROW BEGIN
    SELECT RAISE(ABORT,'revocation ACK mutation refused');
END;

CREATE TRIGGER IF NOT EXISTS revocation_ack_claim_no_delete
BEFORE DELETE ON revocation_ack_claims FOR EACH ROW BEGIN
    SELECT RAISE(ABORT,'revocation ACK deletion refused');
END;
