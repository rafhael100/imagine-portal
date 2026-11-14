-- Sandbox ledger. Run only after customer-offer-schema.sql, never in identity DB.
BEGIN;
DO $$ BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF;
 IF NOT EXISTS(SELECT FROM pg_roles WHERE rolname='purchase_rehearsal_psp') THEN
 CREATE ROLE purchase_rehearsal_psp NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
 END IF;
END $$;
ALTER TABLE purchase_rehearsal.customer_orders ADD COLUMN IF NOT EXISTS payment_source text NOT NULL DEFAULT 'manual_test' CHECK(payment_source IN ('manual_test','asaas_sandbox'));
-- Reversal/chargeback never erases an accepted briefing, but must freeze
-- follow-up and provisioning after independent PSP re-verification.
ALTER TABLE purchase_rehearsal.customer_orders ADD COLUMN IF NOT EXISTS payment_hold boolean NOT NULL DEFAULT false;
CREATE TABLE IF NOT EXISTS purchase_rehearsal.sandbox_payment_intents (
 order_id text PRIMARY KEY REFERENCES purchase_rehearsal.customer_orders(id),
 state text NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','customer_creating','customer_ready','payment_creating','pending','approved','reversed','failed')),
 amount_cents integer NOT NULL CHECK(amount_cents BETWEEN 1 AND 100000000),
 due_date date NOT NULL, customer_ref text UNIQUE, payment_ref text UNIQUE, invoice_url text,
 provider_status text, sync_requested boolean NOT NULL DEFAULT true,
 updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE purchase_rehearsal.sandbox_payment_intents ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.sandbox_payment_intents FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
 IF NOT EXISTS(SELECT FROM pg_policies WHERE schemaname='purchase_rehearsal' AND tablename='sandbox_payment_intents' AND policyname='sandbox_intent_scope') THEN
 CREATE POLICY sandbox_intent_scope ON purchase_rehearsal.sandbox_payment_intents FOR ALL USING(current_user IN ('purchase_rehearsal_a','purchase_rehearsal_psp')) WITH CHECK(current_user IN ('purchase_rehearsal_a','purchase_rehearsal_psp'));
 END IF;
 IF NOT EXISTS(SELECT FROM pg_policies WHERE schemaname='purchase_rehearsal' AND tablename='customer_orders' AND policyname='sandbox_worker_orders') THEN
 CREATE POLICY sandbox_worker_orders ON purchase_rehearsal.customer_orders FOR ALL USING(tenant='test-org-a' AND current_user='purchase_rehearsal_psp') WITH CHECK(tenant='test-org-a' AND current_user='purchase_rehearsal_psp');
 END IF;
END $$;
GRANT USAGE ON SCHEMA purchase_rehearsal TO purchase_rehearsal_psp;
GRANT SELECT ON purchase_rehearsal.sandbox_payment_intents TO purchase_rehearsal_a,purchase_rehearsal_psp;
GRANT INSERT(order_id,amount_cents,due_date) ON purchase_rehearsal.sandbox_payment_intents TO purchase_rehearsal_a;
GRANT UPDATE(sync_requested) ON purchase_rehearsal.sandbox_payment_intents TO purchase_rehearsal_a;
GRANT UPDATE(state,customer_ref,payment_ref,invoice_url,provider_status,sync_requested,updated_at) ON purchase_rehearsal.sandbox_payment_intents TO purchase_rehearsal_psp;
GRANT SELECT(id,tenant,payment_source,payment,payment_hold,revision) ON purchase_rehearsal.customer_orders TO purchase_rehearsal_psp;
GRANT UPDATE(payment,payment_hold,revision) ON purchase_rehearsal.customer_orders TO purchase_rehearsal_psp;
COMMIT;
