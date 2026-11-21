-- Isolated CI buyer approval of an exact synthetic diagnostic PLAN, not Ads consent.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal'
    OR current_user<>'postgres'
    OR current_setting('server_version_num')::int NOT BETWEEN 180000 AND 189999
    OR to_regclass('purchase_rehearsal.f423_google_diagnostic_plans') IS NULL
    OR to_regclass('purchase_rehearsal.f423_google_plan_approvals') IS NOT NULL
 THEN RAISE EXCEPTION 'F423 plan approval: not a fresh disposable PG18'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_google_plan_approvals (
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 order_revision integer NOT NULL CHECK (order_revision>0),
 briefing_sha256 text NOT NULL CHECK (briefing_sha256 ~ '^[a-f0-9]{64}$'),
 provider text NOT NULL DEFAULT 'google_ads' CHECK(provider='google_ads'),
 plan_version integer NOT NULL CHECK(plan_version=1),
 plan_fingerprint text NOT NULL CHECK(plan_fingerprint ~ '^[a-f0-9]{64}$'),
 approved_by text NOT NULL CHECK(approved_by ~ '^demo-[a-z0-9-]{3,40}$'),
 review_kind text NOT NULL DEFAULT 'ci_explicit_buyer_plan_approval' CHECK(review_kind='ci_explicit_buyer_plan_approval'),
 approved_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(customer_id,order_id,order_revision,provider,plan_version),
 FOREIGN KEY(customer_id,order_id,order_revision,provider,plan_version)
  REFERENCES purchase_rehearsal.f423_google_diagnostic_plans
     (customer_id,order_id,order_revision,provider,plan_version)
);
ALTER TABLE purchase_rehearsal.f423_google_plan_approvals ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_google_plan_approvals FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_plan_approval_owner_read ON purchase_rehearsal.f423_google_plan_approvals
 FOR SELECT TO purchase_rehearsal_a USING(purchase_rehearsal.preview_order_allowed(customer_id));
CREATE POLICY f423_plan_approval_owner_insert ON purchase_rehearsal.f423_google_plan_approvals
 FOR INSERT TO purchase_rehearsal_a
 WITH CHECK(purchase_rehearsal.preview_order_allowed(customer_id)
  AND EXISTS (SELECT 1 FROM purchase_rehearsal.f423_google_diagnostic_plans p
   WHERE p.customer_id=f423_google_plan_approvals.customer_id
    AND p.order_id=f423_google_plan_approvals.order_id
    AND p.order_revision=f423_google_plan_approvals.order_revision
    AND p.briefing_sha256=f423_google_plan_approvals.briefing_sha256
    AND p.provider='google_ads'
    AND p.plan_version=f423_google_plan_approvals.plan_version
    AND p.plan_fingerprint=f423_google_plan_approvals.plan_fingerprint
    AND p.plan_state='awaiting_separate_approval'));
REVOKE ALL ON purchase_rehearsal.f423_google_plan_approvals FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.f423_google_plan_approvals TO purchase_rehearsal_a;
-- No update/delete, no campaign, no Ads permission, no terms acceptance.
COMMIT;
