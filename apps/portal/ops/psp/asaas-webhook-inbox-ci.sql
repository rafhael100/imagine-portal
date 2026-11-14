-- F1-391 CI-only disposable PG18 inbox for payment notifications.
-- NOT a production migration: privileged setup below is CI-only and
-- uses fictional role/password/account. No PII or raw JSON is stored.
\set ON_ERROR_STOP on
BEGIN;
CREATE SCHEMA portal_psp AUTHORIZATION postgres;
CREATE TABLE portal_psp.asaas_inbox (
    account_ref uuid NOT NULL,
    event_id text NOT NULL CHECK (event_id ~ '^evt_[A-Za-z0-9_&-]{8,128}$'),
    event_type text NOT NULL CHECK (event_type ~ '^PAYMENT_[A-Z_]{3,64}$'),
    payment_id text NOT NULL CHECK (payment_id ~ '^pay_[A-Za-z0-9]{6,64}$'),
    payload_sha256 text NOT NULL CHECK (payload_sha256 ~ '^[a-f0-9]{64}$'),
    received_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    state text NOT NULL DEFAULT 'pending_reconciliation'
        CHECK (state IN ('pending_reconciliation','reconciled','disputed')),
    PRIMARY KEY (account_ref,event_id)
);
ALTER TABLE portal_psp.asaas_inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_psp.asaas_inbox FORCE ROW LEVEL SECURITY;
CREATE ROLE asaas_webhook_runtime WITH LOGIN PASSWORD 'CI-ONLY-NOT-A-REAL-KEY-1234567890'
    NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION;
GRANT CONNECT ON DATABASE portal_asaas_ci TO asaas_webhook_runtime;
GRANT USAGE ON SCHEMA portal_psp TO asaas_webhook_runtime;
GRANT SELECT, INSERT ON portal_psp.asaas_inbox TO asaas_webhook_runtime;
CREATE POLICY asaas_inbox_ci_scope ON portal_psp.asaas_inbox
    FOR ALL TO asaas_webhook_runtime
    USING (account_ref='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'::uuid AND current_user='asaas_webhook_runtime')
    WITH CHECK (account_ref='ci-asaas-sandbox' AND current_user='asaas_webhook_runtime');
COMMIT;
