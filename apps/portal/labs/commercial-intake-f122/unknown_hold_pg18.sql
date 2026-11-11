-- F122: disposable PostgreSQL 18 CI only. Fail-closed hold for unknown
-- transport outcomes; this never denotes a real CRM delivery receipt.
BEGIN;

CREATE ROLE portal_f122_worker_a NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE portal_f122_worker_b NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

CREATE TABLE portal_commercial_ci.delivery_gate (
  organization_reference uuid NOT NULL,
  submission_reference uuid NOT NULL,
  state text NOT NULL CHECK (state IN ('claimed','unknown')),
  claim_version bigint NOT NULL CHECK (claim_version >= 1),
  attempts smallint NOT NULL CHECK (attempts BETWEEN 1 AND 3),
  lease_until timestamptz,
  updated_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
  PRIMARY KEY (organization_reference,submission_reference),
  FOREIGN KEY (organization_reference,submission_reference)
    REFERENCES portal_commercial_ci.metadata_ledger
      (organization_reference,submission_reference),
  CHECK ((state='claimed' AND lease_until IS NOT NULL)
     OR (state='unknown' AND lease_until IS NULL))
);
REVOKE ALL ON portal_commercial_ci.delivery_gate FROM PUBLIC;
ALTER TABLE portal_commercial_ci.delivery_gate ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_commercial_ci.delivery_gate FORCE ROW LEVEL SECURITY;
GRANT USAGE ON SCHEMA portal_commercial_ci TO
  portal_f122_worker_a,portal_f122_worker_b;
GRANT SELECT ON portal_commercial_ci.delivery_gate TO
  portal_f122_worker_a,portal_f122_worker_b;

CREATE POLICY f122_worker_a_read ON portal_commercial_ci.delivery_gate
  FOR SELECT TO portal_f122_worker_a
  USING (organization_reference='11111111-1111-4111-8111-111111111111'::uuid);
CREATE POLICY f122_worker_b_read ON portal_commercial_ci.delivery_gate
  FOR SELECT TO portal_f122_worker_b
  USING (organization_reference='99999999-9999-4999-8999-999999999999'::uuid);

-- A worker cannot choose its own tenant, destination, lease or attempt count.
-- The privileged function owner exists ONLY in a throwaway GitHub CI database.
CREATE FUNCTION portal_commercial_ci.claim_lab_a(
  p_submission uuid,p_expected_version bigint
) RETURNS bigint
LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog
AS $$
DECLARE got_version bigint;
BEGIN
  IF p_submission IS NULL OR p_expected_version IS NULL
     OR p_expected_version < 0 THEN
    RETURN NULL;
  END IF;
  IF p_expected_version = 0 THEN
    INSERT INTO portal_commercial_ci.delivery_gate (
      organization_reference,submission_reference,state,claim_version,
      attempts,lease_until
    )
    SELECT organization_reference,submission_reference,
      'claimed',1,1,transaction_timestamp()+interval '60 seconds'
    FROM portal_commercial_ci.pending_outbox
    WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
      AND submission_reference=p_submission AND event_kind='pending_review'
    ON CONFLICT (organization_reference,submission_reference) DO NOTHING
    RETURNING claim_version INTO got_version;
    RETURN got_version;
  END IF;
  UPDATE portal_commercial_ci.delivery_gate
  SET claim_version=claim_version+1, attempts=attempts+1,
      lease_until=transaction_timestamp()+interval '60 seconds',
      updated_at=transaction_timestamp()
  WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
    AND submission_reference=p_submission
    AND state='claimed'
    AND claim_version=p_expected_version
    AND lease_until<transaction_timestamp()
    AND attempts<3
  RETURNING claim_version INTO got_version;
  RETURN got_version;
END;
$$;

-- The consumer reports an UNKNOWN outcome for its current version, including
-- a just-expired lease IF nobody has reclaimed it. A stale fencing version
-- cannot block a newer claimant. Unknown status is never cleared by a worker.
CREATE FUNCTION portal_commercial_ci.note_unknown_lab_a(
  p_submission uuid,p_claim_version bigint
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog
AS $$
DECLARE updated_sub uuid;
BEGIN
  IF p_submission IS NULL OR p_claim_version IS NULL OR p_claim_version<1 THEN
    RETURN false;
  END IF;
  UPDATE portal_commercial_ci.delivery_gate
  SET state='unknown',lease_until=NULL,
      updated_at=transaction_timestamp()
  WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
    AND submission_reference=p_submission
    AND state='claimed'
    AND claim_version=p_claim_version
  RETURNING submission_reference INTO updated_sub;
  RETURN updated_sub IS NOT NULL;
END;
$$;

REVOKE ALL ON FUNCTION portal_commercial_ci.claim_lab_a(uuid,bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION portal_commercial_ci.note_unknown_lab_a(uuid,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_commercial_ci.claim_lab_a(uuid,bigint)
  TO portal_f122_worker_a;
GRANT EXECUTE ON FUNCTION portal_commercial_ci.note_unknown_lab_a(uuid,bigint)
  TO portal_f122_worker_a;
COMMIT;

COMMENT ON TABLE portal_commercial_ci.delivery_gate IS
  'F122 metadata-only CI hold: unknown result forbids retries; not a CRM receipt.';
