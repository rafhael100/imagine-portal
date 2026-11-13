-- F376 candidate ONLY: apply exclusively to a separately controlled Cloudflare D1
-- witness database AFTER independent security review. No auto-deployment.
-- Never restore this database from a Portal/Laravel database backup.
CREATE TABLE IF NOT EXISTS witness_epochs (
    organization TEXT NOT NULL
        CHECK(length(organization) BETWEEN 1 AND 64
              AND organization NOT GLOB '*[^A-Za-z0-9_-]*'),
    instance TEXT NOT NULL
        CHECK(length(instance) BETWEEN 1 AND 64
              AND instance NOT GLOB '*[^A-Za-z0-9_-]*'),
    generation_floor INTEGER NOT NULL
        CHECK(generation_floor BETWEEN 1 AND 1000000000),
    session_floor INTEGER NOT NULL
        CHECK(session_floor BETWEEN 1 AND 1000000000),
    membership_floor INTEGER NOT NULL
        CHECK(membership_floor BETWEEN 1 AND 1000000000),
    sequence INTEGER NOT NULL
        CHECK(sequence BETWEEN 1 AND 1000000000),
    updated_at INTEGER NOT NULL CHECK(updated_at > 0),
    PRIMARY KEY(organization,instance)
);

CREATE TRIGGER IF NOT EXISTS witness_epoch_monotonic
BEFORE UPDATE ON witness_epochs
FOR EACH ROW WHEN
    NEW.organization != OLD.organization
    OR NEW.instance != OLD.instance
    OR NEW.sequence != OLD.sequence + 1
    OR NEW.generation_floor < OLD.generation_floor
    OR NEW.session_floor < OLD.session_floor
    OR NEW.membership_floor < OLD.membership_floor
BEGIN
    SELECT RAISE(ABORT, 'witness epoch monotonicity violation');
END;

CREATE TRIGGER IF NOT EXISTS witness_epoch_no_delete
BEFORE DELETE ON witness_epochs
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'witness state deletion refused');
END;

-- No default seed! An operator must bind/audit the exact organization,
-- instance, baselines, and independent key custody before any witness exists.
