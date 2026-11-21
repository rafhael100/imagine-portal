-- Proposal only, derived from two OFFLINE fixture samples. NOT an Ads action.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal'
    OR current_user<>'postgres'
    OR current_setting('server_version_num')::int NOT BETWEEN 180000 AND 189999
    OR to_regclass('purchase_rehearsal.f423_google_monitoring_samples') IS NULL
    OR to_regclass('purchase_rehearsal.f423_google_offline_improvements') IS NOT NULL
 THEN RAISE EXCEPTION 'F423 improvement requires fresh disposable PG18 with monitoring ledger'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_google_offline_improvements (
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 order_revision integer NOT NULL CHECK(order_revision>0),
 briefing_sha256 text NOT NULL CHECK(briefing_sha256 ~ '^[a-f0-9]{64}$'),
 provider text NOT NULL DEFAULT 'google_ads' CHECK(provider='google_ads'),
 plan_version integer NOT NULL CHECK(plan_version=1),
 proposal_version integer NOT NULL CHECK(proposal_version=1),
 plan_fingerprint text NOT NULL CHECK(plan_fingerprint ~ '^[a-f0-9]{64}$'),
 draft_fingerprint text NOT NULL CHECK(draft_fingerprint ~ '^[a-f0-9]{64}$'),
 evidence_sha256 text NOT NULL CHECK(evidence_sha256 ~ '^[a-f0-9]{64}$'),
 proposal_fingerprint text NOT NULL CHECK(proposal_fingerprint ~ '^[a-f0-9]{64}$'),
 action text NOT NULL CHECK(action='audit_search_terms_offline_simulation'),
 proposal_state text NOT NULL CHECK(proposal_state='awaiting_separate_approval'),
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(customer_id,order_id,order_revision,provider,plan_version,proposal_version),
 FOREIGN KEY(customer_id,order_id,order_revision,provider,plan_version)
   REFERENCES purchase_rehearsal.f423_google_internal_drafts
     (customer_id,order_id,order_revision,provider,plan_version)
);
ALTER TABLE purchase_rehearsal.f423_google_offline_improvements ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_google_offline_improvements FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_proposal_owner_read ON purchase_rehearsal.f423_google_offline_improvements
 FOR SELECT TO purchase_rehearsal_a
 USING(purchase_rehearsal.preview_order_allowed(customer_id));
CREATE POLICY f423_proposal_owner_insert ON purchase_rehearsal.f423_google_offline_improvements
 FOR INSERT TO purchase_rehearsal_a WITH CHECK (
 purchase_rehearsal.preview_order_allowed(customer_id)
 AND EXISTS(SELECT 1 FROM purchase_rehearsal.f423_google_internal_drafts d
   WHERE d.customer_id=f423_google_offline_improvements.customer_id
     AND d.order_id=f423_google_offline_improvements.order_id
     AND d.order_revision=f423_google_offline_improvements.order_revision
     AND d.briefing_sha256=f423_google_offline_improvements.briefing_sha256
     AND d.provider='google_ads'
     AND d.plan_version=f423_google_offline_improvements.plan_version
     AND d.plan_fingerprint=f423_google_offline_improvements.plan_fingerprint
     AND d.draft_fingerprint=f423_google_offline_improvements.draft_fingerprint
     AND d.draft_state='ci_internal_unpublished')
 AND (SELECT count(*) FROM purchase_rehearsal.f423_google_monitoring_samples m
   WHERE m.customer_id=f423_google_offline_improvements.customer_id
    AND m.order_id=f423_google_offline_improvements.order_id
    AND m.order_revision=f423_google_offline_improvements.order_revision
    AND m.provider='google_ads'
    AND m.plan_version=f423_google_offline_improvements.plan_version
    AND m.plan_fingerprint=f423_google_offline_improvements.plan_fingerprint
    AND m.draft_fingerprint=f423_google_offline_improvements.draft_fingerprint)=2
 );
REVOKE ALL ON purchase_rehearsal.f423_google_offline_improvements FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.f423_google_offline_improvements TO purchase_rehearsal_a;
-- No UPDATE/DELETE, no approval of improvement, no live campaign, no spend.
COMMIT;
