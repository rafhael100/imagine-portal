-- CI-only: sandbox charge intents must require the exact DB-signed customer.
\set ON_ERROR_STOP on
DO $test$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF;
 IF NOT EXISTS(SELECT 1 FROM pg_policies WHERE schemaname='purchase_rehearsal'
  AND tablename='sandbox_payment_intents' AND policyname='sandbox_owner_intent_scope'
  AND qual LIKE '%preview_order_allowed%'
  AND with_check LIKE '%preview_order_allowed%') THEN
   RAISE EXCEPTION 'Signed HMAC intent policy missing';
 END IF;
 IF NOT EXISTS(SELECT 1 FROM pg_class WHERE oid='purchase_rehearsal.sandbox_payment_intents'::regclass
  AND relrowsecurity AND relforcerowsecurity) THEN RAISE EXCEPTION 'Sandbox intents must FORCE RLS'; END IF;
 IF (SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents)<1 THEN
  RAISE EXCEPTION 'No Chromium Sandbox intent to test';
 END IF;
 IF pg_has_role('purchase_rehearsal_a','purchase_rehearsal_psp','member') THEN
  RAISE EXCEPTION 'Application login must not inherit PSP worker role';
 END IF;
END $test$;
SET SESSION AUTHORIZATION purchase_rehearsal_a;
DO $deny$
DECLARE n integer;
BEGIN
 SELECT count(*) INTO n FROM purchase_rehearsal.sandbox_payment_intents;
 IF n<>0 THEN RAISE EXCEPTION 'CRITICAL: unscoped SQL enumerates charge intents'; END IF;
 PERFORM set_config('imagine.preview_principal','admin',false);
 PERFORM set_config('imagine.preview_mac',repeat('0',64),false);
 SELECT count(*) INTO n FROM purchase_rehearsal.sandbox_payment_intents;
 IF n<>0 THEN RAISE EXCEPTION 'CRITICAL: forged SQL admin enumerates charge intents'; END IF;
END $deny$;
RESET SESSION AUTHORIZATION;

-- Verify the worker on a genuine SQL identity (current_user=PSP) sees ONLY
-- its approved metadata rows and does not need the app's HMAC function
-- or customer_id visibility. This query must fail the workflow on any RLS
-- permission regression; it must NOT bypass policies with owner credentials.
DO $scopes$
DECLARE
  app_policy_roles name[];
  worker_policy_roles name[];
  order_policy_roles name[];
BEGIN
  SELECT roles INTO app_policy_roles FROM pg_policies
    WHERE schemaname='purchase_rehearsal'
      AND tablename='sandbox_payment_intents'
      AND policyname='sandbox_owner_intent_scope';
  SELECT roles INTO worker_policy_roles FROM pg_policies
    WHERE schemaname='purchase_rehearsal'
      AND tablename='sandbox_payment_intents'
      AND policyname='sandbox_worker_intent_scope';
  SELECT roles INTO order_policy_roles FROM pg_policies
    WHERE schemaname='purchase_rehearsal'
      AND tablename='customer_orders'
      AND policyname='customer_order_scope';
  IF app_policy_roles<>ARRAY['purchase_rehearsal_a']::name[]
     OR worker_policy_roles<>ARRAY['purchase_rehearsal_psp']::name[]
     OR order_policy_roles<>ARRAY['purchase_rehearsal_a']::name[] THEN
    RAISE EXCEPTION 'Role-scoped owner/PSP RLS must be disjoint';
  END IF;
END $scopes$;

SET ROLE purchase_rehearsal_psp;
DO $psp$
DECLARE
  n bigint;
BEGIN
  SELECT count(*) INTO n
    FROM purchase_rehearsal.sandbox_payment_intents
   WHERE sync_requested;
  IF n<1 THEN RAISE EXCEPTION 'Worker cannot read pending sandbox intents'; END IF;
  SELECT count(*) INTO n
    FROM purchase_rehearsal.customer_orders
   WHERE tenant='test-org-a' AND payment_source='asaas_sandbox';
  IF n<1 THEN RAISE EXCEPTION 'Worker cannot read scoped payment status'; END IF;
END $psp$;
RESET ROLE;
