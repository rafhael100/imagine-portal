-- P0 synthetic-only Asaas ledger; requires signed customer/order RLS FIRST.
-- A worker on the host may inspect payment metadata but not customer PII or
-- approved offer snapshots. No live PSP, production or customer migration.
BEGIN;
DO $$ BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN
  RAISE EXCEPTION 'Wrong database: disposable preview only';
 END IF;
 IF NOT EXISTS(
  SELECT 1 FROM pg_policies
  WHERE schemaname='purchase_rehearsal'
   AND tablename='customer_orders' AND policyname='customer_order_scope'
   AND qual LIKE '%preview_order_allowed%'
 ) THEN RAISE EXCEPTION 'Signed owner policy required BEFORE Sandbox ledger'; END IF;
 IF NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname='purchase_rehearsal_psp') THEN
  CREATE ROLE purchase_rehearsal_psp NOLOGIN NOSUPERUSER
    NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
 END IF;
END $$;
ALTER TABLE purchase_rehearsal.customer_orders
 ADD COLUMN IF NOT EXISTS payment_source text NOT NULL DEFAULT 'manual_test'
 CHECK(payment_source IN ('manual_test','asaas_sandbox'));
ALTER TABLE purchase_rehearsal.customer_orders
 ADD COLUMN IF NOT EXISTS payment_hold boolean NOT NULL DEFAULT false;

CREATE TABLE IF NOT EXISTS purchase_rehearsal.sandbox_payment_intents (
 order_id text PRIMARY KEY REFERENCES purchase_rehearsal.customer_orders(id),
 state text NOT NULL DEFAULT 'queued'
  CHECK(state IN ('queued','customer_creating','customer_ready','payment_creating','pending','approved','reversed','failed')),
 amount_cents integer NOT NULL CHECK(amount_cents BETWEEN 1 AND 100000000),
 due_date date NOT NULL,
 customer_ref text UNIQUE,
 payment_ref text UNIQUE,
 invoice_url text,
 provider_status text,
 sync_requested boolean NOT NULL DEFAULT true,
 updated_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE purchase_rehearsal.sandbox_payment_intents ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.sandbox_payment_intents FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS sandbox_intent_scope ON purchase_rehearsal.sandbox_payment_intents;
DROP POLICY IF EXISTS sandbox_owner_intent_scope ON purchase_rehearsal.sandbox_payment_intents;
DROP POLICY IF EXISTS sandbox_worker_intent_scope ON purchase_rehearsal.sandbox_payment_intents;
-- The shared SQL app login can only access intentions under a transaction-local
-- HMAC principal verified by the DB, not arbitrary customer_id in HTTP input.
CREATE POLICY sandbox_owner_intent_scope ON purchase_rehearsal.sandbox_payment_intents
 FOR ALL TO purchase_rehearsal_a
 USING(EXISTS(
  SELECT 1 FROM purchase_rehearsal.customer_orders o
  WHERE o.id=order_id AND o.tenant='test-org-a'
    AND purchase_rehearsal.preview_order_allowed(o.customer_id)
 ))
 WITH CHECK(EXISTS(
  SELECT 1 FROM purchase_rehearsal.customer_orders o
  WHERE o.id=order_id AND o.tenant='test-org-a'
    AND purchase_rehearsal.preview_order_allowed(o.customer_id)
 ));
-- Host worker can reconcile all queued Sandbox intents using a distinct
-- NOLOGIN role; app role is NOT a member and cannot set this role.
CREATE POLICY sandbox_worker_intent_scope ON purchase_rehearsal.sandbox_payment_intents
 FOR ALL TO purchase_rehearsal_psp USING(current_user='purchase_rehearsal_psp')
 WITH CHECK(current_user='purchase_rehearsal_psp');
CREATE POLICY sandbox_worker_orders ON purchase_rehearsal.customer_orders
 FOR ALL TO purchase_rehearsal_psp USING(tenant='test-org-a' AND current_user='purchase_rehearsal_psp')
 WITH CHECK(tenant='test-org-a' AND current_user='purchase_rehearsal_psp');

CREATE OR REPLACE FUNCTION purchase_rehearsal.preview_sandbox_intent_count()
RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog
AS $count$
 SELECT CASE WHEN session_user='purchase_rehearsal_a'
 THEN (SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents)
 ELSE 0 END;
$count$;
REVOKE ALL ON FUNCTION purchase_rehearsal.preview_sandbox_intent_count() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION purchase_rehearsal.preview_sandbox_intent_count() TO purchase_rehearsal_a;

REVOKE ALL ON purchase_rehearsal.sandbox_payment_intents FROM PUBLIC;
GRANT SELECT ON purchase_rehearsal.sandbox_payment_intents TO purchase_rehearsal_a,purchase_rehearsal_psp;
GRANT INSERT(order_id,amount_cents,due_date) ON purchase_rehearsal.sandbox_payment_intents TO purchase_rehearsal_a;
GRANT UPDATE(sync_requested) ON purchase_rehearsal.sandbox_payment_intents TO purchase_rehearsal_a;
GRANT UPDATE(state,customer_ref,payment_ref,invoice_url,provider_status,sync_requested,updated_at)
 ON purchase_rehearsal.sandbox_payment_intents TO purchase_rehearsal_psp;
GRANT USAGE ON SCHEMA purchase_rehearsal TO purchase_rehearsal_psp;
GRANT SELECT(id,tenant,payment_source,payment,payment_hold,revision)
 ON purchase_rehearsal.customer_orders TO purchase_rehearsal_psp;
GRANT UPDATE(payment,payment_hold,revision)
 ON purchase_rehearsal.customer_orders TO purchase_rehearsal_psp;
COMMIT;
