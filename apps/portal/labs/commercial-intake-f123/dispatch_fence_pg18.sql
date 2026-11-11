-- PORTAL F123: GitHub-hosted PG18 CI ONLY.
-- A lease alone is not a safe indication that HTTP has not started.
-- Dispatching is an irreversible local boundary until trusted reconciliation.
BEGIN;
DO $$
BEGIN
  IF current_database() <> 'portal_commercial_lab'
     OR to_regclass('portal_commercial_ci.delivery_gate') IS NULL
     OR to_regprocedure('portal_commercial_ci.claim_lab_a(uuid,bigint)') IS NULL
  THEN
    RAISE EXCEPTION 'F123 refuses non-Fixture database / missing F122';
  END IF;
END;
$$;
ALTER TABLE portal_commercial_ci.delivery_gate
  DROP CONSTRAINT delivery_gate_state_check,
  ADD CONSTRAINT delivery_gate_state_check
    CHECK (state IN ('claimed','dispatching','unknown')),
  DROP CONSTRAINT delivery_gate_check,
  ADD CONSTRAINT delivery_gate_check
    CHECK ((state IN ('claimed','dispatching') AND lease_until IS NOT NULL)
        OR (state='unknown' AND lease_until IS NULL));

-- Called immediately BEFORE the first HTTP byte could leave the worker.
-- A lease that expired before dispatch may be reclaimed by F122; once
-- dispatching, it is NEVER automatically reclaimable, even when expired.
CREATE FUNCTION portal_commercial_ci.begin_dispatch_lab_a(
  p_submission uuid,p_claim_version bigint
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog
AS $$
DECLARE marked uuid;
BEGIN
  IF p_submission IS NULL OR p_claim_version IS NULL
     OR p_claim_version<1 THEN
    RETURN false;
  END IF;
  UPDATE portal_commercial_ci.delivery_gate
     SET state='dispatching',updated_at=transaction_timestamp()
   WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
     AND submission_reference=p_submission
     AND state='claimed'
     AND claim_version=p_claim_version
     AND lease_until>transaction_timestamp()
  RETURNING submission_reference INTO marked;
  RETURN marked IS NOT NULL;
END;
$$;

-- Preserve F122's conservative option to mark uncertainty before sending.
-- Also accept uncertainty after dispatch started, including expired leases
-- whose claim_version has not changed. Never acknowledge CRM persistence.
CREATE OR REPLACE FUNCTION portal_commercial_ci.note_unknown_lab_a(
  p_submission uuid,p_claim_version bigint
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog
AS $$
DECLARE marked uuid;
BEGIN
  IF p_submission IS NULL OR p_claim_version IS NULL
     OR p_claim_version<1 THEN
    RETURN false;
  END IF;
  UPDATE portal_commercial_ci.delivery_gate
     SET state='unknown',lease_until=NULL,
         updated_at=transaction_timestamp()
   WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
     AND submission_reference=p_submission
     AND state IN ('claimed','dispatching')
     AND claim_version=p_claim_version
  RETURNING submission_reference INTO marked;
  RETURN marked IS NOT NULL;
END;
$$;

REVOKE ALL ON FUNCTION portal_commercial_ci.begin_dispatch_lab_a(uuid,bigint)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_commercial_ci.begin_dispatch_lab_a(uuid,bigint)
  TO portal_f122_worker_a;
REVOKE ALL ON FUNCTION portal_commercial_ci.note_unknown_lab_a(uuid,bigint)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_commercial_ci.note_unknown_lab_a(uuid,bigint)
  TO portal_f122_worker_a;
COMMENT ON FUNCTION portal_commercial_ci.begin_dispatch_lab_a(uuid,bigint)
  IS 'F123 CI-only point of no automatic retry before simulated HTTP; never a delivery receipt';
COMMIT;
