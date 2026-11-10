-- PORTAL-F35: synthetic acknowledgement registry on the witness DB only.
-- CI-only, after F31/F33/F34 schemas. NEVER install on real databases.
BEGIN;
CREATE ROLE f35_acknowledgement_writer LOGIN NOINHERIT;
CREATE SCHEMA f35;
REVOKE ALL ON SCHEMA f35 FROM PUBLIC;

CREATE TABLE f35.applied_receipt (
    event_id text PRIMARY KEY
      REFERENCES f33.publication_ledger(event_id) ON DELETE RESTRICT,
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    kind text NOT NULL CHECK (kind IN ('session','membership')),
    subject_ref text NOT NULL,
    observed_primary_revision integer NOT NULL
      CHECK (observed_primary_revision BETWEEN 1 AND 1000000),
    acknowledged_at bigint NOT NULL
      CHECK (acknowledged_at BETWEEN 1800000000 AND 1800003600)
);

ALTER TABLE f35.applied_receipt ENABLE ROW LEVEL SECURITY;
ALTER TABLE f35.applied_receipt FORCE ROW LEVEL SECURITY;
CREATE POLICY f35_receipts_reader ON f35.applied_receipt
  FOR SELECT TO f34_reconciliation_reader
  USING (session_user='f34_reconciliation_reader');
REVOKE ALL ON f35.applied_receipt FROM PUBLIC;
GRANT USAGE ON SCHEMA f35 TO f34_reconciliation_reader, f35_acknowledgement_writer;
GRANT SELECT ON f35.applied_receipt TO f34_reconciliation_reader;

-- A restricted writer may invoke only the precisely scoped procedure.
-- The procedure CANNOT prove the assertion about primary DB by itself;
-- PHP caller separately checks primary with tenant-bound read identity.
CREATE FUNCTION f35.confirm_observed_primary(
    p_event_id text,
    p_organization text,
    p_kind text,
    p_subject_ref text,
    p_observed_revision integer,
    p_acknowledged_at bigint
) RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, f35
AS $body$
DECLARE
    source f33.publication_ledger%ROWTYPE;
    recorded f35.applied_receipt%ROWTYPE;
    required_revision integer;
BEGIN
    IF p_event_id IS NULL
      OR p_event_id !~ '^F33-[A-Z0-9-]{1,56}$'
      OR p_organization IS NULL
      OR p_organization NOT IN ('org-a','org-b')
      OR p_kind IS NULL
      OR p_kind NOT IN ('session','membership')
      OR p_subject_ref IS NULL
      OR p_observed_revision IS NULL
      OR p_observed_revision NOT BETWEEN 1 AND 1000000
      OR p_acknowledged_at IS NULL
      OR p_acknowledged_at NOT BETWEEN 1800000000 AND 1800003600 THEN
        RAISE EXCEPTION 'F35: invalid fixture acknowledgement';
    END IF;

    SELECT * INTO source
      FROM f33.publication_ledger
     WHERE event_id=p_event_id
     FOR SHARE;
    IF NOT FOUND
      OR source.organization_id <> p_organization
      OR source.kind <> p_kind
      OR source.subject_ref <> p_subject_ref THEN
        RAISE EXCEPTION 'F35: event binding mismatch or missing event';
    END IF;

    required_revision := CASE p_kind
      WHEN 'session' THEN source.session_floor
      ELSE source.membership_floor
    END;
    IF p_observed_revision < required_revision THEN
        RAISE EXCEPTION 'F35: primary below published witness floor';
    END IF;

    SELECT * INTO recorded
      FROM f35.applied_receipt
     WHERE event_id=p_event_id
     FOR UPDATE;
    IF FOUND THEN
        IF recorded.organization_id <> p_organization
          OR recorded.kind <> p_kind
          OR recorded.subject_ref <> p_subject_ref
          OR recorded.observed_primary_revision <> p_observed_revision
          OR recorded.acknowledged_at <> p_acknowledged_at THEN
            RAISE EXCEPTION 'F35: conflicting acknowledgement replay';
        END IF;
        RETURN 'already_recorded';
    END IF;

    INSERT INTO f35.applied_receipt(
        event_id, organization_id, kind, subject_ref,
        observed_primary_revision, acknowledged_at
    ) VALUES (
        p_event_id,p_organization,p_kind,p_subject_ref,
        p_observed_revision,p_acknowledged_at
    );

    RETURN 'recorded';
END
$body$;

REVOKE ALL ON FUNCTION f35.confirm_observed_primary(
    text,text,text,text,integer,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION f35.confirm_observed_primary(
    text,text,text,text,integer,bigint
) TO f35_acknowledgement_writer;
COMMIT;
