-- PORTAL-F31: second, independent *DISPOSABLE* PostgreSQL 18 container.
-- Not a production witness, not durable, not a real identity authority.
-- Run ONLY inside the isolated F31 CI runner, NEVER on an existing server.
BEGIN;

CREATE ROLE f31_witness_reader LOGIN NOINHERIT;
CREATE SCHEMA f31;
REVOKE ALL ON SCHEMA f31 FROM PUBLIC;

CREATE TABLE f31.revocation_floor (
    organization_id text PRIMARY KEY
       CHECK (organization_id IN ('org-a', 'org-b')),
    session_floor integer NOT NULL CHECK (session_floor BETWEEN 1 AND 1000000),
    membership_floor integer NOT NULL CHECK (membership_floor BETWEEN 1 AND 1000000),
    sequence_number bigint NOT NULL CHECK (sequence_number BETWEEN 1 AND 100000000),
    changed_at bigint NOT NULL CHECK (changed_at > 0)
);

INSERT INTO f31.revocation_floor
    (organization_id, session_floor, membership_floor, sequence_number, changed_at)
VALUES
    ('org-a', 1, 1, 1, 1800000000),
    ('org-b', 1, 1, 1, 1800000000);

CREATE FUNCTION f31.monotonic_floor_writer()
RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'F31: delete forbidden on synthetic revocation witness';
    END IF;
    IF TG_OP = 'UPDATE' AND
       (NEW.organization_id <> OLD.organization_id
        OR NEW.session_floor < OLD.session_floor
        OR NEW.membership_floor < OLD.membership_floor
        OR NEW.sequence_number <= OLD.sequence_number
        OR NEW.changed_at < OLD.changed_at) THEN
        RAISE EXCEPTION 'F31: attempted revision rollback';
    END IF;
    RETURN NEW;
END
$body$;

CREATE TRIGGER f31_floor_monotonic
BEFORE UPDATE OR DELETE ON f31.revocation_floor
FOR EACH ROW EXECUTE FUNCTION f31.monotonic_floor_writer();

ALTER TABLE f31.revocation_floor ENABLE ROW LEVEL SECURITY;
ALTER TABLE f31.revocation_floor FORCE ROW LEVEL SECURITY;
CREATE POLICY f31_reader_policy ON f31.revocation_floor FOR SELECT
    TO f31_witness_reader USING (organization_id IN ('org-a','org-b'));

GRANT USAGE ON SCHEMA f31 TO f31_witness_reader;
GRANT SELECT ON f31.revocation_floor TO f31_witness_reader;
REVOKE ALL ON ALL TABLES IN SCHEMA f31 FROM PUBLIC;
COMMIT;
