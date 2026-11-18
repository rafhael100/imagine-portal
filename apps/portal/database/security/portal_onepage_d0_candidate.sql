-- Additive candidate only; install after native orders + entry payments in a gated disposable DB.
-- No account is enabled here. No network, production migration, status mutation or timestamp of liquidation.
BEGIN;
CREATE ROLE portal_onepage_d0_verifier NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
GRANT USAGE ON SCHEMA portal_onepage TO portal_onepage_d0_verifier;
CREATE TABLE portal_onepage.entry_confirmations (
 order_id uuid PRIMARY KEY REFERENCES portal_onepage.entry_payment_bindings(order_id),
 account_ref text NOT NULL, environment text NOT NULL CHECK(environment='sandbox'),
 payment_id text NOT NULL UNIQUE, context jsonb NOT NULL, binding jsonb NOT NULL,
 evidence_sha256 text NOT NULL CHECK(evidence_sha256 ~ '^[a-f0-9]{64}$'),
 observed_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
REVOKE ALL ON portal_onepage.entry_confirmations FROM PUBLIC,portal_onepage_runtime,portal_onepage_psp_registrar,portal_onepage_d0_verifier;
GRANT SELECT,INSERT ON portal_onepage.entry_confirmations TO portal_onepage_payment_owner;
ALTER TABLE portal_onepage.entry_confirmations ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_onepage.entry_confirmations FORCE ROW LEVEL SECURITY;
CREATE POLICY d0_internal_read ON portal_onepage.entry_confirmations FOR SELECT TO portal_onepage_payment_owner USING(true);
CREATE POLICY d0_verified_append ON portal_onepage.entry_confirmations FOR INSERT TO portal_onepage_payment_owner
 WITH CHECK(session_user='portal_onepage_d0_verifier' AND environment='sandbox');
CREATE TRIGGER d0_immutable BEFORE UPDATE OR DELETE ON portal_onepage.entry_confirmations
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.reject_payment_binding_change();
-- Explicit unconfigured gate, not a GUC or client-selected account. Test bootstrap alone pins a synthetic literal.
CREATE FUNCTION portal_onepage.d0_account_gate() RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT NULL::text $$;
ALTER FUNCTION portal_onepage.d0_account_gate() OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_account_gate() FROM PUBLIC;

-- Stable keys; collisions only serialize unrelated work, never grant authority.
CREATE FUNCTION portal_onepage.d0_lock_account(p_account text) RETURNS void
LANGUAGE sql VOLATILE SET search_path=pg_catalog,pg_temp AS $$ SELECT pg_advisory_xact_lock(95501,hashtext(p_account)) $$;
CREATE FUNCTION portal_onepage.d0_lock_binding(p_order uuid) RETURNS void
LANGUAGE sql VOLATILE SET search_path=pg_catalog,pg_temp AS $$ SELECT pg_advisory_xact_lock(95502,hashtext(p_order::text)) $$;
ALTER FUNCTION portal_onepage.d0_lock_account(text) OWNER TO portal_onepage_payment_owner;
ALTER FUNCTION portal_onepage.d0_lock_binding(uuid) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_lock_account(text),portal_onepage.d0_lock_binding(uuid) FROM PUBLIC;

-- Coordinate the EXISTING revoker even when called outside the new PHP service.
CREATE FUNCTION portal_onepage.d0_revocation_lock() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE account text;
BEGIN
 SELECT account_ref INTO account FROM portal_onepage.entry_payment_bindings WHERE order_id=NEW.order_id;
 IF account IS NOT NULL THEN PERFORM portal_onepage.d0_lock_account(account); END IF;
 PERFORM portal_onepage.d0_lock_binding(NEW.order_id);
 RETURN NEW;
END $$;
ALTER FUNCTION portal_onepage.d0_revocation_lock() OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_revocation_lock() FROM PUBLIC;
CREATE TRIGGER d0_coordinate_revocation BEFORE INSERT ON portal_onepage.entry_payment_revocations
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.d0_revocation_lock();

-- No UPDATE privilege added to payment_owner. The operator's account mutation acquires the same advisory key.
CREATE FUNCTION portal_onepage.d0_account_change_lock() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
BEGIN
 IF TG_OP='UPDATE' AND NEW.account_ref IS DISTINCT FROM OLD.account_ref THEN
  IF NEW.account_ref<OLD.account_ref THEN
   PERFORM portal_onepage.d0_lock_account(NEW.account_ref);PERFORM portal_onepage.d0_lock_account(OLD.account_ref);
  ELSE PERFORM portal_onepage.d0_lock_account(OLD.account_ref);PERFORM portal_onepage.d0_lock_account(NEW.account_ref); END IF;
 ELSE PERFORM portal_onepage.d0_lock_account(OLD.account_ref); END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END $$;
ALTER FUNCTION portal_onepage.d0_account_change_lock() OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_account_change_lock() FROM PUBLIC;
CREATE TRIGGER d0_coordinate_account BEFORE UPDATE OR DELETE ON portal_onepage.payment_accounts
 FOR EACH ROW EXECUTE FUNCTION portal_onepage.d0_account_change_lock();

CREATE FUNCTION portal_onepage.d0_snapshot(p_session text,p_order uuid,p_account text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE native jsonb;ctx jsonb;b portal_onepage.entry_payment_bindings%ROWTYPE;bj jsonb;c portal_onepage.entry_confirmations%ROWTYPE;state text;
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
  state='ready';
 END IF;
 RETURN jsonb_build_object('state',state,'account',p_account,'environment','sandbox','context',ctx,'binding',bj);
END $$;
ALTER FUNCTION portal_onepage.d0_snapshot(text,uuid,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_snapshot(text,uuid,text) FROM PUBLIC;
CREATE FUNCTION portal_onepage.d0_inspect(p_session text,p_order uuid,p_account text) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
BEGIN
 IF session_user<>'portal_onepage_runtime' THEN RETURN NULL; END IF;
 RETURN portal_onepage.d0_snapshot(p_session,p_order,p_account);
END $$;
ALTER FUNCTION portal_onepage.d0_inspect(text,uuid,text) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_inspect(text,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.d0_inspect(text,uuid,text) TO portal_onepage_runtime;
CREATE FUNCTION portal_onepage.d0_admit(p_session text,p_order uuid,p_account text,p_expected jsonb,p_payment text,p_digest text,p_observed timestamptz) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE now_snapshot jsonb;old portal_onepage.entry_confirmations%ROWTYPE;
BEGIN
 IF session_user<>'portal_onepage_d0_verifier' OR p_expected IS NULL OR p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
 OR p_payment IS NULL OR p_observed IS NULL OR NOT isfinite(p_observed) OR p_observed>clock_timestamp() THEN RETURN 'denied'; END IF;
 now_snapshot=portal_onepage.d0_snapshot(p_session,p_order,p_account);
 IF now_snapshot IS NULL OR p_expected->>'state' IS DISTINCT FROM 'ready'
 OR (now_snapshot-'state') IS DISTINCT FROM (p_expected-'state') OR now_snapshot->'binding'->>'payment_id' IS DISTINCT FROM p_payment THEN RETURN 'denied'; END IF;
 SELECT * INTO old FROM portal_onepage.entry_confirmations WHERE order_id=p_order;
 IF FOUND THEN RETURN CASE WHEN old.evidence_sha256=p_digest THEN 'replay' ELSE 'denied' END; END IF;
 INSERT INTO portal_onepage.entry_confirmations(order_id,account_ref,environment,payment_id,context,binding,evidence_sha256,observed_at)
 VALUES(p_order,p_account,'sandbox',p_payment,now_snapshot->'context',now_snapshot->'binding',p_digest,p_observed);
 RETURN 'recorded';
END $$;
ALTER FUNCTION portal_onepage.d0_admit(text,uuid,text,jsonb,text,text,timestamptz) OWNER TO portal_onepage_payment_owner;
REVOKE ALL ON FUNCTION portal_onepage.d0_admit(text,uuid,text,jsonb,text,text,timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_onepage.d0_admit(text,uuid,text,jsonb,text,text,timestamptz) TO portal_onepage_d0_verifier;
COMMIT;
