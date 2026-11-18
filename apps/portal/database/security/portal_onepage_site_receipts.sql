-- Native Portal storage of a validated, pending Sites intake receipt.
-- Requires portal_onepage_orders.sql and native browser principal, but no PSP.
-- Neither a valid receipt nor this ledger grants billing, briefing or publishing.
BEGIN;
CREATE ROLE portal_onepage_intake_recorder NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT;
GRANT USAGE ON SCHEMA portal_onepage TO portal_onepage_intake_recorder;
GRANT SELECT(id,reference,status) ON public.portal_organizations TO portal_onepage_owner;
CREATE POLICY portal_onepage_receipt_org_read ON public.portal_organizations
 FOR SELECT TO portal_onepage_owner USING(status='active');

CREATE TABLE portal_onepage.site_intake_receipts (
 order_id uuid PRIMARY KEY REFERENCES portal_onepage.pending_orders(id),
 site_ref uuid NOT NULL UNIQUE,
 receipt jsonb NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CONSTRAINT site_intake_pending_only CHECK(
  jsonb_typeof(receipt)='object'
  AND receipt->>'schemaVersion'='sites.project-intake.receipt.v1'
  AND receipt->>'status'='recorded'
  AND receipt->>'state'='awaiting_official_authority'
  AND receipt->'authorityVerified'='false'::jsonb
  AND receipt->'clientAccepted'='false'::jsonb
  AND receipt->'canPublish'='false'::jsonb
 )
);
ALTER TABLE portal_onepage.site_intake_receipts ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.site_intake_receipts FORCE ROW LEVEL SECURITY;
REVOKE ALL ON portal_onepage.site_intake_receipts FROM PUBLIC;
GRANT SELECT,INSERT ON portal_onepage.site_intake_receipts TO portal_onepage_owner;
CREATE POLICY onepage_receipt_read ON portal_onepage.site_intake_receipts
 FOR SELECT TO portal_onepage_owner USING(true);
CREATE POLICY onepage_receipt_insert ON portal_onepage.site_intake_receipts
 FOR INSERT TO portal_onepage_owner WITH CHECK(true);
CREATE TRIGGER immutable_site_intake
 BEFORE UPDATE OR DELETE ON portal_onepage.site_intake_receipts
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_order_mutation();

-- Only a separately provisioned restricted SQL login may record this inert
-- receipt. Caller must verify real Sites response against the exact command.
CREATE FUNCTION portal_onepage.record_pending_site_intake(p_receipt jsonb)
RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE o portal_onepage.pending_orders%ROWTYPE;
 org_ref text;
 saved jsonb;
 expected text[] := ARRAY[
  'schemaVersion','tenantRef','orderRef','contractRef','contractRevision',
  'productCode','operationId','idempotencyKey','siteRef','commandDigest',
  'status','state','authorityVerified','clientAccepted','canPublish'
 ];
