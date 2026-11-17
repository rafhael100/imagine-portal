-- Synthetic disposable fixture only, after original #925 process and SQL attestation.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_onepage_buyer_ci'
 OR current_user<>'onepage_lab_bootstrap' OR session_user<>'onepage_lab_bootstrap'
 OR host(inet_server_addr()) IS DISTINCT FROM '127.0.0.1' OR inet_server_port() IS DISTINCT FROM 55439
 OR current_setting('data_directory') !~ '/onepage-pg-lab/data$'
 OR to_regclass('onepage_buyer_lab.fixture_charge_reservations') IS NULL
 THEN RAISE EXCEPTION 'DISPOSABLE_IDENTITY_REQUIRED'; END IF;
END $guard$;

-- Only bootstrap inserts fixture witnesses. No raw provider payload/caller claims.
CREATE TABLE onepage_buyer_lab.fixture_binding_witnesses (
 probe text PRIMARY KEY,
 order_id uuid NOT NULL,
 line text NOT NULL,
 contract_scope jsonb NOT NULL,
 account text NOT NULL,
 environment text NOT NULL CHECK(environment='sandbox'),
 customer text NOT NULL,
 reference text NOT NULL,
 currency text NOT NULL CHECK(currency='BRL'),
 amount_cents integer NOT NULL CHECK(amount_cents>0),
 due_date date NOT NULL,
 payment_id text NOT NULL CHECK(payment_id ~ '^pay_LAB[a-zA-Z0-9]{1,60}$'),
 FOREIGN KEY(order_id,line) REFERENCES onepage_buyer_lab.fixture_charge_reservations(order_id,line)
);
CREATE TABLE onepage_buyer_lab.fixture_charge_bindings (
 order_id uuid NOT NULL,
 line text NOT NULL,
 account text NOT NULL,
 environment text NOT NULL CHECK(environment='sandbox'),
 payment_id text NOT NULL CHECK(payment_id ~ '^pay_LAB[a-zA-Z0-9]{1,60}$'),
 PRIMARY KEY(order_id,line),
 UNIQUE(account,environment,payment_id),
 FOREIGN KEY(order_id,line) REFERENCES onepage_buyer_lab.fixture_charge_reservations(order_id,line)
);
REVOKE ALL ON onepage_buyer_lab.fixture_binding_witnesses,onepage_buyer_lab.fixture_charge_bindings FROM PUBLIC;
ALTER TABLE onepage_buyer_lab.fixture_binding_witnesses ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_binding_witnesses FORCE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_charge_bindings ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_charge_bindings FORCE ROW LEVEL SECURITY;
CREATE POLICY fixture_witness_read ON onepage_buyer_lab.fixture_binding_witnesses
 FOR SELECT TO onepage_buyer_owner USING(true);
CREATE POLICY fixture_binding_read ON onepage_buyer_lab.fixture_charge_bindings
 FOR SELECT TO onepage_buyer_owner USING(true);
CREATE POLICY fixture_binding_insert ON onepage_buyer_lab.fixture_charge_bindings
 FOR INSERT TO onepage_buyer_owner WITH CHECK(true);
GRANT SELECT ON onepage_buyer_lab.fixture_binding_witnesses TO onepage_buyer_owner;
GRANT SELECT, INSERT ON onepage_buyer_lab.fixture_charge_bindings TO onepage_buyer_owner;

CREATE FUNCTION onepage_buyer_lab.fixture_bind_charge(p_session text,p_order uuid,p_line text)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE old onepage_buyer_lab.fixture_charge_bindings%ROWTYPE;
        reserved onepage_buyer_lab.fixture_charge_reservations%ROWTYPE;
        total bigint;
        eligible text[];
        own jsonb;
BEGIN
 IF session_user<>'onepage_buyer_runtime'
 OR current_setting('transaction_isolation')<>'read committed' THEN RETURN 'denied'; END IF;
 -- Native reader locks current session/identity/membership/organization
 -- via principal() until the binding transaction commits.
 own := onepage_buyer_lab.read_pending_order(p_session,p_order);
 IF own IS NULL THEN RETURN 'denied'; END IF;

 SELECT * INTO reserved FROM onepage_buyer_lab.fixture_charge_reservations r
 WHERE r.order_id=p_order AND r.line=p_line FOR SHARE;
 IF NOT FOUND OR NOT reserved.attempted THEN RETURN 'denied'; END IF;
 IF reserved.contract_scope IS DISTINCT FROM (own - ARRAY['buyer','status','quote_snapshot','terms','contract_text'])
 THEN RETURN 'conflict'; END IF;

 -- Resolve exclusively from bootstrap-owned evidence for the locked key.
 -- Count all rows, then compare their frozen scope in the same statement;
 -- even agreeing duplicates or malformed evidence cannot create a link.
 SELECT count(*), array_agg(w.payment_id) FILTER (WHERE
    (w.contract_scope,w.account,w.environment,w.customer,w.reference,w.amount_cents,w.due_date,w.currency)
    IS NOT DISTINCT FROM
    (reserved.contract_scope,reserved.account,reserved.environment,reserved.customer,
     reserved.reference,reserved.amount_cents,reserved.due_date,'BRL'::text))
 INTO total, eligible
 FROM onepage_buyer_lab.fixture_binding_witnesses w
 WHERE w.order_id=reserved.order_id AND w.line=reserved.line;
 IF total=0 THEN RETURN 'pending'; END IF;
 IF total<>1 OR coalesce(cardinality(eligible),0)<>1 THEN RETURN 'conflict'; END IF;

 INSERT INTO onepage_buyer_lab.fixture_charge_bindings(order_id,line,account,environment,payment_id)
 VALUES(p_order,p_line,reserved.account,reserved.environment,eligible[1]) ON CONFLICT DO NOTHING;
 IF FOUND THEN RETURN 'linked'; END IF;
 -- A new command snapshot in READ COMMITTED sees a concurrent winner.
 SELECT * INTO old FROM onepage_buyer_lab.fixture_charge_bindings
 WHERE order_id=p_order AND line=p_line;
 IF FOUND AND (old.account,old.environment,old.payment_id)=(reserved.account,reserved.environment,eligible[1])
 THEN RETURN 'replay'; END IF;
 RETURN 'conflict';
END $$;
ALTER FUNCTION onepage_buyer_lab.fixture_bind_charge(text,uuid,text) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.fixture_bind_charge(text,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.fixture_bind_charge(text,uuid,text) TO onepage_buyer_runtime;
COMMIT;
