-- CI-ONLY disposable versioned Google diagnostic/plan. Never a draft, campaign or Ads authority.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal'
    OR current_user<>'postgres'
    OR current_setting('server_version_num')::int NOT BETWEEN 180000 AND 189999
    OR to_regclass('purchase_rehearsal.f423_performance_decisions') IS NULL
    OR to_regclass('purchase_rehearsal.f423_google_diagnostic_plans') IS NOT NULL
 THEN RAISE EXCEPTION 'F423 plan: not a fresh disposable PG18 with decision ledger'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_google_diagnostic_plans (
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 order_revision integer NOT NULL CHECK (order_revision>0),
 briefing_sha256 text NOT NULL CHECK (briefing_sha256 ~ '^[a-f0-9]{64}$'),
 provider text NOT NULL DEFAULT 'google_ads' CHECK (provider='google_ads'),
 diagnostic_version integer NOT NULL CHECK (diagnostic_version=1),
 plan_version integer NOT NULL CHECK (plan_version=1),
 decision_fingerprint text NOT NULL CHECK (decision_fingerprint ~ '^[a-f0-9]{64}$'),
 metric_origin text NOT NULL CHECK (metric_origin='ci_synthetic_fixture_no_ads'),
 sample_impressions integer NOT NULL CHECK (sample_impressions=1000),
 sample_clicks integer NOT NULL CHECK (sample_clicks=40),
 sample_conversions integer NOT NULL CHECK (sample_conversions=2),
 media_cents integer NOT NULL CHECK (media_cents=100000),
 proposed_action text NOT NULL CHECK (proposed_action='review_search_terms_only'),
 plan_state text NOT NULL CHECK (plan_state='awaiting_separate_approval'),
 plan_fingerprint text NOT NULL CHECK (plan_fingerprint ~ '^[a-f0-9]{64}$'),
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(customer_id,order_id,order_revision,provider,plan_version),
 FOREIGN KEY(customer_id,order_id,order_revision,provider)
   REFERENCES purchase_rehearsal.f423_performance_decisions(customer_id,order_id,order_revision,provider)
);
ALTER TABLE purchase_rehearsal.f423_google_diagnostic_plans ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_google_diagnostic_plans FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_plan_owner_select ON purchase_rehearsal.f423_google_diagnostic_plans
 FOR SELECT TO purchase_rehearsal_a
 USING(purchase_rehearsal.preview_order_allowed(customer_id));
CREATE POLICY f423_plan_owner_insert ON purchase_rehearsal.f423_google_diagnostic_plans
 FOR INSERT TO purchase_rehearsal_a
 WITH CHECK(purchase_rehearsal.preview_order_allowed(customer_id)
   AND EXISTS (SELECT 1 FROM purchase_rehearsal.f423_performance_decisions d
      WHERE d.customer_id=f423_google_diagnostic_plans.customer_id
        AND d.order_id=f423_google_diagnostic_plans.order_id
        AND d.order_revision=f423_google_diagnostic_plans.order_revision
        AND d.briefing_sha256=f423_google_diagnostic_plans.briefing_sha256
        AND d.provider='google_ads'
        AND d.decision_state='internal_review_only'
        AND d.decision_fingerprint=f423_google_diagnostic_plans.decision_fingerprint));
REVOKE ALL ON purchase_rehearsal.f423_google_diagnostic_plans FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.f423_google_diagnostic_plans TO purchase_rehearsal_a;
-- No UPDATE/DELETE, no effective plan approval, no campaign or provider permissions.
COMMIT;
