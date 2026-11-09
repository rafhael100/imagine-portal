-- PORTAL-F35: explicit acknowledgement of FICTIONAL witness ledger entries.
-- Load AFTER F31/F33/F34 on the SECOND disposable PostgreSQL 18 instance.
-- Not a customer event queue, independent attestation, or production IAM.
BEGIN;

CREATE ROLE f35_ack_worker LOGIN NOINHERIT;
CREATE SCHEMA f35;
REVOKE ALL ON SCHEMA f35 FROM PUBLIC;

CREATE TABLE f35.applied_event_ack (
    event_id text PRIMARY KEY REFERENCES f33.publication_ledger (event_id),
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    kind text NOT NULL CHECK (kind IN ('session','membership')),
    subject_ref text NOT NULL CHECK (subject_ref ~ '^DEMO-(SESSION|USER)-[A-Z0-9-]+$'),
    session_floor integer NOT NULL CHECK (session_floor BETWEEN 1 AND 1000000),
    membership_floor integer NOT NULL CHECK (membership_floor BETWEEN 1 AND 1000000),
    changed_at bigint NOT NULL CHECK (changed_at BETWEEN 1800000000 AND 1800003600),
    recorded_at bigint NOT NULL CHECK (recorded_at BETWEEN 1800000000 AND 1800003600)
);
REVOKE ALL ON f35.applied_event_ack FROM PUBLIC;

ALTER TABLE f35.applied_event_ack ENABLE ROW LEVEL SECURITY;
ALTER TABLE f35.applied_event_ack FORCE ROW LEVEL SECURITY;
CREATE POLICY f35_ack_reader ON f35.applied_event_ack
    FOR SELECT TO f35_ack_worker
    USING (session_user = 'f35_ack_worker');

-- An acknowledgement is append-only for the unprivileged lab actor.
-- The SECURITY DEFINER routine only accepts an EXACT match to the
-- previously witness-committed F33 payload.
CREATE FUNCTION f35.ack_applied(
    p_event_id text,
    p_organization text,
    p_kind text,
    p_subject text,
    p_session_floor integer,
    p_membership_floor integer,
    p_changed_at bigint,
    p_recorded_at bigint
) RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, f35
AS $body$
DECLARE
    existing f35.applied_event_ack%ROWTYPE;
    matched integer;
BEGIN
    IF session_user <> 'f35_ack_worker' OR current_setting('server_version_num')::int < 180000 THEN
        RAISE EXCEPTION 'F35: unexpected synthetic ack caller';
    END IF;
    IF p_event_id IS NULL OR p_event_id !~ '^F33-[A-Z0-9-]{1,56}$'
       OR p_organization NOT IN ('org-a','org-b')
       OR p_kind NOT IN ('session','membership')
       OR p_subject IS NULL
       OR (p_kind='session' AND p_subject !~ '^DEMO-SESSION-[A-Z0-9-]{1,40}$')
       OR (p_kind='membership' AND p_subject !~ '^DEMO-USER-[A-Z0-9-]{1,40}$')
       OR p_session_floor NOT BETWEEN 1 AND 1000000
       OR p_membership_floor NOT BETWEEN 1 AND 1000000
       OR p_changed_at NOT BETWEEN 1800000000 AND 1800003600
       OR p_recorded_at NOT BETWEEN 1800000000 AND 1800003600
       OR p_recorded_at < p_changed_at THEN
        RAISE EXCEPTION 'F35: invalid synthetic acknowledgement';
    END IF;

    -- The authoritative ordering of events still belongs to the F33
    -- witness ledger; matching the primary DB here is NOT possible.
    SELECT count(*) INTO matched
      FROM f33.publication_ledger l
     WHERE l.event_id=p_event_id AND l.organization_id=p_organization
       AND l.kind=p_kind AND l.subject_ref=p_subject
       AND l.session_floor=p_session_floor
       AND l.membership_floor=p_membership_floor
       AND l.changed_at=p_changed_at;
    IF matched <> 1 THEN
        RAISE EXCEPTION 'F35: no matching witness-published event';
    END IF;

    SELECT * INTO existing FROM f35.applied_event_ack WHERE event_id=p_event_id FOR UPDATE;
    IF FOUND THEN
        IF existing.organization_id <> p_organization OR existing.kind <> p_kind
           OR existing.subject_ref <> p_subject
           OR existing.session_floor <> p_session_floor
           OR existing.membership_floor <> p_membership_floor
           OR existing.changed_at <> p_changed_at THEN
            RAISE EXCEPTION 'F35: conflicting acknowledgement replay';
        END IF;
        RETURN 'replayed';
    END IF;

    INSERT INTO f35.applied_event_ack
        (event_id,organization_id,kind,subject_ref,session_floor,
         membership_floor,changed_at,recorded_at)
    VALUES
        (p_event_id,p_organization,p_kind,p_subject,p_session_floor,
         p_membership_floor,p_changed_at,p_recorded_at);
    RETURN 'recorded';
END
$body$;

REVOKE ALL ON FUNCTION f35.ack_applied(
    text,text,text,text,integer,integer,bigint,bigint
) FROM PUBLIC;
GRANT USAGE ON SCHEMA f35 TO f35_ack_worker;
GRANT SELECT ON f35.applied_event_ack TO f35_ack_worker;
GRANT EXECUTE ON FUNCTION f35.ack_applied(
    text,text,text,text,integer,integer,bigint,bigint
) TO f35_ack_worker;
COMMIT;
