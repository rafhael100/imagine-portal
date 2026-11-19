-- F1-392 ADDITIVE TEST-ONLY PG18 extension over F1-391 disposable inbox.
-- NEVER apply this file to Leaseweb or production. CI synthetic credentials.
\set ON_ERROR_STOP on
BEGIN;
ALTER TABLE portal_psp.asaas_inbox
  DROP CONSTRAINT asaas_inbox_state_check;
ALTER TABLE portal_psp.asaas_inbox
  ADD CONSTRAINT asaas_inbox_state_check
  CHECK (state IN (
    'pending_reconciliation','reconciled','disputed',
    'claimed','retry_wait','observed_psp_received','manual_review'
  ));
ALTER TABLE portal_psp.asaas_inbox
  ADD COLUMN attempts integer NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 8),
  ADD COLUMN lease_token uuid,
  ADD COLUMN lease_expires_at timestamptz,
  ADD COLUMN retry_after timestamptz,
  ADD COLUMN last_checked_at timestamptz;
ALTER TABLE portal_psp.asaas_inbox
  ADD CONSTRAINT asaas_lease_shape CHECK (
    (state='claimed' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL)
    OR (state<>'claimed' AND lease_token IS NULL AND lease_expires_at IS NULL)
  );
CREATE INDEX asaas_inbox_pending_ci_idx
  ON portal_psp.asaas_inbox (account_ref, retry_after, received_at, event_id)
  WHERE state IN ('pending_reconciliation','retry_wait','claimed');
CREATE ROLE asaas_reconcile_worker LOGIN
  PASSWORD 'CI-ONLY-F392-WORKER-IS-NOT-REAL'
  NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION;
GRANT CONNECT ON DATABASE portal_asaas_ci TO asaas_reconcile_worker;
GRANT USAGE ON SCHEMA portal_psp TO asaas_reconcile_worker;
GRANT SELECT ON portal_psp.asaas_inbox TO asaas_reconcile_worker;
GRANT UPDATE (
    state,attempts,lease_token,lease_expires_at,retry_after,last_checked_at
  ) ON portal_psp.asaas_inbox TO asaas_reconcile_worker;
CREATE POLICY asaas_worker_read_ci ON portal_psp.asaas_inbox
  FOR SELECT TO asaas_reconcile_worker
  USING (account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'::uuid
         AND current_user='asaas_reconcile_worker');
CREATE POLICY asaas_worker_update_ci ON portal_psp.asaas_inbox
  FOR UPDATE TO asaas_reconcile_worker
  USING (account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'::uuid
         AND current_user='asaas_reconcile_worker')
  WITH CHECK (account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'::uuid
              AND current_user='asaas_reconcile_worker');
COMMIT;
