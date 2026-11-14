-- Dedicated PostgreSQL18 disposable CI database ONLY.
BEGIN;
DO $$ BEGIN
 IF current_database()<>'portal_asaas_inbox_ci' THEN
  RAISE EXCEPTION 'Forbidden database for isolated Asaas webhook candidate';
 END IF;
END $$;
CREATE SCHEMA IF NOT EXISTS portal_asaas_ci;
CREATE TABLE IF NOT EXISTS portal_asaas_ci.webhook_inbox (
 event_id text PRIMARY KEY CHECK(length(event_id) BETWEEN 1 AND 160),
 event_type text NOT NULL CHECK(length(event_type) BETWEEN 3 AND 80),
 account_id text NOT NULL CHECK(length(account_id) BETWEEN 5 AND 128),
 payment_id text CHECK(payment_id IS NULL OR length(payment_id) BETWEEN 1 AND 128),
 payload_sha256 text NOT NULL CHECK(payload_sha256 ~ '^[a-f0-9]{64}$'),
 review_state text NOT NULL DEFAULT 'pending_external_reconciliation'
  CHECK(review_state='pending_external_reconciliation'),
 received_at timestamptz NOT NULL DEFAULT now()
);
REVOKE ALL ON SCHEMA portal_asaas_ci FROM PUBLIC;
REVOKE ALL ON portal_asaas_ci.webhook_inbox FROM PUBLIC;
COMMIT;
