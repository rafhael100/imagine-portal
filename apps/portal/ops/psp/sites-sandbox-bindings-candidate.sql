-- Additive candidate only, for a separately reviewed EMPTY Portal staging schema.
-- No seed/credentials/paid flag. NOT automatically installed or promoted from labs.
CREATE SCHEMA portal_sites_sandbox;
REVOKE ALL ON SCHEMA portal_sites_sandbox FROM PUBLIC;
CREATE TABLE portal_sites_sandbox.principal_grants (
 database_login name NOT NULL, tenant_ref text NOT NULL,
 active boolean NOT NULL DEFAULT false, expires_at timestamptz NOT NULL, revoked_at timestamptz,
 PRIMARY KEY(database_login,tenant_ref)
);
CREATE TABLE portal_sites_sandbox.installation_bindings (
 order_ref text PRIMARY KEY CHECK(order_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 tenant_ref text NOT NULL CHECK(tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
 contract_ref text NOT NULL, contract_revision integer NOT NULL CHECK(contract_revision>0),
 product_code text NOT NULL CHECK(product_code IN ('onepage','landing_page')),
 offer_sha256 text NOT NULL CHECK(offer_sha256 ~ '^[a-f0-9]{64}$'),
 sandbox_account_ref text NOT NULL,
 provider_environment text NOT NULL DEFAULT 'sandbox' CHECK(provider_environment='sandbox'),
 payment_id text NOT NULL UNIQUE CHECK(payment_id ~ '^pay_[A-Za-z0-9]{6,64}$'),
 customer_id text NOT NULL CHECK(customer_id ~ '^cus_[A-Za-z0-9]{6,64}$'),
 external_reference text NOT NULL, amount_cents bigint NOT NULL CHECK(amount_cents BETWEEN 1 AND 999999999),
 billing_type text NOT NULL CHECK(billing_type IN ('PIX','CREDIT_CARD','BOLETO')),
 expires_at timestamptz NOT NULL, UNIQUE(order_ref,tenant_ref)
);
CREATE TABLE portal_sites_sandbox.binding_revocations (
 order_ref text PRIMARY KEY, tenant_ref text NOT NULL,
 at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(order_ref,tenant_ref) REFERENCES portal_sites_sandbox.installation_bindings(order_ref,tenant_ref)
);
CREATE TABLE portal_sites_sandbox.binding_audit (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, actor name NOT NULL, event text NOT NULL,
 order_ref text, tenant_ref text, at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE FUNCTION portal_sites_sandbox.can_read_binding(tenant text, ord text) RETURNS boolean
 LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,portal_sites_sandbox AS $$
 SELECT EXISTS(SELECT 1 FROM portal_sites_sandbox.principal_grants g
 WHERE g.database_login=session_user AND g.tenant_ref=tenant AND g.active AND g.revoked_at IS NULL AND g.expires_at>statement_timestamp())
 AND NOT EXISTS(SELECT 1 FROM portal_sites_sandbox.binding_revocations r WHERE r.order_ref=ord AND r.tenant_ref=tenant)
$$;
REVOKE ALL ON FUNCTION portal_sites_sandbox.can_read_binding(text,text) FROM PUBLIC;
ALTER TABLE portal_sites_sandbox.installation_bindings ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_sites_sandbox.installation_bindings FORCE ROW LEVEL SECURITY;
CREATE POLICY installation_scope ON portal_sites_sandbox.installation_bindings
 USING(provider_environment='sandbox' AND expires_at>statement_timestamp() AND portal_sites_sandbox.can_read_binding(tenant_ref,order_ref));
CREATE FUNCTION portal_sites_sandbox.reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Immutable sandbox binding/audit'; END $$;
CREATE TRIGGER immutable_bindings BEFORE UPDATE OR DELETE ON portal_sites_sandbox.installation_bindings FOR EACH ROW EXECUTE FUNCTION portal_sites_sandbox.reject_change();
CREATE TRIGGER immutable_revocations BEFORE UPDATE OR DELETE ON portal_sites_sandbox.binding_revocations FOR EACH ROW EXECUTE FUNCTION portal_sites_sandbox.reject_change();
CREATE TRIGGER immutable_binding_audit BEFORE UPDATE OR DELETE ON portal_sites_sandbox.binding_audit FOR EACH ROW EXECUTE FUNCTION portal_sites_sandbox.reject_change();
CREATE FUNCTION portal_sites_sandbox.audit_change() RETURNS trigger
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,portal_sites_sandbox AS $$
DECLARE row_data jsonb;
BEGIN
 row_data=coalesce(to_jsonb(NEW),to_jsonb(OLD));
 INSERT INTO portal_sites_sandbox.binding_audit(actor,event,order_ref,tenant_ref)
 VALUES(session_user,TG_TABLE_NAME||':'||TG_OP,row_data->>'order_ref',row_data->>'tenant_ref');
 RETURN coalesce(NEW,OLD);
END $$;
REVOKE ALL ON FUNCTION portal_sites_sandbox.audit_change() FROM PUBLIC;
CREATE TRIGGER audit_binding AFTER INSERT ON portal_sites_sandbox.installation_bindings FOR EACH ROW EXECUTE FUNCTION portal_sites_sandbox.audit_change();
CREATE TRIGGER audit_revocation AFTER INSERT ON portal_sites_sandbox.binding_revocations FOR EACH ROW EXECUTE FUNCTION portal_sites_sandbox.audit_change();
CREATE TRIGGER audit_grant AFTER INSERT OR UPDATE OR DELETE ON portal_sites_sandbox.principal_grants FOR EACH ROW EXECUTE FUNCTION portal_sites_sandbox.audit_change();
-- Runtime: schema USAGE, SELECT installation_bindings and EXECUTE can_read_binding only.
-- Never GRANT ALL TABLES, INSERT bindings or authority/audit access to HTTP runtime.
-- Native order/offer/contract producer and account enrollment require independent approval.
