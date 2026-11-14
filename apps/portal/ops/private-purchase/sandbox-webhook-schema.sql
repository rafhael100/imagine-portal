BEGIN;
DO $$ BEGIN IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF;
IF NOT EXISTS(SELECT FROM pg_roles WHERE rolname='purchase_rehearsal_hook') THEN
 CREATE ROLE purchase_rehearsal_hook NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
END IF; END $$;
CREATE TABLE IF NOT EXISTS purchase_rehearsal.sandbox_webhook_inbox (
 event_hash text PRIMARY KEY CHECK(event_hash~'^[a-f0-9]{64}$'),
 event_type text NOT NULL CHECK(event_type~'^[A-Z_]{1,64}$'),
 resource_ref text NOT NULL CHECK(resource_ref~'^(pay|sub)_[A-Za-z0-9_]{1,100}$'),
 subscription_ref text CHECK(subscription_ref~'^sub_[A-Za-z0-9_]{1,100}$'),
 external_ref text CHECK(external_ref~'^imagine:TEST-[a-f0-9]{32}:(initial|setup-2|setup-3|monthly)$'),
 received_at timestamptz NOT NULL DEFAULT now(), attempts integer NOT NULL DEFAULT 0, next_attempt_at timestamptz NOT NULL DEFAULT now(), processed_at timestamptz
);
ALTER TABLE purchase_rehearsal.sandbox_webhook_inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.sandbox_webhook_inbox FORCE ROW LEVEL SECURITY;
DO $$ BEGIN IF NOT EXISTS(SELECT FROM pg_policies WHERE schemaname='purchase_rehearsal' AND tablename='sandbox_webhook_inbox' AND policyname='sandbox_hook_scope') THEN
 CREATE POLICY sandbox_hook_scope ON purchase_rehearsal.sandbox_webhook_inbox FOR ALL USING(current_user IN ('purchase_rehearsal_hook','purchase_rehearsal_psp')) WITH CHECK(current_user IN ('purchase_rehearsal_hook','purchase_rehearsal_psp'));
END IF; END $$;
GRANT USAGE ON SCHEMA purchase_rehearsal TO purchase_rehearsal_hook;
GRANT SELECT ON purchase_rehearsal.sandbox_webhook_inbox TO purchase_rehearsal_hook,purchase_rehearsal_psp;
GRANT INSERT(event_hash,event_type,resource_ref,subscription_ref,external_ref) ON purchase_rehearsal.sandbox_webhook_inbox TO purchase_rehearsal_hook;
GRANT UPDATE(processed_at,attempts,next_attempt_at) ON purchase_rehearsal.sandbox_webhook_inbox TO purchase_rehearsal_psp;
CREATE TABLE IF NOT EXISTS purchase_rehearsal.sandbox_subscription_invoices (
 payment_ref text PRIMARY KEY CHECK(payment_ref~'^pay_[A-Za-z0-9_]{1,100}$'),
 order_id text NOT NULL REFERENCES purchase_rehearsal.sandbox_payment_intents(order_id),
 amount_cents integer NOT NULL CHECK(amount_cents BETWEEN 1 AND 100000000), due_date date NOT NULL,
 state text NOT NULL CHECK(state IN ('pending','approved','reversed')),
 provider_status text NOT NULL, invoice_url text, updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE purchase_rehearsal.sandbox_subscription_invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.sandbox_subscription_invoices FORCE ROW LEVEL SECURITY;
DO $$ BEGIN IF NOT EXISTS(SELECT FROM pg_policies WHERE schemaname='purchase_rehearsal' AND tablename='sandbox_subscription_invoices' AND policyname='sandbox_invoice_scope') THEN
 CREATE POLICY sandbox_invoice_scope ON purchase_rehearsal.sandbox_subscription_invoices FOR ALL USING(current_user IN ('purchase_rehearsal_a','purchase_rehearsal_psp')) WITH CHECK(current_user IN ('purchase_rehearsal_a','purchase_rehearsal_psp'));
END IF; END $$;
GRANT SELECT ON purchase_rehearsal.sandbox_subscription_invoices TO purchase_rehearsal_a,purchase_rehearsal_psp;
GRANT INSERT ON purchase_rehearsal.sandbox_subscription_invoices TO purchase_rehearsal_psp;
GRANT UPDATE(state,provider_status,invoice_url,updated_at) ON purchase_rehearsal.sandbox_subscription_invoices TO purchase_rehearsal_psp;
COMMIT;
