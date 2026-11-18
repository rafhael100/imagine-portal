-- Candidate delta on #957. Only install in the independently gated disposable rehearsal.
-- No runtime/operator identity is created; no GRANT, PSP, credential or activation.
BEGIN;
-- The existing account table owner is the configuration operator in this fixture.
-- Do not silently delegate sequence access or change the table owner.
DO $$ BEGIN
 IF (SELECT relowner FROM pg_class WHERE oid='portal_onepage.payment_accounts'::regclass)
 <> (SELECT oid FROM pg_roles WHERE rolname=current_user) THEN
 RAISE EXCEPTION 'D0_EPOCH_INSTALL_REFUSED'; END IF;
END $$;
CREATE SEQUENCE portal_onepage.d0_account_generation_seq AS bigint MINVALUE 1 NO CYCLE;
REVOKE ALL ON SEQUENCE portal_onepage.d0_account_generation_seq FROM PUBLIC;
-- Deliberately NOT OWNED BY the account row/column: deleting/recreating an account never resets it.
ALTER TABLE portal_onepage.payment_accounts
 ADD COLUMN configuration_ref text CHECK(configuration_ref ~ '^cfg_[A-Za-z0-9_-]{8,96}$'),
 ADD COLUMN authorization_generation bigint CHECK(authorization_generation>0);
