-- Additive native expected-payment ledger. No PSP call, account seed, paid flag
-- or automatic installation. Apply only after portal_onepage_orders.sql in an explicitly
-- prepared environment. All roles and account/receipt ledgers start closed/empty.
BEGIN;
CREATE ROLE portal_onepage_payment_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
CREATE ROLE portal_onepage_psp_registrar NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
GRANT USAGE ON SCHEMA portal_onepage TO portal_onepage_payment_owner,portal_onepage_psp_registrar;

CREATE TABLE portal_onepage.payment_accounts (
 account_ref text PRIMARY KEY CHECK(account_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$'),
 environment text NOT NULL CHECK(environment='sandbox'),
 active boolean NOT NULL DEFAULT false
);
CREATE TABLE portal_onepage.payment_customers (
 company_id uuid NOT NULL REFERENCES public.portal_organizations(id),
 account_ref text NOT NULL REFERENCES portal_onepage.payment_accounts(account_ref),
 customer_id text NOT NULL CHECK(customer_id ~ '^cus_[A-Za-z0-9]{6,64}$'),
 registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(company_id,account_ref), UNIQUE(account_ref,customer_id)
);
CREATE TABLE portal_onepage.entry_payment_bindings (
 order_id uuid PRIMARY KEY REFERENCES portal_onepage.pending_orders(id),
 account_ref text NOT NULL REFERENCES portal_onepage.payment_accounts(account_ref),
 customer_id text NOT NULL, payment_id text NOT NULL UNIQUE CHECK(payment_id ~ '^pay_[A-Za-z0-9]{6,64}$'),
 external_reference text NOT NULL UNIQUE,
 amount_cents integer NOT NULL CHECK(amount_cents=33000),
 due_date date NOT NULL, line_key text NOT NULL CHECK(line_key='setup-1'),
 billing_type text NOT NULL CHECK(billing_type IN ('PIX','CREDIT_CARD','BOLETO')),
 context jsonb NOT NULL,
 registered_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(account_ref,customer_id) REFERENCES portal_onepage.payment_customers(account_ref,customer_id)
);
CREATE TABLE portal_onepage.entry_payment_revocations (
 order_id uuid PRIMARY KEY REFERENCES portal_onepage.entry_payment_bindings(order_id),
 reason_ref text NOT NULL CHECK(reason_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 revoked_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

-- The browser/runtime receives EXECUTE only. Account enrollment remains an
-- operator-owned action; the registrar cannot enroll or activate an account.
REVOKE ALL ON portal_onepage.payment_accounts,portal_onepage.payment_customers,
 portal_onepage.entry_payment_bindings,portal_onepage.entry_payment_revocations FROM PUBLIC;
GRANT SELECT ON portal_onepage.payment_accounts TO portal_onepage_payment_owner;
GRANT SELECT,INSERT ON portal_onepage.payment_customers,portal_onepage.entry_payment_bindings,
 portal_onepage.entry_payment_revocations TO portal_onepage_payment_owner;
GRANT SELECT ON portal_onepage.pending_orders,portal_onepage.order_terms TO portal_onepage_payment_owner;
GRANT SELECT(id,reference,status) ON public.portal_organizations TO portal_onepage_payment_owner;
CREATE POLICY payment_native_order_read ON portal_onepage.pending_orders FOR SELECT TO portal_onepage_payment_owner USING(true);
CREATE POLICY payment_order_terms_read ON portal_onepage.order_terms FOR SELECT TO portal_onepage_payment_owner USING(true);
CREATE POLICY payment_organization_read ON public.portal_organizations FOR SELECT TO portal_onepage_payment_owner USING(true);
GRANT EXECUTE ON FUNCTION portal_onepage.read_pending_order(text,uuid) TO portal_onepage_payment_owner;
GRANT EXECUTE ON FUNCTION portal_onepage.terms_usable(portal_onepage.order_terms) TO portal_onepage_payment_owner;

ALTER TABLE portal_onepage.payment_accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.payment_accounts FORCE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.payment_customers ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.payment_customers FORCE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.entry_payment_bindings ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.entry_payment_bindings FORCE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.entry_payment_revocations ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.entry_payment_revocations FORCE ROW LEVEL SECURITY;
CREATE POLICY payment_account_read ON portal_onepage.payment_accounts FOR SELECT TO portal_onepage_payment_owner USING(true);
CREATE POLICY payment_customer_read ON portal_onepage.payment_customers FOR SELECT TO portal_onepage_payment_owner USING(true);
CREATE POLICY payment_customer_append ON portal_onepage.payment_customers FOR INSERT TO portal_onepage_payment_owner
 WITH CHECK(session_user='portal_onepage_psp_registrar');
CREATE POLICY entry_payment_read ON portal_onepage.entry_payment_bindings FOR SELECT TO portal_onepage_payment_owner USING(true);
CREATE POLICY entry_payment_append ON portal_onepage.entry_payment_bindings FOR INSERT TO portal_onepage_payment_owner
 WITH CHECK(session_user='portal_onepage_psp_registrar');
CREATE POLICY entry_payment_revocation_read ON portal_onepage.entry_payment_revocations FOR SELECT TO portal_onepage_payment_owner USING(true);
CREATE POLICY entry_payment_revocation_append ON portal_onepage.entry_payment_revocations FOR INSERT TO portal_onepage_payment_owner
 WITH CHECK(session_user='portal_onepage_psp_registrar');

CREATE FUNCTION portal_onepage.reject_payment_binding_change() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
BEGIN RAISE EXCEPTION 'Immutable expected-payment record'; END $$;
REVOKE ALL ON FUNCTION portal_onepage.reject_payment_binding_change() FROM PUBLIC;
CREATE TRIGGER immutable_payment_customers BEFORE UPDATE OR DELETE ON portal_onepage.payment_customers
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_payment_binding_change();
CREATE TRIGGER immutable_entry_payment_bindings BEFORE UPDATE OR DELETE ON portal_onepage.entry_payment_bindings
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_payment_binding_change();
CREATE TRIGGER immutable_entry_payment_revocations BEFORE UPDATE OR DELETE ON portal_onepage.entry_payment_revocations
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_payment_binding_change();

-- Exact accepted policy, not a reinterpretation of the quote's illustration.
CREATE FUNCTION portal_onepage.entry_policy_matches(p_terms jsonb,p_quote jsonb) RETURNS boolean
LANGUAGE sql IMMUTABLE STRICT SET search_path=pg_catalog,pg_temp AS $$
 SELECT p_terms->'onepage_payment_policy' = '{
  "version":"onepage-d0-d90-v1","currency":"BRL","setup_total_cents":99000,
  "setup_charges":[{"offset_days":0,"amount_cents":33000},{"offset_days":30,"amount_cents":33000},{"offset_days":60,"amount_cents":33000}],
  "monthly":{"amount_cents":7990,"mandatory":true,"first_due_offset_days":90,"anchor":"contract_acceptance","independent_of_publication":true},
  "entry_installment_key":"setup-1","briefing_after_entry_confirmation":true,"publication_requires_separate_acceptance":true
 }'::jsonb
 AND p_quote->>'site'='onepage' AND p_quote->'channels'='[]'::jsonb
 AND p_quote->>'currency'='BRL' AND p_quote->'site_setup_cents'='99000'::jsonb
 AND p_quote->'illustrative_three_parts_cents'='[33000,33000,33000]'::jsonb
 AND p_quote->'site_monthly_cents'='7990'::jsonb
 AND p_quote->'site_monthly_first_due_offset_days'='90'::jsonb
 AND p_quote->>'site_monthly_anchor'='contract_acceptance'
 AND p_quote->'site_monthly_independent_of_publication'='true'::jsonb
$$;
ALTER FUNCTION portal_onepage.entry_policy_matches(jsonb,jsonb) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.entry_policy_matches(jsonb,jsonb) FROM PUBLIC;

-- Private immutable order projection used by the registrar. It never accepts
-- company, tenant, prices, contract identity or acceptance dates from the caller.
CREATE FUNCTION portal_onepage.entry_payment_context(p_order uuid) RETURNS jsonb
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
 SELECT jsonb_build_object(
  'order_ref',o.id,'quote_id',o.quote_id,'company_id',o.company,'tenant_ref',org.reference,'buyer_id',o.buyer,
  'publication_ref',o.publication_ref,'contract_ref',o.contract_ref,'contract_revision',o.contract_revision,
  'catalog_version',o.quote_snapshot->>'catalog_version','offer_digest',o.offer_digest,
  'terms_digest',o.terms_digest,'contract_digest',o.contract_digest,
  'accepted_at',to_char(o.accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
  'product_code','onepage','line_key','setup-1','amount_cents',33000,
  'due_date',(o.accepted_at AT TIME ZONE 'America/Sao_Paulo')::date)
 FROM portal_onepage.pending_orders o
 JOIN public.portal_organizations org ON org.id=o.company AND org.status='active'
 JOIN portal_onepage.order_terms t ON t.quote_id=o.quote_id AND t.publication_ref=o.publication_ref
 WHERE o.id=p_order AND o.status='pending_payment'
 AND portal_onepage.entry_policy_matches(o.terms,o.quote_snapshot)
 AND t.quote_snapshot=o.quote_snapshot AND t.terms=o.terms AND t.contract_ref=o.contract_ref
 AND t.contract_revision=o.contract_revision AND t.contract_text=o.contract_text
 AND portal_onepage.terms_usable(t)
$$;
ALTER FUNCTION portal_onepage.entry_payment_context(uuid) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.entry_payment_context(uuid) FROM PUBLIC;

CREATE FUNCTION portal_onepage.entry_payment_reference(p_context jsonb,p_account text,p_customer text) RETURNS text
LANGUAGE sql IMMUTABLE STRICT SET search_path=pg_catalog,pg_temp AS $$
 SELECT 'imagine:onepage:setup-1:'||encode(sha256(convert_to(array_to_string(ARRAY[
  'imagine.onepage.setup-reference.v1',p_context->>'order_ref',p_context->>'quote_id',
  p_context->>'company_id',p_context->>'tenant_ref',p_context->>'buyer_id',p_context->>'publication_ref',
  p_context->>'contract_ref',p_context->>'contract_revision',p_context->>'catalog_version',
  p_context->>'offer_digest',p_context->>'terms_digest',p_context->>'contract_digest',p_context->>'accepted_at',
  p_account,'sandbox',p_customer,'setup-1',p_context->>'due_date',p_context->>'amount_cents','BRL'
 ],chr(10)),'UTF8')),'hex')
$$;
ALTER FUNCTION portal_onepage.entry_payment_reference(jsonb,text,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.entry_payment_reference(jsonb,text,text) FROM PUBLIC;

CREATE FUNCTION portal_onepage.register_payment_customer(p_order uuid,p_account text,p_customer text) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE ctx jsonb; existing text;
BEGIN
 IF session_user<>'portal_onepage_psp_registrar' OR current_setting('transaction_isolation')<>'read committed'
 OR p_customer IS NULL OR p_customer !~ '^cus_[A-Za-z0-9]{6,64}$'
 OR NOT EXISTS(SELECT FROM portal_onepage.payment_accounts WHERE account_ref=p_account AND environment='sandbox' AND active)
 THEN RETURN 'denied'; END IF;
 ctx=portal_onepage.entry_payment_context(p_order);
 IF ctx IS NULL THEN RETURN 'denied'; END IF;
 INSERT INTO portal_onepage.payment_customers(company_id,account_ref,customer_id)
 VALUES((ctx->>'company_id')::uuid,p_account,p_customer) ON CONFLICT DO NOTHING;
 IF FOUND THEN RETURN 'recorded'; END IF;
 SELECT customer_id INTO existing FROM portal_onepage.payment_customers
 WHERE company_id=(ctx->>'company_id')::uuid AND account_ref=p_account;
 RETURN CASE WHEN existing=p_customer THEN 'replay' ELSE 'denied' END;
END $$;
ALTER FUNCTION portal_onepage.register_payment_customer(uuid,text,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.register_payment_customer(uuid,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.register_payment_customer(uuid,text,text) TO portal_onepage_psp_registrar;

CREATE FUNCTION portal_onepage.register_entry_payment(p_order uuid,p_account text,p_payment text,p_customer text,
 p_reference text,p_amount integer,p_due date,p_billing text) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE ctx jsonb; existing portal_onepage.entry_payment_bindings%ROWTYPE;
BEGIN
 IF session_user<>'portal_onepage_psp_registrar' OR current_setting('transaction_isolation')<>'read committed'
 OR p_payment IS NULL OR p_payment !~ '^pay_[A-Za-z0-9]{6,64}$'
 OR p_billing IS NULL OR p_billing NOT IN ('PIX','CREDIT_CARD','BOLETO')
 OR NOT EXISTS(SELECT FROM portal_onepage.payment_accounts WHERE account_ref=p_account AND environment='sandbox' AND active)
 THEN RETURN 'denied'; END IF;
 ctx=portal_onepage.entry_payment_context(p_order);
 IF ctx IS NULL OR p_amount IS DISTINCT FROM (ctx->>'amount_cents')::integer
 OR p_due IS DISTINCT FROM (ctx->>'due_date')::date
 OR p_reference IS DISTINCT FROM portal_onepage.entry_payment_reference(ctx,p_account,p_customer)
 OR NOT EXISTS(SELECT FROM portal_onepage.payment_customers
  WHERE company_id=(ctx->>'company_id')::uuid AND account_ref=p_account AND customer_id=p_customer)
 OR EXISTS(SELECT FROM portal_onepage.entry_payment_revocations WHERE order_id=p_order)
 THEN RETURN 'denied'; END IF;
 INSERT INTO portal_onepage.entry_payment_bindings(order_id,account_ref,customer_id,payment_id,
  external_reference,amount_cents,due_date,line_key,billing_type,context)
 VALUES(p_order,p_account,p_customer,p_payment,p_reference,p_amount,p_due,'setup-1',p_billing,ctx)
 ON CONFLICT DO NOTHING;
 IF FOUND THEN RETURN 'recorded'; END IF;
 SELECT * INTO existing FROM portal_onepage.entry_payment_bindings WHERE order_id=p_order;
 RETURN CASE WHEN FOUND AND existing.account_ref=p_account AND existing.customer_id=p_customer
  AND existing.payment_id=p_payment AND existing.external_reference=p_reference AND existing.amount_cents=p_amount
  AND existing.due_date=p_due AND existing.billing_type=p_billing AND existing.context=ctx
  THEN 'replay' ELSE 'denied' END;
END $$;
ALTER FUNCTION portal_onepage.register_entry_payment(uuid,text,text,text,text,integer,date,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.register_entry_payment(uuid,text,text,text,text,integer,date,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.register_entry_payment(uuid,text,text,text,text,integer,date,text) TO portal_onepage_psp_registrar;

CREATE FUNCTION portal_onepage.revoke_entry_payment(p_order uuid,p_reason text) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
BEGIN
 IF session_user<>'portal_onepage_psp_registrar' OR current_setting('transaction_isolation')<>'read committed'
 OR p_reason IS NULL OR p_reason !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
 OR NOT EXISTS(SELECT FROM portal_onepage.entry_payment_bindings WHERE order_id=p_order) THEN RETURN 'denied'; END IF;
 INSERT INTO portal_onepage.entry_payment_revocations(order_id,reason_ref) VALUES(p_order,p_reason) ON CONFLICT DO NOTHING;
 RETURN CASE WHEN FOUND THEN 'recorded' ELSE 'replay' END;
END $$;
ALTER FUNCTION portal_onepage.revoke_entry_payment(uuid,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.revoke_entry_payment(uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.revoke_entry_payment(uuid,text) TO portal_onepage_psp_registrar;

CREATE FUNCTION portal_onepage.read_entry_payment(p_session text,p_order uuid,p_account text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE native jsonb; ctx jsonb; b portal_onepage.entry_payment_bindings%ROWTYPE;
BEGIN
 IF session_user<>'portal_onepage_runtime' OR current_setting('transaction_isolation')<>'read committed' THEN RETURN NULL; END IF;
 native=portal_onepage.read_pending_order(p_session,p_order);
 IF native IS NULL OR native->>'id' IS DISTINCT FROM p_order::text THEN RETURN NULL; END IF;
 ctx=portal_onepage.entry_payment_context(p_order);
 IF ctx IS NULL OR native->>'company_reference' IS DISTINCT FROM ctx->>'tenant_ref'
 OR native->>'publication_ref' IS DISTINCT FROM ctx->>'publication_ref'
 OR NOT EXISTS(SELECT FROM portal_onepage.payment_accounts WHERE account_ref=p_account AND environment='sandbox' AND active)
 OR EXISTS(SELECT FROM portal_onepage.entry_payment_revocations WHERE order_id=p_order) THEN RETURN NULL; END IF;
 SELECT * INTO b FROM portal_onepage.entry_payment_bindings
 WHERE order_id=p_order AND account_ref=p_account AND context=ctx AND line_key='setup-1';
 IF NOT FOUND OR NOT EXISTS(SELECT FROM portal_onepage.payment_customers
  WHERE company_id=(ctx->>'company_id')::uuid AND account_ref=b.account_ref AND customer_id=b.customer_id) THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('tenant_ref',ctx->>'tenant_ref','order_ref',p_order,
  'contract_ref',ctx->>'contract_ref','contract_revision',ctx->'contract_revision','product_code','onepage',
  'payment_id',b.payment_id,'customer_id',b.customer_id,'external_reference',b.external_reference,
  'amount_cents',b.amount_cents,'billing_type',b.billing_type);
END $$;
ALTER FUNCTION portal_onepage.read_entry_payment(text,uuid,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.read_entry_payment(text,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.read_entry_payment(text,uuid,text) TO portal_onepage_runtime;
COMMIT;
