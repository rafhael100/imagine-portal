-- Additive, dedicated synthetic database only. Old orders intentionally stay unpriced.
BEGIN;
DO $$ BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF;
END $$;
ALTER TABLE purchase_rehearsal.customer_orders ADD COLUMN IF NOT EXISTS offer_snapshot jsonb;
ALTER TABLE purchase_rehearsal.customer_orders ADD COLUMN IF NOT EXISTS offer_accepted_at timestamptz;
DO $$ BEGIN
 IF NOT EXISTS(SELECT FROM pg_constraint WHERE conname='customer_offer_snapshot_pair' AND conrelid='purchase_rehearsal.customer_orders'::regclass) THEN
 ALTER TABLE purchase_rehearsal.customer_orders ADD CONSTRAINT customer_offer_snapshot_pair CHECK ((offer_snapshot IS NULL)=(offer_accepted_at IS NULL));
 END IF;
END $$;
COMMIT;
