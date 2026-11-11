-- PORTAL-F125 — metadata-only manual review queue, PostgreSQL 18 CI only.
-- Consumes the disposable F122/F123 gate; no production migration, no ACK.
BEGIN;
DO $$
BEGIN
  IF current_database() <> 'portal_commercial_lab'
      OR to_regclass('portal_commercial_ci.delivery_gate') IS NULL
      OR to_regprocedure('portal_commercial_ci.begin_dispatch_lab_a(uuid,bigint)') IS NULL
      OR to_regrole('portal_f122_worker_a') IS NULL
      OR to_regrole('portal_f122_worker_b') IS NULL THEN
    RAISE EXCEPTION 'F125 requires disposable F122/F123 test fixture';
  END IF;
END
$$;

-- security_invoker=true is essential: caller's table GRANT and tenant RLS
-- apply to the base F122 delivery_gate. Never use a superuser-owned
-- default-security view, which could bypass tenant RLS.
CREATE VIEW portal_commercial_ci.delivery_review_queue
WITH (security_invoker = true, security_barrier = true) AS
SELECT
  organization_reference,
  submission_reference,
  claim_version,
  attempts,
  updated_at,
  CASE
    WHEN state = 'unknown' THEN 'unknown_outcome'
    WHEN state = 'dispatching' THEN 'expired_dispatch_may_have_committed'
    WHEN state = 'claimed' THEN 'unsent_attempt_limit_reached'
  END::text AS review_reason,
  true::boolean AS requires_manual_review,
  false::boolean AS automatic_retry_allowed,
  false::boolean AS real_crm_persisted_verified,
  false::boolean AS can_acknowledge_visitor
FROM portal_commercial_ci.delivery_gate
WHERE
    state = 'unknown'
    OR (state = 'dispatching' AND lease_until <= transaction_timestamp())
    OR (state = 'claimed' AND attempts >= 3
        AND lease_until <= transaction_timestamp());

REVOKE ALL ON portal_commercial_ci.delivery_review_queue FROM PUBLIC;
GRANT SELECT ON portal_commercial_ci.delivery_review_queue
  TO portal_f122_worker_a, portal_f122_worker_b;
COMMENT ON VIEW portal_commercial_ci.delivery_review_queue IS
  'F125 CI-only tenant-RLS read-only list. Not a CRM receipt, retry approval, or authorization to disclose data.';
COMMIT;
