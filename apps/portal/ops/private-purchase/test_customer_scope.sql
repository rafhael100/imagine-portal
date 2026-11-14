-- CI-only adversarial proof: same-company DB login cannot read another
-- customer's orders by issuing raw SQL, or by spoofing PostgreSQL GUCs.
-- Run AFTER Chromium checkout created orders in portal_purchase_rehearsal.
\set ON_ERROR_STOP on
SELECT count(*) AS baseline_rows FROM purchase_rehearsal.customer_orders;
DO $block$
DECLARE rows_seen bigint;
BEGIN
 SELECT count(*) INTO rows_seen FROM purchase_rehearsal.customer_orders;
 IF rows_seen < 2 THEN RAISE EXCEPTION 'Need at least two real checkout orders for isolation proof'; END IF;
END $block$;

SET SESSION AUTHORIZATION purchase_rehearsal_a;
DO $block$
DECLARE n bigint;
BEGIN
 SELECT count(*) INTO n FROM purchase_rehearsal.customer_orders;
 IF n <> 0 THEN RAISE EXCEPTION 'CRITICAL: shared SQL login sees customer orders with no claim'; END IF;
 PERFORM set_config('imagine.preview_principal','admin',false);
 PERFORM set_config('imagine.preview_mac',repeat('0',64),false);
 SELECT count(*) INTO n FROM purchase_rehearsal.customer_orders;
 IF n <> 0 THEN RAISE EXCEPTION 'CRITICAL: forged admin claim permitted'; END IF;
 PERFORM set_config('imagine.preview_principal','demo-00000000000000000000000000000000',false);
 SELECT count(*) INTO n FROM purchase_rehearsal.customer_orders;
 IF n <> 0 THEN RAISE EXCEPTION 'CRITICAL: forged customer principal permitted'; END IF;
 BEGIN
   PERFORM key FROM purchase_rehearsal.preview_scope_secret;
   RAISE EXCEPTION 'CRITICAL: SQL login can read verifier secret';
 EXCEPTION WHEN insufficient_privilege THEN
   NULL; -- Explicitly expected: the DB-held key is not readable by the app login.
 END;
END $block$;
-- No real orders, payments or contacts were touched.
