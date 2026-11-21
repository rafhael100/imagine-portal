-- Synthetic OFFLINE application receipt. Never a campaign operation or observed gain.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal'
   OR current_user<>'postgres'
   OR current_setting('server_version_num')::int NOT BETWEEN 180000 AND 189999
   OR to_regclass('purchase_rehearsal.f423_google_improvement_approvals') IS NULL
   OR to_regclass('purchase_rehearsal.f423_google_offline_applied_results') IS NOT NULL
 THEN RAISE EXCEPTION 'F423 offline application requires fresh disposable PG18'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_google_offline_applied_results (
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 order_revision integer NOT NULL CHECK(order_revision>0),
 briefing_sha256 text NOT NULL CHECK(briefing_sha256 ~ '^[a-f0-9]{64}$'),
 provider text NOT NULL DEFAULT 'google_ads' CHECK(provider='google_ads'),
 plan_version integer NOT NULL CHECK(plan_version=1),
 proposal_version integer NOT NULL CHECK(proposal_version=1),
 proposal_fingerprint text NOT NULL CHECK(proposal_fingerprint ~ '^[a-f0-9]{64}$'),
 draft_fingerprint text NOT NULL CHECK(draft_fingerprint ~ '^[a-f0-9]{64}$'),
 evidence_sha256 text NOT NULL CHECK(evidence_sha256 ~ '^[a-f0-9]{64}$'),
 post_sha256 text NOT NULL CHECK(post_sha256 ~ '^[a-f0-9]{64}$'),
 result_fingerprint text NOT NULL CHECK(result_fingerprint ~ '^[a-f0-9]{64}$'),
 application_state text NOT NULL CHECK(application_state='ci_offline_fixture_applied'),
 post_samples jsonb NOT NULL CHECK(jsonb_typeof(post_samples)='array' AND jsonb_array_length(post_samples)=2),
 before_totals jsonb NOT NULL CHECK(jsonb_typeof(before_totals)='object'),
 after_totals jsonb NOT NULL CHECK(jsonb_typeof(after_totals)='object'),
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(customer_id,order_id,order_revision,provider,plan_version,proposal_version),
 FOREIGN KEY(customer_id,order_id,order_revision,provider,plan_version,proposal_version)
   REFERENCES purchase_rehearsal.f423_google_improvement_approvals
    (customer_id,order_id,order_revision,provider,plan_version,proposal_version)
);
ALTER TABLE purchase_rehearsal.f423_google_offline_applied_results ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_google_offline_applied_results FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_offline_application_read ON purchase_rehearsal.f423_google_offline_applied_results
 FOR SELECT TO purchase_rehearsal_a
 USING(purchase_rehearsal.preview_order_allowed(customer_id));
CREATE POLICY f423_offline_application_insert ON purchase_rehearsal.f423_google_offline_applied_results
 FOR INSERT TO purchase_rehearsal_a WITH CHECK (
  purchase_rehearsal.preview_order_allowed(customer_id)
  AND EXISTS(SELECT 1 FROM purchase_rehearsal.f423_google_improvement_approvals a
    JOIN purchase_rehearsal.f423_google_offline_improvements p
      ON p.customer_id=a.customer_id AND p.order_id=a.order_id
     AND p.order_revision=a.order_revision AND p.provider=a.provider
     AND p.plan_version=a.plan_version AND p.proposal_version=a.proposal_version
     AND p.proposal_fingerprint=a.proposal_fingerprint
    WHERE a.customer_id=f423_google_offline_applied_results.customer_id
      AND a.order_id=f423_google_offline_applied_results.order_id
      AND a.order_revision=f423_google_offline_applied_results.order_revision
      AND a.briefing_sha256=f423_google_offline_applied_results.briefing_sha256
      AND a.provider='google_ads'
      AND a.plan_version=f423_google_offline_applied_results.plan_version
      AND a.proposal_version=f423_google_offline_applied_results.proposal_version
      AND a.proposal_fingerprint=f423_google_offline_applied_results.proposal_fingerprint
      AND p.draft_fingerprint=f423_google_offline_applied_results.draft_fingerprint
      AND p.evidence_sha256=f423_google_offline_applied_results.evidence_sha256
      AND p.proposal_state='awaiting_separate_approval')
 );
REVOKE ALL ON purchase_rehearsal.f423_google_offline_applied_results FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.f423_google_offline_applied_results TO purchase_rehearsal_a;
-- No update/delete, approval mutation, Ads call, account permission, spend or publication.
COMMIT;
