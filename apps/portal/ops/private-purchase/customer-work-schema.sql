-- Dedicated synthetic database only. Additive: preserves existing customers/orders.
BEGIN;
DO $$ BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF;
END $$;
ALTER TABLE purchase_rehearsal.customer_orders ADD COLUMN IF NOT EXISTS work_status text NOT NULL DEFAULT 'queued' CHECK(work_status IN ('queued','in_production','awaiting_approval','changes_requested','completed'));
ALTER TABLE purchase_rehearsal.customer_orders ADD COLUMN IF NOT EXISTS delivery_note text;
CREATE TABLE IF NOT EXISTS purchase_rehearsal.customer_order_events (
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 revision integer NOT NULL CHECK(revision>0), actor_kind text NOT NULL CHECK(actor_kind IN ('admin','client')),
 action text NOT NULL CHECK(action IN ('start','deliver','approve','request_changes','message')),
 note text NOT NULL CHECK(length(note)<=1000), created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(order_id,revision)
);
ALTER TABLE purchase_rehearsal.customer_order_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.customer_order_events FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
 IF NOT EXISTS(SELECT FROM pg_policies WHERE schemaname='purchase_rehearsal' AND tablename='customer_order_events' AND policyname='preview_event_scope') THEN
 CREATE POLICY preview_event_scope ON purchase_rehearsal.customer_order_events FOR ALL USING(current_user='purchase_rehearsal_a') WITH CHECK(current_user='purchase_rehearsal_a');
 END IF;
END $$;
GRANT SELECT,INSERT ON purchase_rehearsal.customer_order_events TO purchase_rehearsal_a;
COMMIT;
