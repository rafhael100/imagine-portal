-- PRIVATE LAB ONLY. NOT a product migration. Never execute against an existing cluster.
-- Requires independently qualified fresh PG18 and pinned native #960 schema first.
-- Installer must establish effective-config + exact PDO/cluster identity BEFORE any DDL.
-- No binding producer, PSP transport, buyer session, entitlement or briefing side effect.
BEGIN;
CREATE ROLE onepage_finance_lab_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
CREATE ROLE onepage_finance_lab_verifier NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
CREATE SCHEMA onepage_finance_lab AUTHORIZATION onepage_finance_lab_owner;
REVOKE ALL ON SCHEMA onepage_finance_lab FROM PUBLIC;
GRANT USAGE ON SCHEMA onepage_finance_lab TO onepage_finance_lab_verifier,portal_onepage_payment_owner;

CREATE TABLE onepage_finance_lab.observations (
 account_ref text NOT NULL,
 environment text NOT NULL CHECK(environment='sandbox'),
 order_id uuid NOT NULL UNIQUE,
 payment_id text NOT NULL,
 expected jsonb NOT NULL CHECK(jsonb_typeof(expected)='object'),
 receipt jsonb NOT NULL CHECK(jsonb_typeof(receipt)='object'),
 evidence_sha256 text NOT NULL CHECK(evidence_sha256 ~ '^[a-f0-9]{64}$'),
 observed_at timestamptz NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(account_ref,payment_id)
);
CREATE TABLE onepage_finance_lab.requests (
 account_ref text NOT NULL,
 request_id uuid NOT NULL,
 payment_id text NOT NULL,
 payload jsonb NOT NULL CHECK(jsonb_typeof(payload)='object'),
 PRIMARY KEY(account_ref,request_id),
 FOREIGN KEY(account_ref,payment_id) REFERENCES onepage_finance_lab.observations(account_ref,payment_id)
);
ALTER TABLE onepage_finance_lab.observations OWNER TO onepage_finance_lab_owner;
ALTER TABLE onepage_finance_lab.requests OWNER TO onepage_finance_lab_owner;
REVOKE ALL ON onepage_finance_lab.observations,onepage_finance_lab.requests FROM PUBLIC,onepage_finance_lab_verifier;
ALTER TABLE onepage_finance_lab.observations ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_finance_lab.observations FORCE ROW LEVEL SECURITY;
ALTER TABLE onepage_finance_lab.requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_finance_lab.requests FORCE ROW LEVEL SECURITY;
CREATE POLICY financial_observation_read ON onepage_finance_lab.observations FOR SELECT TO onepage_finance_lab_owner USING(session_user='onepage_finance_lab_verifier');
CREATE POLICY financial_observation_append ON onepage_finance_lab.observations FOR INSERT TO onepage_finance_lab_owner WITH CHECK(session_user='onepage_finance_lab_verifier');
CREATE POLICY financial_request_read ON onepage_finance_lab.requests FOR SELECT TO onepage_finance_lab_owner USING(session_user='onepage_finance_lab_verifier');
CREATE POLICY financial_request_append ON onepage_finance_lab.requests FOR INSERT TO onepage_finance_lab_owner WITH CHECK(session_user='onepage_finance_lab_verifier');
CREATE FUNCTION onepage_finance_lab.reject_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
BEGIN RAISE EXCEPTION 'FINANCE_LAB_IMMUTABLE'; END $$;
ALTER FUNCTION onepage_finance_lab.reject_mutation() OWNER TO onepage_finance_lab_owner;
REVOKE ALL ON FUNCTION onepage_finance_lab.reject_mutation() FROM PUBLIC;
CREATE TRIGGER immutable_observations BEFORE UPDATE OR DELETE ON onepage_finance_lab.observations FOR EACH ROW EXECUTE FUNCTION onepage_finance_lab.reject_mutation();
CREATE TRIGGER immutable_requests BEFORE UPDATE OR DELETE ON onepage_finance_lab.requests FOR EACH ROW EXECUTE FUNCTION onepage_finance_lab.reject_mutation();

