-- Canonical native Onepage order store. No installation or provider activation is automatic.
-- Requires native Portal identity migrations and portal_browser_auth/principal functions.
-- Runtime gets EXECUTE only. Published terms are empty until a separate trusted publisher acts.
BEGIN;
-- ONEPAGE_STAGE_BUYER_BEGIN
CREATE ROLE portal_onepage_owner NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT;
CREATE ROLE portal_onepage_runtime NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT;
CREATE ROLE portal_onepage_publisher NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT;
CREATE SCHEMA portal_onepage;
REVOKE ALL ON SCHEMA portal_onepage FROM PUBLIC;
GRANT USAGE ON SCHEMA portal_onepage TO portal_onepage_owner,portal_onepage_runtime,portal_browser_owner,portal_onepage_publisher;
CREATE TABLE portal_onepage.drafts (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), company uuid NOT NULL, buyer uuid NOT NULL,
 request_hash text NOT NULL CHECK(request_hash ~ '^[a-f0-9]{64}$'),
 selection jsonb NOT NULL, quote jsonb NOT NULL,
 UNIQUE(company,request_hash)
);
ALTER TABLE portal_onepage.drafts ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.drafts FORCE ROW LEVEL SECURITY;
REVOKE ALL ON portal_onepage.drafts FROM PUBLIC;
GRANT SELECT,INSERT ON portal_onepage.drafts TO portal_onepage_owner;
CREATE POLICY internal_store ON portal_onepage.drafts TO portal_onepage_owner USING(true) WITH CHECK(true);
-- No runtime table grants. Functions derive scope exclusively from native sessions.
GRANT SELECT(role) ON public.portal_memberships TO portal_browser_owner;
GRANT SELECT(reference) ON public.portal_organizations TO portal_browser_owner;
CREATE FUNCTION portal_onepage.principal(p_hash text)
RETURNS TABLE(identity_id uuid,organization_id uuid,organization_reference text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
BEGIN
 -- Locks serialize membership/session/identity/organization revocation until commit.
 PERFORM s.id FROM public.portal_auth_sessions s
 JOIN public.portal_identities i ON i.id=s.identity_id
 JOIN public.portal_memberships m ON m.identity_id=i.id AND m.organization_id=s.organization_id
 JOIN public.portal_organizations o ON o.id=s.organization_id
 WHERE s.session_sha256=p_hash AND m.role='member'
 FOR SHARE OF s,i,m,o;
 IF NOT FOUND THEN RETURN; END IF;
 RETURN QUERY SELECT c.identity_id,c.organization_id,o.reference::text
 FROM public.portal_browser_principal_candidate(p_hash) c
 JOIN public.portal_organizations o ON o.id=c.organization_id;
END $$;
ALTER FUNCTION portal_onepage.principal(text) OWNER TO portal_browser_owner;
REVOKE ALL ON FUNCTION portal_onepage.principal(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.principal(text) TO portal_onepage_owner;
CREATE FUNCTION portal_onepage.command(p_session text,p_operation text,p_key text,p_id text,p_selection jsonb,p_quote jsonb)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE actor record; again record; draft portal_onepage.drafts%ROWTYPE;
BEGIN
 SELECT * INTO actor FROM portal_onepage.principal(p_session);
 IF NOT FOUND THEN RETURN jsonb_build_object('status','denied'); END IF;
 IF p_operation='create' THEN
  IF p_key IS NULL OR p_key !~ '^[a-f0-9]{64}$' OR p_selection IS NULL OR p_quote IS NULL
   OR p_selection->>'site' IS DISTINCT FROM 'onepage' OR p_selection->'channels' IS DISTINCT FROM '[]'::jsonb
   OR p_quote->>'site' IS DISTINCT FROM 'onepage'
   OR p_quote->'real_charge_enabled' IS DISTINCT FROM 'false'::jsonb
   OR p_quote->'public_checkout_authorized' IS DISTINCT FROM 'false'::jsonb
  THEN RETURN jsonb_build_object('status','invalid_selection'); END IF;
  INSERT INTO portal_onepage.drafts(company,buyer,request_hash,selection,quote)
   VALUES(actor.organization_id,actor.identity_id,p_key,p_selection,p_quote)
   ON CONFLICT(company,request_hash) DO NOTHING;
  SELECT * INTO draft FROM portal_onepage.drafts WHERE company=actor.organization_id AND buyer=actor.identity_id AND request_hash=p_key;
  IF NOT FOUND THEN RETURN jsonb_build_object('status','denied'); END IF;
  IF draft.selection IS DISTINCT FROM p_selection OR draft.quote IS DISTINCT FROM p_quote
   THEN RETURN jsonb_build_object('status','conflict'); END IF;
 ELSIF p_operation='resume' THEN
  SELECT * INTO draft FROM portal_onepage.drafts WHERE company=actor.organization_id AND buyer=actor.identity_id AND id::text=p_id;
  IF NOT FOUND THEN RETURN jsonb_build_object('status','denied'); END IF;
 ELSE RETURN jsonb_build_object('status','denied'); END IF;
 SELECT * INTO again FROM portal_onepage.principal(p_session);
 IF NOT FOUND OR again.identity_id<>actor.identity_id OR again.organization_id<>actor.organization_id
  THEN RAISE EXCEPTION 'ONEPAGE_AUTHORITY_CHANGED'; END IF;
 RETURN jsonb_build_object('status','draft','id',draft.id,'quote',draft.quote,
  'order_created',false,'payment_requested',false,'site_provisioned',false);
END $$;
ALTER FUNCTION portal_onepage.command(text,text,text,text,jsonb,jsonb) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.command(text,text,text,text,jsonb,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.command(text,text,text,text,jsonb,jsonb) TO portal_onepage_runtime;
-- ONEPAGE_STAGE_BUYER_END
-- ONEPAGE_STAGE_ORDERS_BEGIN
-- Server-only publication ledger. Empty by default; no browser/runtime publication rights.
CREATE TABLE portal_onepage.published_contracts (
 publication_ref text PRIMARY KEY CHECK(publication_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND publication_ref !~* '^(lab|demo|test)[-_:]'),
 contract_ref text NOT NULL CHECK(contract_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' AND contract_ref !~* '^(lab|demo|test)[-_:]'),
 contract_revision integer NOT NULL CHECK(contract_revision>0),
 quote_snapshot jsonb NOT NULL CHECK(jsonb_typeof(quote_snapshot)='object'),
 terms jsonb NOT NULL CHECK(coalesce(jsonb_typeof(terms)='object' AND terms->>'scope'='onepage' AND jsonb_typeof(terms->'payment_policy')='string' AND length(terms->>'payment_policy')>0 AND terms->>'payment_policy'<>'undecided',false)),
 contract_text text NOT NULL CHECK(length(contract_text)>0 AND octet_length(contract_text)<=65536),
 published_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(contract_ref,contract_revision)
);
CREATE TABLE portal_onepage.contract_revocations (
 publication_ref text PRIMARY KEY REFERENCES portal_onepage.published_contracts(publication_ref),
 revoked_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE portal_onepage.published_contracts ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.published_contracts FORCE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.contract_revocations ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.contract_revocations FORCE ROW LEVEL SECURITY;
REVOKE ALL ON portal_onepage.published_contracts,portal_onepage.contract_revocations FROM PUBLIC;
GRANT SELECT,INSERT ON portal_onepage.published_contracts,portal_onepage.contract_revocations TO portal_onepage_owner;
GRANT UPDATE(publication_ref) ON portal_onepage.published_contracts TO portal_onepage_owner;
CREATE POLICY publication_owner ON portal_onepage.published_contracts TO portal_onepage_owner USING(true) WITH CHECK(true);
CREATE POLICY revocation_owner ON portal_onepage.contract_revocations TO portal_onepage_owner USING(true) WITH CHECK(true);
CREATE FUNCTION portal_onepage.publish_contract(p_publication text,p_contract text,p_revision integer,p_quote jsonb,p_terms jsonb,p_text text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE saved portal_onepage.published_contracts%ROWTYPE;
BEGIN
 IF p_publication IS NULL OR p_contract IS NULL OR p_revision IS NULL OR p_revision<1
 OR p_quote IS NULL OR p_terms IS NULL OR p_text IS NULL OR length(p_text)=0 OR octet_length(p_text)>65536
 OR p_publication !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR p_publication ~* '^(lab|demo|test)[-_:]'
 OR p_contract !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' OR p_contract ~* '^(lab|demo|test)[-_:]'
 OR p_quote->>'site' IS DISTINCT FROM 'onepage' OR p_quote->'channels' IS DISTINCT FROM '[]'::jsonb
 OR p_quote->>'currency' IS DISTINCT FROM 'BRL' OR coalesce(p_quote->>'catalog_version','') !~ '^[a-f0-9]{64}$'
 OR p_quote->'real_charge_enabled' IS DISTINCT FROM 'false'::jsonb
 OR p_quote->'public_checkout_authorized' IS DISTINCT FROM 'false'::jsonb
 OR p_terms->>'scope' IS DISTINCT FROM 'onepage'
 OR jsonb_typeof(p_terms->'payment_policy') IS DISTINCT FROM 'string'
 OR coalesce(p_terms->>'payment_policy','') IN ('','undecided')
 THEN RETURN false; END IF;
 INSERT INTO portal_onepage.published_contracts(publication_ref,contract_ref,contract_revision,quote_snapshot,terms,contract_text)
 VALUES(p_publication,p_contract,p_revision,p_quote,p_terms,p_text) ON CONFLICT DO NOTHING;
 SELECT * INTO saved FROM portal_onepage.published_contracts WHERE publication_ref=p_publication;
 RETURN FOUND AND saved.contract_ref=p_contract AND saved.contract_revision=p_revision
 AND saved.quote_snapshot=p_quote AND saved.terms=p_terms AND saved.contract_text=p_text
 AND NOT EXISTS(SELECT 1 FROM portal_onepage.contract_revocations WHERE publication_ref=p_publication);
END $$;
CREATE FUNCTION portal_onepage.revoke_contract(p_publication text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
BEGIN
 -- Readers hold FOR SHARE on this row; revocation serializes until their statement commits.
 PERFORM publication_ref FROM portal_onepage.published_contracts WHERE publication_ref=p_publication FOR UPDATE;
 IF NOT FOUND THEN RETURN false; END IF;
 INSERT INTO portal_onepage.contract_revocations(publication_ref) VALUES(p_publication) ON CONFLICT DO NOTHING;
 RETURN true;
END $$;
ALTER FUNCTION portal_onepage.publish_contract(text,text,integer,jsonb,jsonb,text) OWNER TO portal_onepage_owner;
ALTER FUNCTION portal_onepage.revoke_contract(text) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.publish_contract(text,text,integer,jsonb,jsonb,text),portal_onepage.revoke_contract(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.publish_contract(text,text,integer,jsonb,jsonb,text),portal_onepage.revoke_contract(text) TO portal_onepage_publisher;

CREATE TABLE portal_onepage.order_terms (
 quote_id uuid PRIMARY KEY REFERENCES portal_onepage.drafts(id),
 publication_ref text REFERENCES portal_onepage.published_contracts(publication_ref),
 quote_snapshot jsonb NOT NULL,
 contract_ref text NOT NULL CHECK(contract_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 contract_revision integer NOT NULL CHECK(contract_revision>0),
 contract_text text NOT NULL CHECK(length(contract_text)>0),
 terms jsonb NOT NULL CHECK(jsonb_typeof(terms)='object')
);
CREATE TABLE portal_onepage.pending_orders (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), quote_id uuid NOT NULL UNIQUE REFERENCES portal_onepage.order_terms(quote_id),
 publication_ref text REFERENCES portal_onepage.published_contracts(publication_ref),
 company uuid NOT NULL, buyer uuid NOT NULL, request_hash text NOT NULL,
 quote_snapshot jsonb NOT NULL, offer_digest text NOT NULL, terms jsonb NOT NULL, terms_digest text NOT NULL,
 contract_ref text NOT NULL, contract_revision integer NOT NULL, contract_text text NOT NULL, contract_digest text NOT NULL,
 accepted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 status text NOT NULL DEFAULT 'pending_payment' CHECK(status='pending_payment'),
 UNIQUE(company,buyer,request_hash)
);
CREATE INDEX pending_orders_owner_accepted_idx ON portal_onepage.pending_orders(company,buyer,accepted_at DESC,id DESC);
ALTER TABLE portal_onepage.order_terms ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.order_terms FORCE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.pending_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.pending_orders FORCE ROW LEVEL SECURITY;
REVOKE ALL ON portal_onepage.order_terms,portal_onepage.pending_orders FROM PUBLIC;
GRANT SELECT,INSERT ON portal_onepage.order_terms TO portal_onepage_owner;
GRANT SELECT,INSERT ON portal_onepage.pending_orders TO portal_onepage_owner;
CREATE POLICY internal_terms ON portal_onepage.order_terms TO portal_onepage_owner USING(true);
CREATE POLICY internal_orders ON portal_onepage.pending_orders TO portal_onepage_owner USING(true) WITH CHECK(true);
CREATE FUNCTION portal_onepage.reject_order_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'ONEPAGE_ORDER_AND_TERMS_IMMUTABLE'; END $$;
CREATE TRIGGER immutable_terms BEFORE UPDATE OR DELETE ON portal_onepage.order_terms
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_order_mutation();
CREATE TRIGGER immutable_orders BEFORE UPDATE OR DELETE ON portal_onepage.pending_orders
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_order_mutation();
REVOKE ALL ON FUNCTION portal_onepage.reject_order_mutation() FROM PUBLIC;
CREATE TRIGGER immutable_publication BEFORE UPDATE OR DELETE ON portal_onepage.published_contracts
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_order_mutation();
CREATE TRIGGER immutable_revocation BEFORE UPDATE OR DELETE ON portal_onepage.contract_revocations
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_order_mutation();

-- ONEPAGE_PUBLICATION_SOURCE_BEGIN
CREATE FUNCTION portal_onepage.terms_usable(t portal_onepage.order_terms) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE p portal_onepage.published_contracts%ROWTYPE;
BEGIN
 IF t.publication_ref IS NULL THEN RETURN false; END IF;
 SELECT * INTO p FROM portal_onepage.published_contracts WHERE publication_ref=t.publication_ref FOR SHARE;
 RETURN FOUND AND p.contract_ref=t.contract_ref AND p.contract_revision=t.contract_revision
 AND p.quote_snapshot=t.quote_snapshot AND p.terms=t.terms AND p.contract_text=t.contract_text
 AND NOT EXISTS(SELECT 1 FROM portal_onepage.contract_revocations WHERE publication_ref=t.publication_ref);
END $$;
-- ONEPAGE_PUBLICATION_SOURCE_END
ALTER FUNCTION portal_onepage.terms_usable(portal_onepage.order_terms) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.terms_usable(portal_onepage.order_terms) FROM PUBLIC;

CREATE FUNCTION portal_onepage.freeze_terms(p_quote uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE d portal_onepage.drafts%ROWTYPE; p portal_onepage.published_contracts%ROWTYPE;
BEGIN
 IF EXISTS(SELECT 1 FROM portal_onepage.order_terms WHERE quote_id=p_quote) THEN RETURN; END IF;
 SELECT * INTO d FROM portal_onepage.drafts WHERE id=p_quote;
 IF NOT FOUND THEN RETURN; END IF;
 BEGIN
  -- One statement observes and locks exactly one publication; never count then select.
  SELECT * INTO STRICT p FROM portal_onepage.published_contracts c WHERE c.quote_snapshot=d.quote
  AND NOT EXISTS(SELECT 1 FROM portal_onepage.contract_revocations r WHERE r.publication_ref=c.publication_ref) FOR SHARE;
 EXCEPTION WHEN NO_DATA_FOUND OR TOO_MANY_ROWS THEN RETURN;
 END;
 INSERT INTO portal_onepage.order_terms(quote_id,publication_ref,quote_snapshot,contract_ref,contract_revision,contract_text,terms)
 VALUES(p_quote,p.publication_ref,d.quote,p.contract_ref,p.contract_revision,p.contract_text,p.terms)
 ON CONFLICT DO NOTHING;
END $$;
ALTER FUNCTION portal_onepage.freeze_terms(uuid) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.freeze_terms(uuid) FROM PUBLIC;

CREATE FUNCTION portal_onepage.review_order(p_session text,p_quote uuid) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE actor record; again record; d portal_onepage.drafts%ROWTYPE; t portal_onepage.order_terms%ROWTYPE;
BEGIN
 SELECT * INTO actor FROM portal_onepage.principal(p_session);
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT * INTO d FROM portal_onepage.drafts WHERE id=p_quote AND company=actor.organization_id AND buyer=actor.identity_id;
 IF NOT FOUND THEN RETURN NULL; END IF;
 PERFORM portal_onepage.freeze_terms(d.id);
 SELECT * INTO t FROM portal_onepage.order_terms WHERE quote_id=d.id;
 IF NOT FOUND OR t.quote_snapshot IS DISTINCT FROM d.quote OR d.selection->>'site' IS DISTINCT FROM 'onepage'
 OR d.selection->'channels' IS DISTINCT FROM '[]'::jsonb OR NOT portal_onepage.terms_usable(t) THEN RETURN NULL; END IF;
 SELECT * INTO again FROM portal_onepage.principal(p_session);
 IF NOT FOUND OR again.identity_id<>actor.identity_id OR again.organization_id<>actor.organization_id
 THEN RAISE EXCEPTION 'ORDER_AUTHORITY_CHANGED'; END IF;
 RETURN jsonb_build_object('quote_id',d.id,'company',actor.organization_id,'buyer',actor.identity_id,
 'company_reference',actor.organization_reference,'publication_ref',t.publication_ref,
 'quote',d.quote,'offer_digest',encode(sha256(convert_to(d.quote::text,'UTF8')),'hex'),
 'terms',t.terms,'terms_digest',encode(sha256(convert_to(t.terms::text,'UTF8')),'hex'),
 'contract_ref',t.contract_ref,'contract_revision',t.contract_revision,'contract_text',t.contract_text,
 'contract_digest',encode(sha256(convert_to(t.contract_text,'UTF8')),'hex'));
END $$;
CREATE FUNCTION portal_onepage.accept_order(p_session text,p_key text,p_quote uuid,p_acceptance jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE r jsonb; expected jsonb; again record; saved portal_onepage.pending_orders%ROWTYPE;
BEGIN
 r=portal_onepage.review_order(p_session,p_quote);
 IF r IS NULL THEN RETURN jsonb_build_object('status','denied'); END IF;
 expected=jsonb_build_object('accepted',true,'quote_id',r->>'quote_id','offer_digest',r->>'offer_digest',
 'terms_digest',r->>'terms_digest','contract_ref',r->>'contract_ref',
 'contract_revision',(r->>'contract_revision')::integer,'contract_digest',r->>'contract_digest');
 IF p_key IS NULL OR p_key !~ '^[a-f0-9]{64}$' OR p_acceptance IS DISTINCT FROM expected
 THEN RETURN jsonb_build_object('status','invalid_acceptance'); END IF;
 INSERT INTO portal_onepage.pending_orders(quote_id,publication_ref,company,buyer,request_hash,quote_snapshot,offer_digest,terms,terms_digest,contract_ref,contract_revision,contract_text,contract_digest)
 VALUES(p_quote,r->>'publication_ref',(r->>'company')::uuid,(r->>'buyer')::uuid,p_key,r->'quote',r->>'offer_digest',r->'terms',r->>'terms_digest',r->>'contract_ref',(r->>'contract_revision')::integer,r->>'contract_text',r->>'contract_digest')
 ON CONFLICT DO NOTHING;
 SELECT * INTO saved FROM portal_onepage.pending_orders
 WHERE company=(r->>'company')::uuid AND buyer=(r->>'buyer')::uuid AND request_hash=p_key;
 IF NOT FOUND OR saved.quote_id<>p_quote OR saved.offer_digest<>r->>'offer_digest'
 OR saved.terms_digest<>r->>'terms_digest' OR saved.contract_digest<>r->>'contract_digest'
 OR saved.publication_ref IS DISTINCT FROM r->>'publication_ref'
 OR saved.contract_ref<>r->>'contract_ref' OR saved.contract_revision<>(r->>'contract_revision')::integer
 THEN RETURN jsonb_build_object('status','conflict'); END IF;
 SELECT * INTO again FROM portal_onepage.principal(p_session);
 IF NOT FOUND OR again.identity_id<>saved.buyer OR again.organization_id<>saved.company
 THEN RAISE EXCEPTION 'ORDER_AUTHORITY_CHANGED'; END IF;
 RETURN jsonb_build_object('status','pending_payment','id',saved.id,'quote_id',saved.quote_id,
 'accepted_at',to_char(saved.accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),'payment_requested',false,'site_provisioned',false);
END $$;
ALTER FUNCTION portal_onepage.review_order(text,uuid) OWNER TO portal_onepage_owner;
ALTER FUNCTION portal_onepage.accept_order(text,text,uuid,jsonb) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.review_order(text,uuid),portal_onepage.accept_order(text,text,uuid,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.review_order(text,uuid),portal_onepage.accept_order(text,text,uuid,jsonb) TO portal_onepage_runtime;
-- ONEPAGE_STAGE_ORDERS_END
-- ONEPAGE_STAGE_READ_BEGIN
-- Native read; the exact accepted publication is revalidated on every call.
CREATE FUNCTION portal_onepage.read_pending_order(p_session text,p_order uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE actor record; again record; saved portal_onepage.pending_orders%ROWTYPE;
BEGIN
 IF p_session IS NULL OR p_session !~ '^[a-f0-9]{64}$' OR p_order IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO actor FROM portal_onepage.principal(p_session);
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT * INTO saved FROM portal_onepage.pending_orders
 WHERE id=p_order AND company=actor.organization_id AND buyer=actor.identity_id AND status='pending_payment';
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM portal_onepage.order_terms t WHERE t.quote_id=saved.quote_id
 AND t.publication_ref IS NOT DISTINCT FROM saved.publication_ref AND portal_onepage.terms_usable(t)) THEN RETURN NULL; END IF;
 SELECT * INTO again FROM portal_onepage.principal(p_session);
 IF NOT FOUND OR again.identity_id<>saved.buyer OR again.organization_id<>saved.company THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('id',saved.id,'quote_id',saved.quote_id,'company',saved.company,'buyer',saved.buyer,
 'company_reference',actor.organization_reference,'publication_ref',saved.publication_ref,
 'status',saved.status,'quote_snapshot',saved.quote_snapshot,'offer_digest',saved.offer_digest,
 'terms',saved.terms,'terms_digest',saved.terms_digest,'contract_ref',saved.contract_ref,
 'contract_revision',saved.contract_revision,'contract_text',saved.contract_text,'contract_digest',saved.contract_digest,
 'accepted_at',to_char(saved.accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'));
END $$;
ALTER FUNCTION portal_onepage.read_pending_order(text,uuid) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.read_pending_order(text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.read_pending_order(text,uuid) TO portal_onepage_runtime;
-- ONEPAGE_STAGE_READ_END
-- ONEPAGE_STAGE_HISTORY_BEGIN
-- Only owned orders under a still-active native business session and publication.
-- No table grant, no payment claims, no client-selected tenant.
CREATE FUNCTION portal_onepage.list_my_pending_orders(p_session text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $
DECLARE actor record; again record; rows jsonb;
BEGIN
 IF p_session IS NULL OR p_session !~ '^[a-f0-9]{64}

 THEN RETURN jsonb_build_object('status','denied'); END IF;
 SELECT * INTO actor FROM portal_onepage.principal(p_session);
 IF NOT FOUND THEN RETURN jsonb_build_object('status','denied'); END IF;
 SELECT COALESCE(jsonb_agg(p.item ORDER BY p.accepted_at DESC,p.id DESC),'[]'::jsonb)
 INTO rows FROM (
  SELECT o.id,o.accepted_at,
   jsonb_build_object('id',o.id,'quote_id',o.quote_id,'status','pending_payment',
    'accepted_at',to_char(o.accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"')) item
  FROM portal_onepage.pending_orders o
  WHERE o.company=actor.organization_id AND o.buyer=actor.identity_id
   AND o.status='pending_payment'
   AND EXISTS(SELECT 1 FROM portal_onepage.order_terms t WHERE t.quote_id=o.quote_id
    AND t.publication_ref IS NOT DISTINCT FROM o.publication_ref
    AND portal_onepage.terms_usable(t))
  ORDER BY o.accepted_at DESC,o.id DESC LIMIT 20
 ) p;
 SELECT * INTO again FROM portal_onepage.principal(p_session);
 IF NOT FOUND OR again.identity_id<>actor.identity_id OR again.organization_id<>actor.organization_id
 THEN RETURN jsonb_build_object('status','denied'); END IF;
 RETURN jsonb_build_object('status','ok','orders',rows);
END $;
ALTER FUNCTION portal_onepage.list_my_pending_orders(text) OWNER TO portal_onepage_owner;
REVOKE ALL ON FUNCTION portal_onepage.list_my_pending_orders(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.list_my_pending_orders(text) TO portal_onepage_runtime;
-- ONEPAGE_STAGE_HISTORY_END
COMMIT;
