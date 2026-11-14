BEGIN;
DO $$ BEGIN IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF; END $$;
CREATE TABLE IF NOT EXISTS purchase_rehearsal.sandbox_future_billing (
 order_id text NOT NULL REFERENCES purchase_rehearsal.sandbox_payment_intents(order_id),
 charge_key text NOT NULL CHECK(charge_key IN ('setup-2','setup-3','monthly')),
 kind text NOT NULL CHECK(kind IN ('setup','monthly')),
 amount_cents integer NOT NULL CHECK(amount_cents BETWEEN 1 AND 100000000),
 due_date date NOT NULL,
 state text NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','payment_creating','pending','approved','reversed','active','inactive')),
 provider_ref text UNIQUE, invoice_url text, provider_status text,
 sync_requested boolean NOT NULL DEFAULT true, updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(order_id,charge_key), CHECK((kind='monthly')=(charge_key='monthly'))
);
ALTER TABLE purchase_rehearsal.sandbox_future_billing ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.sandbox_future_billing FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS sandbox_future_scope ON purchase_rehearsal.sandbox_future_billing;
DROP POLICY IF EXISTS sandbox_future_owner_scope ON purchase_rehearsal.sandbox_future_billing;
DROP POLICY IF EXISTS sandbox_future_worker_scope ON purchase_rehearsal.sandbox_future_billing;
CREATE POLICY sandbox_future_owner_scope ON purchase_rehearsal.sandbox_future_billing
 FOR ALL TO purchase_rehearsal_a
 USING(EXISTS(SELECT 1 FROM purchase_rehearsal.customer_orders o
  WHERE o.id=order_id AND purchase_rehearsal.preview_order_allowed(o.customer_id)))
 WITH CHECK(EXISTS(SELECT 1 FROM purchase_rehearsal.customer_orders o
  WHERE o.id=order_id AND purchase_rehearsal.preview_order_allowed(o.customer_id)));
CREATE POLICY sandbox_future_worker_scope ON purchase_rehearsal.sandbox_future_billing
 FOR ALL TO purchase_rehearsal_psp
 USING(current_user='purchase_rehearsal_psp')
 WITH CHECK(current_user='purchase_rehearsal_psp');
GRANT SELECT ON purchase_rehearsal.sandbox_future_billing TO purchase_rehearsal_a,purchase_rehearsal_psp;
GRANT INSERT(order_id,charge_key,kind,amount_cents,due_date) ON purchase_rehearsal.sandbox_future_billing TO purchase_rehearsal_a;
GRANT UPDATE(sync_requested) ON purchase_rehearsal.sandbox_future_billing TO purchase_rehearsal_a;
GRANT UPDATE(state,provider_ref,invoice_url,provider_status,sync_requested,updated_at) ON purchase_rehearsal.sandbox_future_billing TO purchase_rehearsal_psp;
COMMIT;
