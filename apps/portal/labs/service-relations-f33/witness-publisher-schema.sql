-- PORTAL-F33: witness-first publication fixture on the SECOND disposable PG18.
-- RUN ONLY after F31 witness-schema.sql in isolated GitHub Actions.
-- Not a durable external authority, not a live IAM source, no customer data.
BEGIN;

CREATE ROLE f33_revocation_publisher LOGIN NOINHERIT;
CREATE SCHEMA f33;
REVOKE ALL ON SCHEMA f33 FROM PUBLIC;

CREATE TABLE f33.publication_ledger (
    event_id text PRIMARY KEY CHECK (event_id ~ '^F33-[A-Z0-9-]{1,56}$'),
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    kind text NOT NULL CHECK (kind IN ('session','membership')),
    subject_ref text NOT NULL CHECK (subject_ref ~ '^DEMO-(SESSION|USER)-[A-Z0-9-]+$'),
    session_floor integer NOT NULL CHECK (session_floor BETWEEN 1 AND 1000000),
    membership_floor integer NOT NULL CHECK (membership_floor BETWEEN 1 AND 1000000),
    changed_at bigint NOT NULL CHECK (changed_at > 0),
    sequence_number bigint NOT NULL CHECK (sequence_number BETWEEN 1 AND 100000000)
);
REVOKE ALL ON f33.publication_ledger FROM PUBLIC;

-- All writer-side modifications to f31 floors go through this constrained
-- lab procedure. It serializes on the f31 row, stores a unique event binding
-- and advances the floor + ledger in ONE witness database transaction.
-- This does NOT make the primary + witness update distributed/atomic.
CREATE FUNCTION f33.publish_revocation(
    p_event_id text,
    p_organization text,
    p_kind text,
    p_subject text,
    p_session_floor integer,
    p_membership_floor integer,
    p_changed_at bigint
) RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, f33
AS $body$
DECLARE
    prior f33.publication_ledger%ROWTYPE;
    current_session integer;
    current_member integer;
    current_sequence bigint;
    current_changed_at bigint;
BEGIN
    IF p_event_id IS NULL
       OR p_event_id !~ '^F33-[A-Z0-9-]{1,56}$'
       OR p_organization NOT IN ('org-a','org-b')
       OR p_kind NOT IN ('session','membership')
       OR p_subject IS NULL
       OR (p_kind = 'session' AND p_subject !~ '^DEMO-SESSION-[A-Z0-9-]{1,40}$')
       OR (p_kind = 'membership' AND p_subject !~ '^DEMO-USER-[A-Z0-9-]{1,40}$')
       OR p_session_floor NOT BETWEEN 1 AND 1000000
       OR p_membership_floor NOT BETWEEN 1 AND 1000000
       OR p_changed_at <= 0 THEN
        RAISE EXCEPTION 'F33: invalid synthetic event';
    END IF;

    -- Serialize competing publications for this organization FIRST.
    SELECT session_floor, membership_floor, sequence_number, changed_at
      INTO current_session, current_member, current_sequence, current_changed_at
      FROM f31.revocation_floor
     WHERE organization_id = p_organization
     FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'F33: missing synthetic revocation floor';
    END IF;

    SELECT * INTO prior FROM f33.publication_ledger
     WHERE event_id = p_event_id;
    IF FOUND THEN
        IF prior.organization_id <> p_organization
           OR prior.kind <> p_kind
           OR prior.subject_ref <> p_subject
           OR prior.session_floor <> p_session_floor
           OR prior.membership_floor <> p_membership_floor
           OR prior.changed_at <> p_changed_at
           OR current_session < prior.session_floor
           OR current_member < prior.membership_floor
           OR current_sequence < prior.sequence_number THEN
            RAISE EXCEPTION 'F33: conflicting or rolled-back event replay';
        END IF;
        RETURN 'replayed';
    END IF;

    IF p_session_floor < current_session
       OR p_membership_floor < current_member
       OR (p_session_floor = current_session
           AND p_membership_floor = current_member)
       OR p_changed_at < current_changed_at
       OR current_sequence >= 99999999 THEN
        RAISE EXCEPTION 'F33: non-monotonic or no-op publication';
    END IF;

    -- A single F33 event can raise only its own corresponding floor.
    IF (p_kind = 'session' AND p_membership_floor <> current_member)
       OR (p_kind = 'membership' AND p_session_floor <> current_session) THEN
        RAISE EXCEPTION 'F33: publication kind and target mismatch';
    END IF;

    UPDATE f31.revocation_floor
       SET session_floor=p_session_floor,
           membership_floor=p_membership_floor,
           sequence_number=current_sequence+1,
           changed_at=p_changed_at
     WHERE organization_id=p_organization;

    INSERT INTO f33.publication_ledger
        (event_id, organization_id, kind, subject_ref, session_floor,
         membership_floor, changed_at, sequence_number)
    VALUES
        (p_event_id, p_organization, p_kind, p_subject,
         p_session_floor, p_membership_floor, p_changed_at,
         current_sequence+1);

    RETURN 'published';
END
$body$;

REVOKE ALL ON FUNCTION f33.publish_revocation(
    text,text,text,text,integer,integer,bigint
) FROM PUBLIC;
GRANT USAGE ON SCHEMA f33 TO f33_revocation_publisher;
GRANT EXECUTE ON FUNCTION f33.publish_revocation(
    text,text,text,text,integer,integer,bigint
) TO f33_revocation_publisher;

COMMIT;
