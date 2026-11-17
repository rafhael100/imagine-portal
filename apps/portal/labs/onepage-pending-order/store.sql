-- LAB ONLY: after #922, and only after effective Laravel + Guard::identity preflight.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_onepage_buyer_ci' OR current_user<>'onepage_lab_bootstrap'
 OR session_user<>'onepage_lab_bootstrap' OR host(inet_server_addr()) IS DISTINCT FROM '127.0.0.1'
 OR inet_server_port() IS DISTINCT FROM 55439
 OR current_setting('data_directory') !~ '/onepage-pg-lab/data$'
 THEN RAISE EXCEPTION 'DISPOSABLE_IDENTITY_REQUIRED'; END IF;
END $guard$;
-- Server-authored synthetic terms only. No browser/HTTP writer; no operational policy.
CREATE TABLE onepage_buyer_lab.order_terms (
 quote_id uuid PRIMARY KEY REFERENCES onepage_buyer_lab.drafts(id),
 quote_snapshot jsonb NOT NULL,
 contract_ref text NOT NULL CHECK(contract_ref LIKE 'lab-%'),
 contract_revision integer NOT NULL CHECK(contract_revision>0),
 contract_text text NOT NULL CHECK(length(contract_text)>0),
 terms jsonb NOT NULL CHECK(jsonb_typeof(terms)='object')
);
CREATE TABLE onepage_buyer_lab.pending_orders (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), quote_id uuid NOT NULL UNIQUE REFERENCES onepage_buyer_lab.order_terms(quote_id),
 company uuid NOT NULL, buyer uuid NOT NULL, request_hash text NOT NULL,
 quote_snapshot jsonb NOT NULL, offer_digest text NOT NULL, terms jsonb NOT NULL, terms_digest text NOT NULL,
 contract_ref text NOT NULL, contract_revision integer NOT NULL, contract_text text NOT NULL, contract_digest text NOT NULL,
 accepted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 status text NOT NULL DEFAULT 'pending_payment' CHECK(status='pending_payment'),
 UNIQUE(company,buyer,request_hash)
);
ALTER TABLE onepage_buyer_lab.order_terms ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.order_terms FORCE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.pending_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.pending_orders FORCE ROW LEVEL SECURITY;
REVOKE ALL ON onepage_buyer_lab.order_terms,onepage_buyer_lab.pending_orders FROM PUBLIC;
GRANT SELECT ON onepage_buyer_lab.order_terms TO onepage_buyer_owner;
GRANT SELECT,INSERT ON onepage_buyer_lab.pending_orders TO onepage_buyer_owner;
CREATE POLICY internal_terms ON onepage_buyer_lab.order_terms TO onepage_buyer_owner USING(true);
CREATE POLICY internal_orders ON onepage_buyer_lab.pending_orders TO onepage_buyer_owner USING(true) WITH CHECK(true);
CREATE FUNCTION onepage_buyer_lab.reject_order_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'LAB_ORDER_AND_TERMS_IMMUTABLE'; END $$;
CREATE TRIGGER immutable_terms BEFORE UPDATE OR DELETE ON onepage_buyer_lab.order_terms
 FOR EACH ROW EXECUTE FUNCTION onepage_buyer_lab.reject_order_mutation();
CREATE TRIGGER immutable_orders BEFORE UPDATE OR DELETE ON onepage_buyer_lab.pending_orders
 FOR EACH ROW EXECUTE FUNCTION onepage_buyer_lab.reject_order_mutation();
