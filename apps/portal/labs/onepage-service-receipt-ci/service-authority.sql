-- Private disposable-lab candidate ONLY. Requires independent gate revision;
-- ServiceGate #979 does not allow the new role. Not an operational migration.
-- Inspect derived from the fixed finance.sql used by #979; exact source SHA256:
-- da176ef9c16e5d32059456bccedbf344cfdc7dc5a6c6aad60c597feaab6e4c91
-- D30 and target table copied as rules from portal_onepage_d0_account_epoch.sql
-- (Git blob a917cd4c7a486fb703b3a37bfe7a9ee68733804f).
BEGIN;
CREATE ROLE onepage_d0_service_b LOGIN NOINHERIT NOSUPERUSER NOBYPASSRLS
 NOCREATEDB NOCREATEROLE NOREPLICATION;
REVOKE ALL ON SCHEMA portal_onepage FROM onepage_d0_service_b;
GRANT USAGE ON SCHEMA portal_onepage TO onepage_d0_service_b;
-- Additional insert path only; existing human verifier policies remain unchanged.
CREATE POLICY d0_service_b_append ON portal_onepage.entry_confirmations
 AS PERMISSIVE FOR INSERT TO portal_onepage_payment_owner
 WITH CHECK (session_user='onepage_d0_service_b' AND environment='sandbox');

CREATE FUNCTION portal_onepage.d0_service_b_inspect(p_account text,p_environment text,p_payment text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE ctx jsonb;again jsonb;b portal_onepage.entry_payment_bindings%ROWTYPE;auth jsonb;p_order uuid;
BEGIN
 IF current_user<>'portal_onepage_payment_owner' OR session_user<>'onepage_d0_service_b' OR current_setting('transaction_isolation')<>'read committed'
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
 -- Match the existing human candidate's Sao Paulo calendar D30 boundary
 -- before admitting a new confirmation. No invented D30/D60 settlement ledger.
 IF ((ctx->>'accepted_at')::timestamptz AT TIME ZONE 'America/Sao_Paulo')::date+30
  < (clock_timestamp() AT TIME ZONE 'America/Sao_Paulo')::date THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('account',p_account,'environment','sandbox','context',ctx,'account_authorization',auth,
 'binding',jsonb_build_object('payment_id',b.payment_id,'customer_id',b.customer_id,'external_reference',b.external_reference,
 'amount_cents',b.amount_cents,'billing_type',b.billing_type,'due_date',b.due_date));
END $$;

ALTER FUNCTION portal_onepage.d0_service_b_inspect(text,text,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_service_b_inspect(text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.d0_service_b_inspect(text,text,text) TO onepage_d0_service_b;

-- Narrow attestation of a Consumer-owned observation. No ledger SELECT for service.
-- Existing finance owner owns SELECT rights; additional RLS admits ONLY this session.
CREATE POLICY financial_observation_service_b_match ON onepage_finance_lab.observations
 FOR SELECT TO onepage_finance_lab_owner USING(session_user='onepage_d0_service_b');
CREATE FUNCTION onepage_finance_lab.service_b_observation_matches(
 p_account text,p_environment text,p_order uuid,p_payment text,p_expected jsonb,p_receipt jsonb,p_digest text
) RETURNS boolean
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
BEGIN
 IF current_user<>'onepage_finance_lab_owner' OR session_user<>'onepage_d0_service_b' THEN RETURN false; END IF;
 RETURN EXISTS(SELECT FROM onepage_finance_lab.observations o
 WHERE o.account_ref=p_account AND o.environment=p_environment AND o.order_id=p_order AND o.payment_id=p_payment
 AND o.expected=p_expected AND o.receipt=p_receipt AND o.evidence_sha256=p_digest);
END $$;
ALTER FUNCTION onepage_finance_lab.service_b_observation_matches(text,text,uuid,text,jsonb,jsonb,text) OWNER TO onepage_finance_lab_owner;
REVOKE ALL ON FUNCTION onepage_finance_lab.service_b_observation_matches(text,text,uuid,text,jsonb,jsonb,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_finance_lab.service_b_observation_matches(text,text,uuid,text,jsonb,jsonb,text) TO portal_onepage_payment_owner;

-- Trusted service reads a current provider response INDEPENDENTLY, outside
-- DB transactions. SQL validates the exact normalized receipt but cannot
-- authenticate PSP truth; an ingress handler must NOT hold this service role.
CREATE FUNCTION portal_onepage.d0_service_b_confirm(
 p_account text,p_environment text,p_payment text,p_expected jsonb,
 p_receipt jsonb,p_digest text,p_observed timestamptz
) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE now_snap jsonb; expected_receipt jsonb; p_order uuid;
 saved portal_onepage.entry_confirmations%ROWTYPE;
BEGIN
 IF current_user<>'portal_onepage_payment_owner' OR session_user<>'onepage_d0_service_b'
 OR current_setting('transaction_isolation')<>'read committed'
 OR p_expected IS NULL OR p_receipt IS NULL OR p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
 OR p_observed IS NULL OR NOT isfinite(p_observed) OR p_observed>clock_timestamp() THEN RETURN 'denied'; END IF;
 now_snap=portal_onepage.d0_service_b_inspect(p_account,p_environment,p_payment);
 IF now_snap IS NULL OR now_snap IS DISTINCT FROM p_expected THEN RETURN 'denied'; END IF;
 p_order=(now_snap->'context'->>'order_ref')::uuid;
 IF p_order IS NULL THEN RETURN 'denied'; END IF;
 expected_receipt=((now_snap->'binding')-'due_date')||jsonb_build_object('currency','BRL','status','RECEIVED');
 IF p_receipt IS DISTINCT FROM expected_receipt THEN RETURN 'denied'; END IF;
 IF NOT onepage_finance_lab.service_b_observation_matches(p_account,p_environment,p_order,p_payment,p_expected,p_receipt,p_digest)
 THEN RETURN 'denied'; END IF;
 -- The inspect locks are held until this transaction ends. Statements below
 -- use the native table and immutable PK/UNIQUE, shared by the human path.
 SELECT * INTO saved FROM portal_onepage.entry_confirmations WHERE order_id=p_order;
 IF FOUND THEN
  IF saved.account_ref=p_account AND saved.environment=p_environment AND saved.payment_id=p_payment
   AND saved.context=now_snap->'context' AND saved.binding=now_snap->'binding'
   AND saved.evidence_sha256=p_digest THEN RETURN 'replay'; END IF;
  RETURN 'conflict';
 END IF;
 BEGIN
  INSERT INTO portal_onepage.entry_confirmations
   (order_id,account_ref,environment,payment_id,context,binding,evidence_sha256,observed_at)
  VALUES(p_order,p_account,p_environment,p_payment,now_snap->'context',now_snap->'binding',p_digest,p_observed);
 EXCEPTION WHEN unique_violation THEN RETURN 'conflict';
 END;
 RETURN 'recorded';
END $$;
ALTER FUNCTION portal_onepage.d0_service_b_confirm(text,text,text,jsonb,jsonb,text,timestamptz) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_service_b_confirm(text,text,text,jsonb,jsonb,text,timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.d0_service_b_confirm(text,text,text,jsonb,jsonb,text,timestamptz) TO onepage_d0_service_b;
COMMIT;
