BEGIN;
DO $$ BEGIN IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF; END $$;
ALTER TABLE purchase_rehearsal.sandbox_future_billing DROP CONSTRAINT IF EXISTS sandbox_future_billing_charge_key_check;
ALTER TABLE purchase_rehearsal.sandbox_future_billing DROP CONSTRAINT IF EXISTS sandbox_future_billing_kind_check;
ALTER TABLE purchase_rehearsal.sandbox_future_billing DROP CONSTRAINT IF EXISTS sandbox_future_billing_check;
DO $$ BEGIN IF NOT EXISTS(SELECT FROM pg_constraint WHERE conrelid='purchase_rehearsal.sandbox_future_billing'::regclass AND conname='future_postpaid_kind_scope') THEN
ALTER TABLE purchase_rehearsal.sandbox_future_billing ADD CONSTRAINT future_postpaid_kind_scope CHECK(
 (kind='setup' AND charge_key IN ('setup-2','setup-3')) OR
 (kind='monthly' AND charge_key='monthly') OR
 (kind='performance' AND charge_key ~ '^performance-20[0-9]{2}-(0[1-9]|1[0-2])$'));
END IF; END $$;
ALTER TABLE purchase_rehearsal.sandbox_webhook_inbox DROP CONSTRAINT IF EXISTS sandbox_webhook_inbox_external_ref_check;
DO $$ BEGIN IF NOT EXISTS(SELECT FROM pg_constraint WHERE conrelid='purchase_rehearsal.sandbox_webhook_inbox'::regclass AND conname='postpaid_webhook_reference_scope') THEN
ALTER TABLE purchase_rehearsal.sandbox_webhook_inbox ADD CONSTRAINT postpaid_webhook_reference_scope CHECK(external_ref ~ '^imagine:TEST-[a-f0-9]{32}:(initial|setup-2|setup-3|monthly|performance-20[0-9]{2}-(0[1-9]|1[0-2]))$');
END IF; END $$;
COMMIT;
