-- INERT private source; only for the preattested disposable Onepage cluster.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_onepage_buyer_ci' OR session_user<>'onepage_lab_bootstrap'
 OR current_user<>session_user OR host(inet_server_addr()) IS DISTINCT FROM '127.0.0.1'
 OR inet_server_port() IS DISTINCT FROM 55439 OR current_setting('data_directory') !~ '/onepage-pg-lab/data$'
 OR to_regclass('onepage_buyer_lab.fixture_charge_bindings') IS NULL THEN
  RAISE EXCEPTION 'DISPOSABLE_RECEIPT_PROFILE_REQUIRED';
 END IF;
END $guard$;
CREATE ROLE onepage_fixture_receipt_observer NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
CREATE ROLE onepage_fixture_receipt_writer NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
GRANT USAGE ON SCHEMA onepage_buyer_lab TO onepage_fixture_receipt_observer,onepage_fixture_receipt_writer;

-- One current independently staged checkpoint per exact native order line.
-- No paid_at, signature, PSP receipt data, financial authority or briefing.
CREATE TABLE onepage_buyer_lab.fixture_read_receipt_source(
 order_id uuid NOT NULL REFERENCES onepage_buyer_lab.pending_orders(id),
 line text NOT NULL CHECK (line='setup-1'),
 source_version bigint NOT NULL CHECK (source_version>0),
 evidence jsonb NOT NULL CHECK (jsonb_typeof(evidence)='object'),
 PRIMARY KEY(order_id,line)
);
CREATE UNIQUE INDEX fixture_receipt_one_source_only
 ON onepage_buyer_lab.fixture_read_receipt_source ((true));
CREATE TABLE onepage_buyer_lab.fixture_append_receipt_input(
 order_id uuid NOT NULL REFERENCES onepage_buyer_lab.pending_orders(id),
 line text NOT NULL CHECK (line='setup-1'),
 source_version bigint NOT NULL CHECK (source_version>0),
 evidence_digest text NOT NULL CHECK (evidence_digest ~ '^[0-9a-f]{64}$'),
 fixture_only boolean NOT NULL CHECK (fixture_only),
 PRIMARY KEY(order_id,line)
);
ALTER TABLE onepage_buyer_lab.fixture_read_receipt_source ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_read_receipt_source FORCE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_append_receipt_input ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_append_receipt_input FORCE ROW LEVEL SECURITY;
REVOKE ALL ON onepage_buyer_lab.fixture_read_receipt_source,
 onepage_buyer_lab.fixture_append_receipt_input FROM PUBLIC;
CREATE POLICY fixture_receipt_source_read ON onepage_buyer_lab.fixture_read_receipt_source
 FOR SELECT TO onepage_buyer_owner USING(true);
CREATE POLICY fixture_receipt_log_read ON onepage_buyer_lab.fixture_append_receipt_input
 FOR SELECT TO onepage_buyer_owner USING(true);
CREATE POLICY fixture_receipt_log_append ON onepage_buyer_lab.fixture_append_receipt_input
 FOR INSERT TO onepage_buyer_owner WITH CHECK(fixture_only);
GRANT SELECT ON onepage_buyer_lab.fixture_read_receipt_source TO onepage_buyer_owner;
GRANT SELECT,INSERT ON onepage_buyer_lab.fixture_append_receipt_input TO onepage_buyer_owner;

-- Fixed synthetic authority: source setup assigns one exact order/line. Source
-- persona is not a broad binding reader and cannot authorize/write anything.
CREATE FUNCTION onepage_buyer_lab.fixture_observe_receipt_input(p_order uuid,p_line text)
RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
 SELECT jsonb_build_object('version',s.source_version,'evidence',s.evidence,
  'digest',encode(sha256(convert_to(s.evidence::text,'UTF8')),'hex'))
 FROM onepage_buyer_lab.fixture_read_receipt_source s
 WHERE session_user='onepage_fixture_receipt_observer' AND s.order_id=p_order AND s.line=p_line
$$;

