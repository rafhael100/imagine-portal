-- TWO timestamped OFFLINE fixture samples. Never from Google Ads; no monitoring account authorization.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal'
    OR current_user<>'postgres'
    OR current_setting('server_version_num')::int NOT BETWEEN 180000 AND 189999
    OR to_regclass('purchase_rehearsal.f423_google_internal_drafts') IS NULL
    OR to_regclass('purchase_rehearsal.f423_google_monitoring_samples') IS NOT NULL
 THEN RAISE EXCEPTION 'F423 monitoring only in fresh disposable PG18 after internal draft ledger'; END IF;
END $guard$;
CREATE TABLE purchase_rehearsal.f423_google_monitoring_samples (
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id),
 order_id text NOT NULL REFERENCES purchase_rehearsal.customer_orders(id),
 order_revision integer NOT NULL CHECK(order_revision>0),
 briefing_sha256 text NOT NULL CHECK(briefing_sha256 ~ '^[a-f0-9]{64}$'),
 provider text NOT NULL DEFAULT 'google_ads' CHECK(provider='google_ads'),
 plan_version integer NOT NULL CHECK(plan_version=1),
 plan_fingerprint text NOT NULL CHECK(plan_fingerprint ~ '^[a-f0-9]{64}$'),
 draft_fingerprint text NOT NULL CHECK(draft_fingerprint ~ '^[a-f0-9]{64}$'),
 sample_date date NOT NULL,
 impressions integer NOT NULL,
 clicks integer NOT NULL,
 conversions integer NOT NULL,
 source text NOT NULL CHECK(source='ci_offline_google_ads_provider_fixture'),
 recorded_at timestamptz NOT NULL DEFAULT now(),
 CHECK (
  (sample_date=DATE '2026-11-19' AND impressions=1000 AND clicks=40 AND conversions=2)
  OR
  (sample_date=DATE '2026-11-20' AND impressions=1200 AND clicks=46 AND conversions=2)
 ),
 PRIMARY KEY(customer_id,order_id,order_revision,provider,plan_version,sample_date),
 FOREIGN KEY(customer_id,order_id,order_revision,provider,plan_version)
   REFERENCES purchase_rehearsal.f423_google_internal_drafts
     (customer_id,order_id,order_revision,provider,plan_version)
);
ALTER TABLE purchase_rehearsal.f423_google_monitoring_samples ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.f423_google_monitoring_samples FORCE ROW LEVEL SECURITY;
CREATE POLICY f423_sample_owner_read ON purchase_rehearsal.f423_google_monitoring_samples
 FOR SELECT TO purchase_rehearsal_a
 USING(purchase_rehearsal.preview_order_allowed(customer_id));
CREATE POLICY f423_sample_owner_insert ON purchase_rehearsal.f423_google_monitoring_samples
 FOR INSERT TO purchase_rehearsal_a
 WITH CHECK(purchase_rehearsal.preview_order_allowed(customer_id)
   AND EXISTS(SELECT 1 FROM purchase_rehearsal.f423_google_internal_drafts d
     WHERE d.customer_id=f423_google_monitoring_samples.customer_id
       AND d.order_id=f423_google_monitoring_samples.order_id
       AND d.order_revision=f423_google_monitoring_samples.order_revision
       AND d.briefing_sha256=f423_google_monitoring_samples.briefing_sha256
       AND d.provider='google_ads'
       AND d.plan_version=f423_google_monitoring_samples.plan_version
       AND d.plan_fingerprint=f423_google_monitoring_samples.plan_fingerprint
       AND d.draft_fingerprint=f423_google_monitoring_samples.draft_fingerprint
       AND d.draft_state='ci_internal_unpublished'));
REVOKE ALL ON purchase_rehearsal.f423_google_monitoring_samples FROM PUBLIC;
GRANT SELECT,INSERT ON purchase_rehearsal.f423_google_monitoring_samples TO purchase_rehearsal_a;
-- No UPDATE/DELETE, no provider access, no billing, no real campaign, no Ads metrics.
COMMIT;
