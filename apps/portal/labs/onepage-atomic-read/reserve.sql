-- LAB ONLY; invoked inside the guarded #924 fixture, never startup or migration.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_onepage_buyer_ci'
 OR current_user<>'onepage_lab_bootstrap' OR session_user<>'onepage_lab_bootstrap'
 OR host(inet_server_addr()) IS DISTINCT FROM '127.0.0.1'
 OR inet_server_port() IS DISTINCT FROM 55439
 OR current_setting('data_directory') !~ '/onepage-pg-lab/data$'
 THEN RAISE EXCEPTION 'DISPOSABLE_IDENTITY_REQUIRED'; END IF;
END $guard$;
CREATE TABLE onepage_buyer_lab.fixture_charge_reservations (
 order_id uuid NOT NULL REFERENCES onepage_buyer_lab.pending_orders(id),
 line text NOT NULL CHECK(line IN ('setup-1','setup-2','setup-3')),
 contract_scope jsonb NOT NULL CHECK(jsonb_typeof(contract_scope)='object'),
 account text NOT NULL CHECK(account ~ '^lab-[a-zA-Z0-9-]{1,60}$'),
 environment text NOT NULL CHECK(environment='sandbox'),
 customer text NOT NULL CHECK(customer ~ '^cus_LAB[a-zA-Z0-9]{1,60}$'),
 reference text NOT NULL CHECK(reference ~ '^imagine:lab-op-v1:[a-f0-9]{64}$'),
 due_date date NOT NULL,
 amount_cents integer NOT NULL CHECK(amount_cents>0),
 attempted boolean NOT NULL DEFAULT false,
 PRIMARY KEY(order_id,line),
 UNIQUE(account,environment,reference)
);
REVOKE ALL ON onepage_buyer_lab.fixture_charge_reservations FROM PUBLIC;
ALTER TABLE onepage_buyer_lab.fixture_charge_reservations ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_charge_reservations FORCE ROW LEVEL SECURITY;
CREATE POLICY fixture_cas ON onepage_buyer_lab.fixture_charge_reservations
 FOR ALL TO onepage_buyer_owner USING(true) WITH CHECK(true);
GRANT SELECT, UPDATE(attempted) ON onepage_buyer_lab.fixture_charge_reservations TO onepage_buyer_owner;

-- True designates the first fake-send attempt *only after this transaction commits*.
-- Neither a release nor settlement claim. No capability to reset or resend.
CREATE FUNCTION onepage_buyer_lab.fixture_claim_charge(p_session text,p_order uuid,p_line text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE own jsonb;
BEGIN
 IF session_user<>'onepage_buyer_runtime' THEN RETURN false; END IF;
 own := onepage_buyer_lab.read_pending_order(p_session,p_order);
 IF own IS NULL THEN RETURN false; END IF;
 UPDATE onepage_buyer_lab.fixture_charge_reservations AS r
 SET attempted=true
 WHERE r.order_id=p_order AND r.line=p_line AND NOT r.attempted
 AND r.contract_scope = (own - ARRAY['buyer','status','quote_snapshot','terms','contract_text'])
 AND (SELECT count(*) FROM onepage_buyer_lab.fixture_charge_reservations AS batch
      WHERE batch.order_id=p_order AND batch.contract_scope=r.contract_scope
         AND batch.account=r.account AND batch.environment=r.environment AND batch.customer=r.customer)=3;
 RETURN FOUND;
END $$;
ALTER FUNCTION onepage_buyer_lab.fixture_claim_charge(text,uuid,text) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.fixture_claim_charge(text,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.fixture_claim_charge(text,uuid,text) TO onepage_buyer_runtime;
COMMIT;