REVOKE ALL ON FUNCTION onepage_buyer_lab.reject_order_mutation() FROM PUBLIC;
CREATE FUNCTION onepage_buyer_lab.review_order(p_session text,p_quote uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE actor record; d onepage_buyer_lab.drafts%ROWTYPE; t onepage_buyer_lab.order_terms%ROWTYPE;
BEGIN
 SELECT * INTO actor FROM onepage_buyer_lab.principal(p_session);
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT * INTO d FROM onepage_buyer_lab.drafts WHERE id=p_quote AND company=actor.organization_id AND buyer=actor.identity_id;
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT * INTO t FROM onepage_buyer_lab.order_terms WHERE quote_id=d.id;
 IF NOT FOUND OR t.quote_snapshot IS DISTINCT FROM d.quote OR d.selection->>'site' IS DISTINCT FROM 'onepage'
 OR d.selection->'channels' IS DISTINCT FROM '[]'::jsonb THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('quote_id',d.id,'company',actor.organization_id,'buyer',actor.identity_id,
 'quote',d.quote,'offer_digest',encode(sha256(convert_to(d.quote::text,'UTF8')),'hex'),
 'terms',t.terms,'terms_digest',encode(sha256(convert_to(t.terms::text,'UTF8')),'hex'),
 'contract_ref',t.contract_ref,'contract_revision',t.contract_revision,'contract_text',t.contract_text,
 'contract_digest',encode(sha256(convert_to(t.contract_text,'UTF8')),'hex'));
END $$;
CREATE FUNCTION onepage_buyer_lab.accept_order(p_session text,p_key text,p_quote uuid,p_acceptance jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE r jsonb; expected jsonb; again record; saved onepage_buyer_lab.pending_orders%ROWTYPE;
BEGIN
 r=onepage_buyer_lab.review_order(p_session,p_quote);
 IF r IS NULL THEN RETURN jsonb_build_object('status','denied'); END IF;
 expected=jsonb_build_object('accepted',true,'quote_id',r->>'quote_id','offer_digest',r->>'offer_digest',
 'terms_digest',r->>'terms_digest','contract_ref',r->>'contract_ref',
 'contract_revision',(r->>'contract_revision')::integer,'contract_digest',r->>'contract_digest');
 IF p_key IS NULL OR p_key !~ '^[a-f0-9]{64}$' OR p_acceptance IS DISTINCT FROM expected
 THEN RETURN jsonb_build_object('status','invalid_acceptance'); END IF;
 INSERT INTO onepage_buyer_lab.pending_orders(quote_id,company,buyer,request_hash,quote_snapshot,offer_digest,terms,terms_digest,contract_ref,contract_revision,contract_text,contract_digest)
 VALUES(p_quote,(r->>'company')::uuid,(r->>'buyer')::uuid,p_key,r->'quote',r->>'offer_digest',r->'terms',r->>'terms_digest',r->>'contract_ref',(r->>'contract_revision')::integer,r->>'contract_text',r->>'contract_digest')
 ON CONFLICT DO NOTHING;
 SELECT * INTO saved FROM onepage_buyer_lab.pending_orders
 WHERE company=(r->>'company')::uuid AND buyer=(r->>'buyer')::uuid AND request_hash=p_key;
 IF NOT FOUND OR saved.quote_id<>p_quote OR saved.offer_digest<>r->>'offer_digest'
 OR saved.terms_digest<>r->>'terms_digest' OR saved.contract_digest<>r->>'contract_digest'
 OR saved.contract_ref<>r->>'contract_ref' OR saved.contract_revision<>(r->>'contract_revision')::integer
 THEN RETURN jsonb_build_object('status','conflict'); END IF;
 SELECT * INTO again FROM onepage_buyer_lab.principal(p_session);
 IF NOT FOUND OR again.identity_id<>saved.buyer OR again.organization_id<>saved.company
 THEN RAISE EXCEPTION 'ORDER_AUTHORITY_CHANGED'; END IF;
 RETURN jsonb_build_object('status','pending_payment','id',saved.id,'quote_id',saved.quote_id,
 'accepted_at',to_char(saved.accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),'laboratory_only',true,'payment_requested',false,'site_provisioned',false);
END $$;
ALTER FUNCTION onepage_buyer_lab.review_order(text,uuid) OWNER TO onepage_buyer_owner;
ALTER FUNCTION onepage_buyer_lab.accept_order(text,text,uuid,jsonb) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.review_order(text,uuid),onepage_buyer_lab.accept_order(text,text,uuid,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.review_order(text,uuid),onepage_buyer_lab.accept_order(text,text,uuid,jsonb) TO onepage_buyer_runtime;
COMMIT;
