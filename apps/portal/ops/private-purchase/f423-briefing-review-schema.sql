-- F423 / #425: CI-only review decision by the authenticated synthetic buyer.
-- This is NOT provider consent, an Ads account mandate, or a Performance plan approval.
BEGIN;
DO $guard$
BEGIN
  IF current_database() <> 'portal_purchase_rehearsal'
    OR current_user <> 'postgres'
    OR current_setting('server_version_num')::int < 180000
    OR current_setting('server_version_num')::int >= 190000
    OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='purchase_rehearsal_a'
       AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreaterole AND NOT rolcreatedb)
  THEN RAISE EXCEPTION 'F423 CI-only schema destination/role failed closed'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_briefing_reviews (
  customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
  order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
  order_revision integer NOT NULL CHECK(order_revision > 0),
  briefing_sha256 text NOT NULL CHECK(briefing_sha256 ~ '^[a-f0-9]{64}$'),
  approved_by text NOT NULL CHECK(approved_by ~ '^demo-[a-z0-9-]{3,40}$'),
  approval_kind text NOT NULL DEFAULT 'ci_buyer_briefing_review'
    CHECK(approval_kind='ci_buyer_briefing_review'),
  approved_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY(customer_id, order_id, order_revision)
);
ALTER TABLE purchase_rehearsal.f423_briefing_reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_briefing_reviews FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_review_scoped_read ON purchase_rehearsal.f423_briefing_reviews
  FOR SELECT TO purchase_rehearsal_a
  USING (purchase_rehearsal.preview_order_allowed(customer_id)
    AND EXISTS (SELECT 1 FROM purchase_rehearsal.customer_orders o
      WHERE o.id=order_id AND o.customer_id=customer_id AND o.product='performance'));
CREATE POLICY f423_review_scoped_insert ON purchase_rehearsal.f423_briefing_reviews
  FOR INSERT TO purchase_rehearsal_a
  WITH CHECK (purchase_rehearsal.preview_order_allowed(customer_id)
    AND EXISTS (SELECT 1 FROM purchase_rehearsal.customer_orders o
      WHERE o.id=order_id AND o.customer_id=customer_id AND o.product='performance'
        AND o.revision=order_revision AND o.briefing IS NOT NULL
        AND o.payment='approved' AND o.payment_source='asaas_sandbox'
        AND NOT o.payment_hold
        AND EXISTS (SELECT 1 FROM purchase_rehearsal.sandbox_payment_intents i
                    WHERE i.order_id=o.id AND i.state='approved')));
REVOKE ALL ON purchase_rehearsal.f423_briefing_reviews FROM PUBLIC;
GRANT SELECT, INSERT ON purchase_rehearsal.f423_briefing_reviews TO purchase_rehearsal_a;
-- Intentionally no UPDATE/DELETE, no S2S principal, no credential, no billable status.
COMMIT;
