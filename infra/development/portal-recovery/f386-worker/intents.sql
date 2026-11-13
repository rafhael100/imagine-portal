-- F386: additive schema for a SEPARATELY ADMINISTERED D1 witness only.
-- Never apply to the Portal PostgreSQL DB or its backups.
-- F376 witness_epochs must exist before this file. This schema is NOT
-- installed by any runtime, Wrangler command, or production migration.
CREATE TABLE IF NOT EXISTS revocation_intents (
    organization TEXT NOT NULL
        CHECK(length(organization) BETWEEN 1 AND 64
              AND organization NOT GLOB '*[^A-Za-z0-9_-]*'),
    instance TEXT NOT NULL
        CHECK(length(instance) BETWEEN 1 AND 64
              AND instance NOT GLOB '*[^A-Za-z0-9_-]*'),
    intent_id TEXT NOT NULL
        CHECK(length(intent_id) = 32
              AND intent_id NOT GLOB '*[^0-9a-f]*'),
    session_sha256 TEXT NOT NULL
        CHECK(length(session_sha256) = 64
              AND session_sha256 NOT GLOB '*[^0-9a-f]*'),
    from_sequence INTEGER NOT NULL
        CHECK(from_sequence BETWEEN 1 AND 999999999),
    to_sequence INTEGER NOT NULL
        CHECK(to_sequence = from_sequence + 1),
    generation_floor INTEGER NOT NULL CHECK(generation_floor BETWEEN 1 AND 1000000000),
    session_floor INTEGER NOT NULL CHECK(session_floor BETWEEN 1 AND 1000000000),
    membership_floor INTEGER NOT NULL CHECK(membership_floor BETWEEN 1 AND 1000000000),
    created_at INTEGER NOT NULL CHECK(created_at > 0),
    PRIMARY KEY (organization, instance, intent_id),
    UNIQUE (organization, instance, session_sha256, to_sequence),
    FOREIGN KEY (organization, instance)
        REFERENCES witness_epochs (organization, instance)
);

-- The intent cannot be inserted unless it is backed by the CURRENT
-- independent witness sequence and a STRICTLY higher session floor.
-- Combined with D1 batch's transactional execution this prevents
-- inserting an intent without the matching monotonic witness advance.
CREATE TRIGGER IF NOT EXISTS revocation_intent_insert_guard
BEFORE INSERT ON revocation_intents
FOR EACH ROW
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM witness_epochs w
        WHERE w.organization = NEW.organization
          AND w.instance = NEW.instance
          AND w.sequence = NEW.from_sequence
          AND w.sequence < 1000000000
          AND w.generation_floor <= NEW.generation_floor
          AND w.session_floor < NEW.session_floor
          AND w.membership_floor <= NEW.membership_floor
    ) THEN RAISE(ABORT, 'revocation intent CAS/floor refused') END;
END;

CREATE TRIGGER IF NOT EXISTS revocation_intent_immutable
BEFORE UPDATE ON revocation_intents
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'revocation intent mutation refused');
END;

CREATE TRIGGER IF NOT EXISTS revocation_intent_no_delete
BEFORE DELETE ON revocation_intents
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'revocation intent deletion refused');
END;

-- No automatic seed, cleanup or delete API. An independently audited
-- revocation reconciliation/ack protocol is a different future stage.
