-- PORTAL-F40: in-process FAKE relay receipts. CI/PG18 disposable ONLY.
-- Requires f38 + f39 schemas; no network deliveries or customer records.
BEGIN;

CREATE ROLE f40_relay_a LOGIN NOINHERIT;
CREATE ROLE f40_relay_b LOGIN NOINHERIT;
CREATE SCHEMA f40;
REVOKE ALL ON SCHEMA f40 FROM PUBLIC;

CREATE TABLE f40.fake_delivery_receipt (
    organization_id text NOT NULL CHECK (organization_id IN ('org-a','org-b')),
    command_id text NOT NULL,
    outbox_reference text NOT NULL
       CHECK (outbox_reference ~ '^F39-DEMO-CMD-[A-Z0-9-]+$'),
    payload_sha256 text NOT NULL CHECK (payload_sha256 ~ '^[a-f0-9]{64}$'),
    simulated_at bigint NOT NULL
       CHECK (simulated_at BETWEEN 1800000000 AND 1800003600),
    PRIMARY KEY (organization_id,command_id),
    FOREIGN KEY (organization_id,command_id)
      REFERENCES f39.synthetic_request_outbox(organization_id,command_id)
      ON DELETE RESTRICT
);

ALTER TABLE f40.fake_delivery_receipt ENABLE ROW LEVEL SECURITY;
ALTER TABLE f40.fake_delivery_receipt FORCE ROW LEVEL SECURITY;
CREATE POLICY f40_receipt_read_a ON f40.fake_delivery_receipt
  FOR SELECT TO f40_relay_a
  USING (organization_id='org-a' AND session_user='f40_relay_a');
CREATE POLICY f40_receipt_read_b ON f40.fake_delivery_receipt
  FOR SELECT TO f40_relay_b
  USING (organization_id='org-b' AND session_user='f40_relay_b');

-- Different SQL principals from the original F38 writers.
CREATE POLICY f40_outbox_read_a ON f39.synthetic_request_outbox
  FOR SELECT TO f40_relay_a
  USING (organization_id='org-a' AND session_user='f40_relay_a');
CREATE POLICY f40_outbox_read_b ON f39.synthetic_request_outbox
  FOR SELECT TO f40_relay_b
  USING (organization_id='org-b' AND session_user='f40_relay_b');

GRANT USAGE ON SCHEMA f39,f40 TO f40_relay_a,f40_relay_b;
GRANT SELECT ON f39.synthetic_request_outbox TO f40_relay_a,f40_relay_b;
GRANT SELECT ON f40.fake_delivery_receipt TO f40_relay_a,f40_relay_b;
REVOKE ALL ON f40.fake_delivery_receipt FROM PUBLIC;

-- This is NOT evidence of an actual delivery. It only records a
-- SHA-256 match against the current synthetic outbox JSON payload after
-- an in-memory fake sink reports success. The caller can still lie.
CREATE FUNCTION f40.record_fake_delivery(
    p_organization text,
    p_command_id text,
    p_outbox_reference text,
    p_payload_sha256 text,
    p_simulated_at bigint
) RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, f40
AS $body$
DECLARE
    expected_role text;
    source f39.synthetic_request_outbox%ROWTYPE;
    existing f40.fake_delivery_receipt%ROWTYPE;
    true_hash text;
BEGIN
    expected_role := CASE p_organization
      WHEN 'org-a' THEN 'f40_relay_a'
      WHEN 'org-b' THEN 'f40_relay_b'
      ELSE NULL
    END;
    IF expected_role IS NULL
      OR session_user <> expected_role
      OR current_user <> 'postgres'
      OR p_command_id IS NULL
      OR p_command_id !~ '^DEMO-CMD-[A-Z0-9-]+$'
      OR p_outbox_reference IS NULL
      OR p_outbox_reference <> 'F39-' || p_command_id
      OR p_payload_sha256 IS NULL
      OR p_payload_sha256 !~ '^[a-f0-9]{64}$'
      OR p_simulated_at IS NULL
      OR p_simulated_at NOT BETWEEN 1800000000 AND 1800003600 THEN
        RAISE EXCEPTION 'F40: invalid synthetic relay identity or input';
    END IF;

    SELECT * INTO source
      FROM f39.synthetic_request_outbox
     WHERE organization_id = p_organization
       AND command_id = p_command_id
     FOR SHARE;
    IF NOT FOUND OR source.outbox_reference <> p_outbox_reference
      OR source.event_type <> 'service_request.status_changed.demo' THEN
        RAISE EXCEPTION 'F40: missing or invalid synthetic outbox source';
    END IF;

    true_hash := encode(sha256(convert_to(source.payload::text,'UTF8')), 'hex');
    IF true_hash <> p_payload_sha256 THEN
        RAISE EXCEPTION 'F40: payload changed or forged acknowledgement';
    END IF;

    SELECT * INTO existing
      FROM f40.fake_delivery_receipt
     WHERE organization_id = p_organization
       AND command_id = p_command_id
     FOR UPDATE;
    IF FOUND THEN
        IF existing.outbox_reference <> p_outbox_reference
          OR existing.payload_sha256 <> p_payload_sha256 THEN
            RAISE EXCEPTION 'F40: conflicting fake delivery replay';
        END IF;
        RETURN 'already_recorded';
    END IF;

    INSERT INTO f40.fake_delivery_receipt(
        organization_id,command_id,outbox_reference,payload_sha256,simulated_at
    ) VALUES (
        p_organization,p_command_id,p_outbox_reference,p_payload_sha256,p_simulated_at
    );
    RETURN 'recorded';
END
$body$;

REVOKE ALL ON FUNCTION f40.record_fake_delivery(
    text,text,text,text,bigint
) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION f40.record_fake_delivery(
    text,text,text,text,bigint
) TO f40_relay_a,f40_relay_b;

COMMIT;
