-- Buyer approval of one OFFLINE improvement proposal. Not Ads consent or application.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal'
   OR current_user<>'postgres'
   OR current_setting('server_version_num')::int NOT BETWEEN 180000 AND 189999
   OR to_regclass('purchase_rehearsal.f423_google_offline_improvements') IS NULL
   OR to_regclass('purchase_rehearsal.f423_google_improvement_approvals') IS NOT NULL
 THEN RAISE EXCEPTION 'F423 improvement approval requires fresh disposable PG18'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_google_improvement_approvals (
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 order_revision integer NOT NULL CHECK(order_revision>0),
 briefing_sha256 text NOT NULL CHECK(briefing_sha256 ~ '^[a-f0-9]{64}$'),
 provider text NOT NULL DEFAULT 'google_ads' CHECK(provider='google_ads'),
 plan_version integer NOT NULL CHECK(plan_version=1),
 proposal_version integer NOT NULL CHECK(proposal_version=1),
 proposal_fingerprint text NOT NULL CHECK(proposal_fingerprint ~ '^[a-f0-9]{64}$'),
 approved_by text NOT NULL CHECK(approved_by ~ '^demo-[a-z0-9-]{3,40}$'),
 approval_kind text NOT NULL DEFAULT 'ci_explicit_buyer_offline_improvement'
   CHECK(approval_kind='ci_explicit_buyer_offline_improvement'),
 approved_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(customer_id,order_id,order_revision,provider,plan_version,proposal_version),
 FOREIGN KEY(customer_id,order_id,order_revision,provider,plan_version,proposal_version)
  REFERENCES purchase_rehearsal.f423_google_offline_improvements
   (customer_id,order_id,order_revision,provider,plan_version,proposal_version)
);
ALTER TABLE purchase_rehearsal.f423_google_improvement_approvals ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_google_improvement_approvals FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_improvement_approval_read ON purchase_rehearsal.f423_google_improvement_approvals
 FOR SELECT TO purchase_rehearsal_a
 USING(purchase_rehearsal.preview_order_allowed(customer_id));
CREATE POLICY f423_improvement_approval_insert ON purchase_rehearsal.f423_google_improvement_approvals
 FOR INSERT TO purchase_rehearsal_a WITH CHECK(
   purchase_rehearsal.preview_order_allowed(customer_id)
   AND EXISTS(SELECT 1 FROM purchase_rehearsal.f423_google_offline_improvements p
     WHERE p.customer_id=f423_google_improvement_approvals.customer_id
      AND p.order_id=f423_google_improvement_approvals.order_id
      AND p.order_revision=f423_google_improvement_approvals.order_revision
      AND p.briefing_sha256=f423_google_improvement_approvals.briefing_sha256
      AND p.provider='google_ads'
      AND p.plan_version=f423_google_improvement_approvals.plan_version
      AND p.proposal_version=f423_google_improvement_approvals.proposal_version
      AND p.proposal_fingerprint=f423_google_improvement_approvals.proposal_fingerprint
      AND p.proposal_state='awaiting_separate_approval')
   AND EXISTS(SELECT 1 FROM purchase_rehearsal.f423_google_plan_approvals a
     WHERE a.customer_id=f423_google_improvement_approvals.customer_id
       AND a.order_id=f423_google_improvement_approvals.order_id
       AND a.order_revision=f423_google_improvement_approvals.order_revision
       AND a.briefing_sha256=f423_google_improvement_approvals.briefing_sha256
       AND a.provider='google_ads'
       AND a.plan_version=f423_google_improvement_approvals.plan_version
       AND a.approved_by=f423_google_improvement_approvals.approved_by)
   AND EXISTS(SELECT 1 FROM purchase_rehearsal.f423_briefing_reviews b
     WHERE b.customer_id=f423_google_improvement_approvals.customer_id
       AND b.order_id=f423_google_improvement_approvals.order_id
       AND b.order_revision=f423_google_improvement_approvals.order_revision
       AND b.briefing_sha256=f423_google_improvement_approvals.briefing_sha256
       AND b.approved_by=f423_google_improvement_approvals.approved_by)
 );
REVOKE ALL ON purchase_rehearsal.f423_google_improvement_approvals FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.f423_google_improvement_approvals TO purchase_rehearsal_a;
-- Immutable approval event only. No UPDATE, DELETE, Google API, activation or Ads rights.
COMMIT;