CREATE FUNCTION portal_onepage.d0_assign_account_generation() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 -- On UPDATE/DELETE the existing trigger already uses this key. Also fence INSERT.
 -- Direct advisory lock needs no new grant to the owner/reader/verifier.
 IF TG_OP='UPDATE' AND NEW.account_ref IS DISTINCT FROM OLD.account_ref THEN
  PERFORM pg_advisory_xact_lock(95501,hashtext(least(NEW.account_ref,OLD.account_ref)));
  PERFORM pg_advisory_xact_lock(95501,hashtext(greatest(NEW.account_ref,OLD.account_ref)));
 ELSE PERFORM pg_advisory_xact_lock(95501,hashtext(NEW.account_ref)); END IF;
 -- Override any supplied generation. Even identical UPDATE invalidates an in-flight observation.
 NEW.authorization_generation=nextval('portal_onepage.d0_account_generation_seq'::regclass);
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION portal_onepage.d0_assign_account_generation() FROM PUBLIC;
CREATE TRIGGER d0_generation_account BEFORE INSERT OR UPDATE ON portal_onepage.payment_accounts
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.d0_assign_account_generation();
-- Existing configuration remains unadmitted (NULL); durable confirmations are not rewritten.
UPDATE portal_onepage.payment_accounts SET authorization_generation=NULL;
ALTER TABLE portal_onepage.payment_accounts ALTER COLUMN authorization_generation SET NOT NULL;
CREATE OR REPLACE FUNCTION portal_onepage.d0_snapshot(p_session text,p_order uuid,p_account text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE native jsonb;ctx jsonb;b portal_onepage.entry_payment_bindings%ROWTYPE;bj jsonb;c portal_onepage.entry_confirmations%ROWTYPE;state text;auth jsonb;
BEGIN
 IF current_setting('transaction_isolation')<>'read committed' OR p_account IS NULL
 OR p_account IS DISTINCT FROM portal_onepage.d0_account_gate() THEN RETURN NULL; END IF;
 -- Order: native principal/session -> publication -> account -> binding. No provider call inside.
 native=portal_onepage.read_pending_order(p_session,p_order);
 IF native IS NULL THEN RETURN NULL; END IF;
 PERFORM portal_onepage.d0_lock_account(p_account);
 PERFORM portal_onepage.d0_lock_binding(p_order);
 ctx=portal_onepage.entry_payment_context(p_order);
 IF ctx IS NULL OR NOT EXISTS(SELECT FROM portal_onepage.payment_accounts WHERE account_ref=p_account AND active AND environment='sandbox')
 OR EXISTS(SELECT FROM portal_onepage.entry_payment_revocations WHERE order_id=p_order) THEN RETURN NULL; END IF;
 SELECT * INTO b FROM portal_onepage.entry_payment_bindings WHERE order_id=p_order AND account_ref=p_account AND context=ctx AND line_key='setup-1';
 IF NOT FOUND OR NOT EXISTS(SELECT FROM portal_onepage.payment_customers WHERE company_id=(ctx->>'company_id')::uuid AND account_ref=p_account AND customer_id=b.customer_id)
 OR b.external_reference IS DISTINCT FROM portal_onepage.entry_payment_reference(ctx,p_account,b.customer_id) THEN RETURN NULL; END IF;
 bj=jsonb_build_object('payment_id',b.payment_id,'customer_id',b.customer_id,'external_reference',b.external_reference,'amount_cents',b.amount_cents,'billing_type',b.billing_type,'due_date',b.due_date);
 SELECT * INTO c FROM portal_onepage.entry_confirmations WHERE order_id=p_order;
 IF FOUND THEN
  IF c.account_ref<>p_account OR c.context<>ctx OR c.binding<>bj OR c.payment_id<>b.payment_id THEN RETURN NULL; END IF;
  state='confirmed';
 ELSE
  -- This candidate has no D30/D60 paid ledger. Positive only before either becomes overdue.
  -- Unknown later eligibility is unavailable, not a new suspension or 1..7-day rule.
  IF ((ctx->>'accepted_at')::timestamptz AT TIME ZONE 'America/Sao_Paulo')::date+30 < (clock_timestamp() AT TIME ZONE 'America/Sao_Paulo')::date THEN RETURN NULL; END IF;
  SELECT jsonb_build_object('generation',authorization_generation::text,'configuration',configuration_ref) INTO auth
  FROM portal_onepage.payment_accounts WHERE account_ref=p_account
  AND authorization_generation IS NOT NULL AND configuration_ref IS NOT NULL;
  IF auth IS NULL THEN RETURN NULL; END IF;
  state='ready';
 END IF;
 RETURN jsonb_build_object('state',state,'account',p_account,'environment','sandbox','context',ctx,'binding',bj)
  || CASE WHEN state='ready' THEN jsonb_build_object('account_authorization',auth) ELSE '{}'::jsonb END;
END $$;
ALTER FUNCTION portal_onepage.d0_snapshot(text,uuid,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_snapshot(text,uuid,text) FROM PUBLIC;
CREATE OR REPLACE FUNCTION portal_onepage.d0_admit(p_session text,p_order uuid,p_account text,p_expected jsonb,p_payment text,p_digest text,p_observed timestamptz) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE now_snapshot jsonb;old portal_onepage.entry_confirmations%ROWTYPE;
BEGIN
 IF session_user<>'portal_onepage_d0_verifier' OR p_expected IS NULL OR p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
 OR p_payment IS NULL OR p_observed IS NULL OR NOT isfinite(p_observed) OR p_observed>clock_timestamp() THEN RETURN 'denied'; END IF;
 now_snapshot=portal_onepage.d0_snapshot(p_session,p_order,p_account);
 IF now_snapshot IS NULL OR p_expected->>'state' IS DISTINCT FROM 'ready'
 OR (CASE WHEN now_snapshot->>'state'='confirmed' THEN now_snapshot-'state'-'account_authorization' ELSE now_snapshot-'state' END)
 IS DISTINCT FROM (CASE WHEN now_snapshot->>'state'='confirmed' THEN p_expected-'state'-'account_authorization' ELSE p_expected-'state' END) OR now_snapshot->'binding'->>'payment_id' IS DISTINCT FROM p_payment THEN RETURN 'denied'; END IF;
 SELECT * INTO old FROM portal_onepage.entry_confirmations WHERE order_id=p_order;
 IF FOUND THEN RETURN CASE WHEN old.evidence_sha256=p_digest THEN 'replay' ELSE 'denied' END; END IF;
 INSERT INTO portal_onepage.entry_confirmations(order_id,account_ref,environment,payment_id,context,binding,evidence_sha256,observed_at)
 VALUES(p_order,p_account,'sandbox',p_payment,now_snapshot->'context',now_snapshot->'binding',p_digest,p_observed);
 RETURN 'recorded';
END $$;
ALTER FUNCTION portal_onepage.d0_admit(text,uuid,text,jsonb,text,text,timestamptz) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_admit(text,uuid,text,jsonb,text,text,timestamptz) FROM PUBLIC;
COMMIT;
