-- Run on disposable portal_purchase_rehearsal only, after browser work cycle.
\set ON_ERROR_STOP on
DO $do$
DECLARE n bigint;
BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF;
 SELECT count(*) INTO n FROM purchase_rehearsal.customer_order_events;
 IF n < 7 THEN RAISE EXCEPTION 'Expected synthetic delivery events, found %',n; END IF;
 IF NOT EXISTS (
   SELECT 1 FROM pg_class WHERE oid='purchase_rehearsal.customer_order_events'::regclass
   AND relrowsecurity AND relforcerowsecurity
 ) THEN RAISE EXCEPTION 'Delivery events missing FORCE RLS'; END IF;
 IF NOT EXISTS (
   SELECT 1 FROM pg_policies WHERE schemaname='purchase_rehearsal'
   AND tablename='customer_order_events'
   AND policyname='customer_order_event_scope'
   AND qual LIKE '%preview_order_allowed%'
   AND with_check LIKE '%preview_order_allowed%'
 ) THEN RAISE EXCEPTION 'Signed event policy missing'; END IF;
END
$do$;
SET ROLE purchase_rehearsal_a;
DO $deny$
BEGIN
 IF EXISTS(SELECT 1 FROM purchase_rehearsal.customer_order_events) THEN
  RAISE EXCEPTION 'Restricted role must not enumerate event rows';
 END IF;
 PERFORM set_config('imagine.preview_principal','admin',false);
 PERFORM set_config('imagine.preview_mac','forged',false);
 IF EXISTS(SELECT 1 FROM purchase_rehearsal.customer_order_events) THEN
  RAISE EXCEPTION 'Forged admin claim enumerated customer events';
 END IF;
END
$deny$;
RESET ROLE;
