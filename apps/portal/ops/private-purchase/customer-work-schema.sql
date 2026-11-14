-- P0 synthetic-only migration; apply to a NEW disposable PG18 database in CI.
-- Never run on a customer or production database without an independent DBA gate.
BEGIN;
DO $$ BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN
  RAISE EXCEPTION 'Wrong database: synthetic purchase preview only';
 END IF;
 IF NOT EXISTS(
  SELECT 1 FROM pg_policies
  WHERE schemaname='purchase_rehearsal'
    AND tablename='customer_orders'
    AND policyname='customer_order_scope'
    AND qual LIKE '%preview_order_allowed%'
 ) THEN RAISE EXCEPTION 'Signed customer-order policy must be installed first';
 END IF;
END $$;
ALTER TABLE purchase_rehearsal.customer_orders
 ADD COLUMN IF NOT EXISTS work_status text NOT NULL DEFAULT 'queued'
  CHECK(work_status IN ('queued','in_production','awaiting_approval','changes_requested','completed'));
ALTER TABLE purchase_rehearsal.customer_orders
 ADD COLUMN IF NOT EXISTS delivery_note text;
CREATE TABLE IF NOT EXISTS purchase_rehearsal.customer_order_events (
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 revision integer NOT NULL CHECK(revision>0),
 actor_kind text NOT NULL CHECK(actor_kind IN ('admin','client')),
 action text NOT NULL CHECK(action IN ('start','deliver','approve','request_changes','message')),
 note text NOT NULL CHECK(length(note)<=1000),
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(order_id,revision)
);
ALTER TABLE purchase_rehearsal.customer_order_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.customer_order_events FORCE ROW LEVEL SECURITY;
-- The restricted company role MUST NOT enumerate events without the same
-- DB-verified, transaction-local HMAC that protects the parent order.
DROP POLICY IF EXISTS customer_order_event_scope ON purchase_rehearsal.customer_order_events;
CREATE POLICY customer_order_event_scope
 ON purchase_rehearsal.customer_order_events FOR ALL
 USING(EXISTS(
  SELECT 1 FROM purchase_rehearsal.customer_orders o
  WHERE o.id=order_id
    AND o.tenant='test-org-a'
    AND purchase_rehearsal.preview_order_allowed(o.customer_id)
 ))
 WITH CHECK(EXISTS(
  SELECT 1 FROM purchase_rehearsal.customer_orders o
  WHERE o.id=order_id
    AND o.tenant='test-org-a'
    AND purchase_rehearsal.preview_order_allowed(o.customer_id)
 ));
REVOKE ALL ON purchase_rehearsal.customer_order_events FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.customer_order_events TO purchase_rehearsal_a;
COMMIT;