-- Caller cannot submit evidence, payer, tenant or provider. Digest/version
-- must match the independently staged current fixture input and bound entry.
-- Scope/row and observation version are rechecked in a single SELECT snapshot.
-- No claim that this snapshot serializes a future live source changing later.
CREATE FUNCTION onepage_buyer_lab.fixture_append_bound_receipt_input(p_order uuid,p_line text,p_version bigint,p_digest text)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE result text;
BEGIN
 IF session_user<>'onepage_fixture_receipt_writer'
 OR current_setting('transaction_isolation')<>'read committed'
 OR p_line IS DISTINCT FROM 'setup-1' OR p_version IS NULL
 OR p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$' THEN RETURN 'denied';
 END IF;
 WITH matched AS MATERIALIZED (
 SELECT s.order_id,s.line,s.source_version
 FROM onepage_buyer_lab.fixture_read_receipt_source s
 JOIN onepage_buyer_lab.pending_orders o ON o.id=s.order_id
 JOIN onepage_buyer_lab.fixture_charge_reservations r ON r.order_id=o.id AND r.line=s.line
 JOIN onepage_buyer_lab.fixture_charge_bindings b ON
  (b.order_id,b.line,b.account,b.environment)=(r.order_id,r.line,r.account,r.environment)
 WHERE s.order_id=p_order AND s.line=p_line AND s.source_version=p_version
 AND o.status='pending_payment' AND r.attempted AND r.environment='sandbox'
 AND o.quote_snapshot->>'currency'='BRL'
 AND r.contract_scope=jsonb_build_object('id',o.id,'quote_id',o.quote_id,'company',o.company,
  'offer_digest',o.offer_digest,'terms_digest',o.terms_digest,'contract_ref',o.contract_ref,
  'contract_revision',o.contract_revision,'contract_digest',o.contract_digest,
  'accepted_at',to_char(o.accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'))
 AND s.evidence->'scope'=jsonb_build_object(
  'company',o.company,'order_id',o.id,'line',r.line,'quote_id',o.quote_id,
  'contract_ref',o.contract_ref,'contract_revision',o.contract_revision,
  'offer_digest',o.offer_digest,'terms_digest',o.terms_digest,'contract_digest',o.contract_digest,
  'catalog_version',o.quote_snapshot->>'catalog_version','account',r.account,'environment',r.environment,
  'reference',r.reference,'customer',r.customer,'currency',o.quote_snapshot->>'currency',
  'amount_cents',r.amount_cents,'due_date',r.due_date)
 AND s.evidence->'payment'=jsonb_build_object(
  'object','payment','id',b.payment_id,'customer',r.customer,'externalReference',r.reference,
  'currency','BRL','value_cents',r.amount_cents,'dueDate',r.due_date,'status','RECEIVED',
  'deleted',false,'subscription',NULL)
 AND s.evidence->>'reversal'='fixture_verified_clear'
 AND s.evidence->>'fixture_origin'='independent-read-scenario.v1'
 AND s.evidence-'scope'-'payment'-'reversal'-'fixture_origin'='{}'::jsonb
 AND encode(sha256(convert_to(s.evidence::text,'UTF8')),'hex')=p_digest
 ), appended AS (
  INSERT INTO onepage_buyer_lab.fixture_append_receipt_input(order_id,line,source_version,evidence_digest,fixture_only)
  SELECT m.order_id,m.line,m.source_version,p_digest,true FROM matched m
  ON CONFLICT DO NOTHING RETURNING 1
 )
 SELECT CASE WHEN EXISTS(SELECT FROM appended) THEN 'recorded'
  WHEN EXISTS(SELECT FROM matched m JOIN onepage_buyer_lab.fixture_append_receipt_input r
   USING(order_id,line,source_version) WHERE r.evidence_digest=p_digest) THEN 'replay'
  ELSE 'denied' END INTO result;
 RETURN result;
END $$;
ALTER FUNCTION onepage_buyer_lab.fixture_observe_receipt_input(uuid,text) OWNER TO onepage_buyer_owner;
ALTER FUNCTION onepage_buyer_lab.fixture_append_bound_receipt_input(uuid,text,bigint,text) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.fixture_observe_receipt_input(uuid,text),
 onepage_buyer_lab.fixture_append_bound_receipt_input(uuid,text,bigint,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.fixture_observe_receipt_input(uuid,text)
 TO onepage_fixture_receipt_observer;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.fixture_append_bound_receipt_input(uuid,text,bigint,text)
 TO onepage_fixture_receipt_writer;
COMMIT;
