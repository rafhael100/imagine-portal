-- CI-only: direct SQL access from shared app login cannot enumerate customer
-- invoices, future charges or Performance reports without a valid DB HMAC.
\set ON_ERROR_STOP on
DO $guard$
DECLARE p record; forced boolean; scoped boolean;
BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN
   RAISE EXCEPTION 'Wrong database'; END IF;
 FOR p IN SELECT * FROM (VALUES
   ('sandbox_payment_intents','sandbox_owner_intent_scope'),
   ('sandbox_future_billing','sandbox_future_owner_scope'),
   ('sandbox_subscription_invoices','sandbox_invoice_owner_scope'),
   ('performance_settlement_reports','performance_report_scope')
 ) AS t(tbl,pol) LOOP
  SELECT c.relrowsecurity AND c.relforcerowsecurity INTO forced
   FROM pg_class c WHERE c.oid=('purchase_rehearsal.'||p.tbl)::regclass;
  SELECT qual LIKE '%preview_order_allowed%'
         AND with_check LIKE '%preview_order_allowed%' INTO scoped
   FROM pg_policies WHERE schemaname='purchase_rehearsal'
    AND tablename=p.tbl AND policyname=p.pol;
  IF forced IS DISTINCT FROM true OR scoped IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'CRITICAL: table % is not signed owner FORCE RLS',p.tbl;
  END IF;
 END LOOP;
END $guard$;
SET SESSION AUTHORIZATION purchase_rehearsal_a;
DO $deny$
DECLARE n bigint;
BEGIN
  SELECT count(*) INTO n FROM purchase_rehearsal.sandbox_payment_intents;
  IF n<>0 THEN RAISE EXCEPTION 'CRITICAL unscoped intents leaked'; END IF;
  SELECT count(*) INTO n FROM purchase_rehearsal.sandbox_future_billing;
  IF n<>0 THEN RAISE EXCEPTION 'CRITICAL unscoped future charges leaked'; END IF;
  SELECT count(*) INTO n FROM purchase_rehearsal.sandbox_subscription_invoices;
  IF n<>0 THEN RAISE EXCEPTION 'CRITICAL unscoped subscription invoices leaked'; END IF;
  SELECT count(*) INTO n FROM purchase_rehearsal.performance_settlement_reports;
  IF n<>0 THEN RAISE EXCEPTION 'CRITICAL unscoped Performance reports leaked'; END IF;
  PERFORM set_config('imagine.preview_principal','admin',false);
  PERFORM set_config('imagine.preview_mac',repeat('0',64),false);
  SELECT count(*) INTO n FROM purchase_rehearsal.sandbox_subscription_invoices;
  IF n<>0 THEN RAISE EXCEPTION 'CRITICAL forged admin read invoices'; END IF;
  SELECT count(*) INTO n FROM purchase_rehearsal.performance_settlement_reports;
  IF n<>0 THEN RAISE EXCEPTION 'CRITICAL forged admin read Performance reports'; END IF;
END $deny$;
RESET SESSION AUTHORIZATION;
