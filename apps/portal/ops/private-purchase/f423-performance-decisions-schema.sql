-- Disposable F423-only Performance decision ledger; NO campaign, consent or account grants.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal'
   OR current_user<>'postgres'
   OR current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999
   OR to_regclass('purchase_rehearsal.f423_briefing_reviews') IS NULL
   OR to_regclass('purchase_rehearsal.f423_performance_decisions') IS NOT NULL
 THEN RAISE EXCEPTION 'F423 decisions: destination is not a new disposable PG18'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_performance_decisions (
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 order_revision integer NOT NULL CHECK(order_revision>0),
 briefing_sha256 text NOT NULL CHECK(briefing_sha256 ~ '^[a-f0-9]{64}$'),
 provider text NOT NULL CHECK(provider IN('google_ads','meta_ads')),
 media_cents integer NOT NULL CHECK(media_cents>=0),
 source_order_alias text NOT NULL CHECK(source_order_alias ~ '^order_[a-f0-9]{32}$'),
 account_fingerprint text NOT NULL CHECK(account_fingerprint ~ '^[a-f0-9]{64}$'),
 decision_state text NOT NULL CHECK(decision_state='blocked'),
 decision_reason text NOT NULL CHECK(decision_reason='mandate_or_s2s_denied'),
 decision_fingerprint text NOT NULL CHECK(decision_fingerprint ~ '^[a-f0-9]{64}$'),
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(customer_id,order_id,order_revision,provider)
);
ALTER TABLE purchase_rehearsal.f423_performance_decisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_performance_decisions FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_decision_scoped_select ON purchase_rehearsal.f423_performance_decisions
 FOR SELECT TO purchase_rehearsal_a
 USING(purchase_rehearsal.preview_order_allowed(customer_id)
   AND EXISTS(SELECT 1 FROM purchase_rehearsal.customer_orders o
     WHERE o.id=order_id AND o.customer_id=f423_performance_decisions.customer_id AND o.product='performance'));
CREATE POLICY f423_decision_scoped_insert ON purchase_rehearsal.f423_performance_decisions
 FOR INSERT TO purchase_rehearsal_a
 WITH CHECK(purchase_rehearsal.preview_order_allowed(customer_id)
  AND EXISTS(SELECT 1 FROM purchase_rehearsal.customer_orders o
     WHERE o.id=order_id AND o.customer_id=f423_performance_decisions.customer_id
       AND o.product='performance' AND o.payment='approved'
       AND o.payment_source='asaas_sandbox' AND NOT o.payment_hold
       AND o.revision=f423_performance_decisions.order_revision)
  AND EXISTS(SELECT 1 FROM purchase_rehearsal.f423_briefing_reviews b
     WHERE b.customer_id=f423_performance_decisions.customer_id
       AND b.order_id=f423_performance_decisions.order_id
       AND b.order_revision=f423_performance_decisions.order_revision
       AND b.briefing_sha256=f423_performance_decisions.briefing_sha256));
REVOKE ALL ON purchase_rehearsal.f423_performance_decisions FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.f423_performance_decisions TO purchase_rehearsal_a;
-- No UPDATE/DELETE/TRUNCATE, no Performance database credential, no Ads write.
COMMIT;