-- Reuses native grants of payment_owner; no table SELECT or schema CREATE granted
-- to verifier/finance owner. Bound account gate remains operator-owned and denying
-- by default. Fixture enrollment is separate, never copied from external content.
CREATE FUNCTION onepage_finance_lab.inspect_binding(p_account text,p_environment text,p_payment text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE ctx jsonb;again jsonb;b portal_onepage.entry_payment_bindings%ROWTYPE;auth jsonb;p_order uuid;
BEGIN
 IF session_user<>'onepage_finance_lab_verifier' OR current_setting('transaction_isolation')<>'read committed'
 OR p_account IS NULL OR p_payment IS NULL OR p_payment !~ '^pay_[A-Za-z0-9]{6,64}$' OR p_environment IS DISTINCT FROM 'sandbox'
 OR p_account IS DISTINCT FROM portal_onepage.d0_account_gate() THEN RETURN NULL; END IF;
 -- Input payment ID is a search key only. Order comes ONLY from the existing
 -- immutable binding; no event-supplied order or externalReference routing.
 BEGIN
  SELECT order_id INTO STRICT p_order FROM portal_onepage.entry_payment_bindings
  WHERE account_ref=p_account AND payment_id=p_payment AND line_key='setup-1';
 EXCEPTION WHEN no_data_found OR too_many_rows THEN RETURN NULL;
 END;
 -- Native terms_usable locks publication first, before account/binding, same order
 -- as D0. No session/MFA read is necessary for a financial-only observation.
 ctx=portal_onepage.entry_payment_context(p_order);
 IF ctx IS NULL THEN RETURN NULL; END IF;
 PERFORM portal_onepage.d0_lock_account(p_account);
 PERFORM portal_onepage.d0_lock_binding(p_order);
 again=portal_onepage.entry_payment_context(p_order);
 IF again IS DISTINCT FROM ctx THEN RETURN NULL; END IF;
 SELECT jsonb_build_object('configuration',configuration_ref,'generation',authorization_generation::text)
 INTO auth FROM portal_onepage.payment_accounts
 WHERE account_ref=p_account AND environment='sandbox' AND active
 AND configuration_ref IS NOT NULL AND authorization_generation IS NOT NULL;
 IF auth IS NULL OR EXISTS(SELECT FROM portal_onepage.entry_payment_revocations WHERE order_id=p_order) THEN RETURN NULL; END IF;
 BEGIN
  SELECT * INTO STRICT b FROM portal_onepage.entry_payment_bindings
  WHERE order_id=p_order AND account_ref=p_account AND payment_id=p_payment AND context=ctx AND line_key='setup-1';
 EXCEPTION WHEN no_data_found OR too_many_rows THEN RETURN NULL;
 END;
 IF NOT EXISTS(SELECT FROM portal_onepage.payment_customers
 WHERE company_id=(ctx->>'company_id')::uuid AND account_ref=p_account AND customer_id=b.customer_id)
 OR b.external_reference IS DISTINCT FROM portal_onepage.entry_payment_reference(ctx,p_account,b.customer_id)
 OR ctx->>'product_code' IS DISTINCT FROM 'onepage' THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('account',p_account,'environment','sandbox','context',ctx,'account_authorization',auth,
 'binding',jsonb_build_object('payment_id',b.payment_id,'customer_id',b.customer_id,'external_reference',b.external_reference,
 'amount_cents',b.amount_cents,'billing_type',b.billing_type,'due_date',b.due_date));
END $$;
ALTER FUNCTION onepage_finance_lab.inspect_binding(text,text,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION onepage_finance_lab.inspect_binding(text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_finance_lab.inspect_binding(text,text,text) TO onepage_finance_lab_verifier,onepage_finance_lab_owner;

-- Receipt is a claim admitted only from the restricted verifier after its
-- independent read. SQL cannot prove PSP truth; webhook authentication alone
-- must NEVER be sufficient to obtain this LOGIN or invoke this function.
CREATE FUNCTION onepage_finance_lab.record_observation(p_request uuid,p_account text,p_environment text,p_order uuid,
 p_expected jsonb,p_receipt jsonb,p_digest text,p_observed timestamptz) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE current_snapshot jsonb;required_receipt jsonb;payload jsonb;saved_payload jsonb;
 saved onepage_finance_lab.observations%ROWTYPE;payment text;had_request boolean;
BEGIN
 IF session_user<>'onepage_finance_lab_verifier' OR current_setting('transaction_isolation')<>'read committed'
 OR p_request IS NULL OR p_expected IS NULL OR p_receipt IS NULL OR p_digest IS NULL
 OR p_digest !~ '^[a-f0-9]{64}$' OR p_observed IS NULL OR NOT isfinite(p_observed)
 OR p_observed>clock_timestamp() THEN RETURN 'denied'; END IF;
 current_snapshot=onepage_finance_lab.inspect_binding(p_account,p_environment,p_expected->'binding'->>'payment_id');
 IF current_snapshot IS NULL OR current_snapshot IS DISTINCT FROM p_expected OR p_order IS NULL
 OR current_snapshot->'context'->>'order_ref' IS DISTINCT FROM p_order::text THEN RETURN 'denied'; END IF;
 -- inspect_binding holds the SAME native account/binding coordination locks
 -- until this transaction ends, fencing revocation/config generation changes.
 payment=current_snapshot->'binding'->>'payment_id';
 required_receipt=((current_snapshot->'binding') - 'due_date'::text)||jsonb_build_object('currency','BRL','status','RECEIVED');
 -- Negative current provider observation never gets a positive replay/ACK.
 IF p_receipt->>'status' IS DISTINCT FROM 'RECEIVED' THEN RETURN 'denied'; END IF;
 -- Attempt-local clock is metadata, not financial/request identity.
 payload=jsonb_build_object('order',p_order,'environment',p_environment,'expected',p_expected,
 'receipt',p_receipt,'evidence_sha256',p_digest);
 SELECT r.payload INTO saved_payload FROM onepage_finance_lab.requests r
 WHERE r.account_ref=p_account AND r.request_id=p_request;
 had_request=FOUND;
 IF had_request THEN
  IF saved_payload=payload THEN RETURN 'replay'; ELSE RETURN 'conflict'; END IF;
 END IF;
 IF p_receipt IS DISTINCT FROM required_receipt THEN RETURN 'denied'; END IF;
 SELECT * INTO saved FROM onepage_finance_lab.observations
 WHERE account_ref=p_account AND payment_id=payment;
 IF FOUND THEN
  IF saved.order_id<>p_order OR saved.expected<>p_expected OR saved.receipt<>p_receipt OR saved.evidence_sha256<>p_digest THEN RETURN 'conflict'; END IF;
  -- A distinct request for same immutable financial fact is an alias, not a
  -- second posting. Remember its payload so its ID cannot later be reused.
  INSERT INTO onepage_finance_lab.requests(account_ref,request_id,payment_id,payload) VALUES(p_account,p_request,payment,payload);
  RETURN 'replay';
 END IF;
 IF EXISTS(SELECT FROM onepage_finance_lab.observations WHERE order_id=p_order) THEN RETURN 'conflict'; END IF;
 -- Final native readback under coordination; no cached buyer authority.
 IF onepage_finance_lab.inspect_binding(p_account,p_environment,p_expected->'binding'->>'payment_id') IS DISTINCT FROM current_snapshot THEN RETURN 'denied'; END IF;
 INSERT INTO onepage_finance_lab.observations(account_ref,environment,order_id,payment_id,expected,receipt,evidence_sha256,observed_at)
 VALUES(p_account,p_environment,p_order,payment,p_expected,p_receipt,p_digest,p_observed);
 INSERT INTO onepage_finance_lab.requests(account_ref,request_id,payment_id,payload) VALUES(p_account,p_request,payment,payload);
 RETURN 'recorded';
END $$;
ALTER FUNCTION onepage_finance_lab.record_observation(uuid,text,text,uuid,jsonb,jsonb,text,timestamptz) OWNER TO onepage_finance_lab_owner;
REVOKE ALL ON FUNCTION onepage_finance_lab.record_observation(uuid,text,text,uuid,jsonb,jsonb,text,timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_finance_lab.record_observation(uuid,text,text,uuid,jsonb,jsonb,text,timestamptz) TO onepage_finance_lab_verifier;
COMMIT;