BEGIN
 IF session_user <> 'portal_onepage_intake_recorder'
 THEN RETURN jsonb_build_object('status','denied'); END IF;
 IF p_receipt IS NULL OR jsonb_typeof(p_receipt) IS DISTINCT FROM 'object'
 THEN RETURN jsonb_build_object('status','denied'); END IF;
 IF NOT (p_receipt ?& expected)
    OR (SELECT count(*) FROM jsonb_object_keys(p_receipt)) <> 15
    OR p_receipt->>'schemaVersion' IS DISTINCT FROM 'sites.project-intake.receipt.v1'
    OR p_receipt->>'status' IS DISTINCT FROM 'recorded'
    OR p_receipt->>'state' IS DISTINCT FROM 'awaiting_official_authority'
    OR p_receipt->'authorityVerified' IS DISTINCT FROM 'false'::jsonb
    OR p_receipt->'clientAccepted' IS DISTINCT FROM 'false'::jsonb
    OR p_receipt->'canPublish' IS DISTINCT FROM 'false'::jsonb
    OR jsonb_typeof(p_receipt->'contractRevision') IS DISTINCT FROM 'number'
    OR coalesce(p_receipt->>'contractRevision','') !~ '^[1-9][0-9]{0,8}$'
    OR coalesce(p_receipt->>'productCode','') <> 'onepage'
    OR coalesce(p_receipt->>'orderRef','') !~ '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$'
    OR coalesce(p_receipt->>'siteRef','') !~ '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$'
    OR p_receipt->>'siteRef' = p_receipt->>'orderRef'
    OR coalesce(p_receipt->>'siteRef','') = '00000000-0000-0000-0000-000000000000'
    OR coalesce(p_receipt->>'commandDigest','') !~ '^[a-f0-9]{64}$'
    OR coalesce(p_receipt->>'operationId','') !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
    OR coalesce(p_receipt->>'idempotencyKey','') !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 THEN RETURN jsonb_build_object('status','denied'); END IF;

 SELECT * INTO o FROM portal_onepage.pending_orders
 WHERE id=(p_receipt->>'orderRef')::uuid AND status='pending_payment';
 IF NOT FOUND OR o.contract_ref IS DISTINCT FROM p_receipt->>'contractRef'
    OR o.contract_revision::text IS DISTINCT FROM p_receipt->>'contractRevision'
 THEN RETURN jsonb_build_object('status','denied'); END IF;
 SELECT reference INTO org_ref FROM public.portal_organizations
 WHERE id=o.company AND status='active';
 IF NOT FOUND OR org_ref IS DISTINCT FROM p_receipt->>'tenantRef'
 THEN RETURN jsonb_build_object('status','denied'); END IF;
 IF NOT EXISTS (
  SELECT 1 FROM portal_onepage.order_terms t
  WHERE t.quote_id=o.quote_id
   AND t.publication_ref IS NOT DISTINCT FROM o.publication_ref
   AND portal_onepage.terms_usable(t)
 ) THEN RETURN jsonb_build_object('status','denied'); END IF;
 INSERT INTO portal_onepage.site_intake_receipts(order_id,site_ref,receipt)
 VALUES(o.id,(p_receipt->>'siteRef')::uuid,p_receipt)
 ON CONFLICT(order_id) DO NOTHING;
 SELECT receipt INTO saved FROM portal_onepage.site_intake_receipts WHERE order_id=o.id;
 IF NOT FOUND OR saved IS DISTINCT FROM p_receipt
 THEN RETURN jsonb_build_object('status','conflict'); END IF;
 RETURN saved;
END $$;
ALTER FUNCTION portal_onepage.record_pending_site_intake(jsonb) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.record_pending_site_intake(jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.record_pending_site_intake(jsonb) TO portal_onepage_intake_recorder;

-- Customer-facing data comes solely from native Portal session authority.
-- Never return a receipt or siteRef across tenant, buyer or revocation boundaries.
CREATE FUNCTION portal_onepage.read_my_site_intake(p_session text,p_order uuid)
RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE actor record; again record; o portal_onepage.pending_orders%ROWTYPE;
 saved jsonb;
BEGIN
 IF p_session IS NULL OR p_session !~ '^[a-f0-9]{64}$' OR p_order IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO actor FROM portal_onepage.principal(p_session);
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT * INTO o FROM portal_onepage.pending_orders
 WHERE id=p_order AND company=actor.organization_id AND buyer=actor.identity_id
   AND status='pending_payment';
 IF NOT FOUND OR NOT EXISTS (
  SELECT 1 FROM portal_onepage.order_terms t WHERE t.quote_id=o.quote_id
  AND t.publication_ref IS NOT DISTINCT FROM o.publication_ref
  AND portal_onepage.terms_usable(t)
 ) THEN RETURN NULL; END IF;
 SELECT receipt INTO saved FROM portal_onepage.site_intake_receipts WHERE order_id=o.id;
 SELECT * INTO again FROM portal_onepage.principal(p_session);
 IF NOT FOUND OR again.identity_id<>o.buyer OR again.organization_id<>o.company THEN RETURN NULL; END IF;
 IF saved IS NULL THEN RETURN jsonb_build_object('status','not_recorded'); END IF;
 RETURN jsonb_build_object('status','recorded','siteRef',saved->>'siteRef',
   'state','awaiting_official_authority','authorityVerified',false,
   'clientAccepted',false,'canPublish',false);
END $$;
ALTER FUNCTION portal_onepage.read_my_site_intake(text,uuid) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.read_my_site_intake(text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.read_my_site_intake(text,uuid) TO portal_onepage_runtime;
COMMIT;
