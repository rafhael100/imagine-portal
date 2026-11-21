-- CI-only immutable UNPUBLISHED Google draft. This is NOT a Google Ads campaign.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal'
    OR current_user<>'postgres'
    OR current_setting('server_version_num')::int NOT BETWEEN 180000 AND 189999
    OR to_regclass('purchase_rehearsal.f423_google_plan_approvals') IS NULL
    OR to_regclass('purchase_rehearsal.f423_google_internal_drafts') IS NOT NULL
 THEN RAISE EXCEPTION 'F423 draft requires disposable PG18 and existing plan approval'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_google_internal_drafts (
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 order_revision integer NOT NULL CHECK (order_revision>0),
 briefing_sha256 text NOT NULL CHECK (briefing_sha256 ~ '^[a-f0-9]{64}$'),
 provider text NOT NULL DEFAULT 'google_ads' CHECK(provider='google_ads'),
 plan_version integer NOT NULL CHECK(plan_version=1),
 plan_fingerprint text NOT NULL CHECK(plan_fingerprint ~ '^[a-f0-9]{64}$'),
 approved_by text NOT NULL CHECK(approved_by ~ '^demo-[a-z0-9-]{3,40}$'),
 draft_fingerprint text NOT NULL CHECK(draft_fingerprint ~ '^[a-f0-9]{64}$'),
 draft_state text NOT NULL CHECK(draft_state='ci_internal_unpublished'),
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(customer_id,order_id,order_revision,provider,plan_version),
 FOREIGN KEY(customer_id,order_id,order_revision,provider,plan_version)
  REFERENCES purchase_rehearsal.f423_google_plan_approvals
      (customer_id,order_id,order_revision,provider,plan_version)
);
ALTER TABLE purchase_rehearsal.f423_google_internal_drafts ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_google_internal_drafts FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_draft_owner_read ON purchase_rehearsal.f423_google_internal_drafts
 FOR SELECT TO purchase_rehearsal_a
 USING(purchase_rehearsal.preview_order_allowed(customer_id));
CREATE POLICY f423_draft_owner_insert ON purchase_rehearsal.f423_google_internal_drafts
 FOR INSERT TO purchase_rehearsal_a
 WITH CHECK(purchase_rehearsal.preview_order_allowed(customer_id)
   AND EXISTS(SELECT 1 FROM purchase_rehearsal.f423_google_plan_approvals a
      WHERE a.customer_id=f423_google_internal_drafts.customer_id
        AND a.order_id=f423_google_internal_drafts.order_id
        AND a.order_revision=f423_google_internal_drafts.order_revision
        AND a.briefing_sha256=f423_google_internal_drafts.briefing_sha256
        AND a.provider='google_ads'
        AND a.plan_version=f423_google_internal_drafts.plan_version
        AND a.plan_fingerprint=f423_google_internal_drafts.plan_fingerprint
        AND a.approved_by=f423_google_internal_drafts.approved_by));
REVOKE ALL ON purchase_rehearsal.f423_google_internal_drafts FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.f423_google_internal_drafts TO purchase_rehearsal_a;
-- No UPDATE/DELETE, external campaign ID, Ads token, publish, billing, account consent.
COMMIT;
