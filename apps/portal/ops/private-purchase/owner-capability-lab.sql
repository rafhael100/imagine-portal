-- P0: standalone capability-gated SQL laboratory, NEVER an application migration.
-- Requires disposable PostgreSQL 18 database 'portal_capability_ci'; fail closed.
\set ON_ERROR_STOP on
BEGIN;
DO $$
BEGIN
  IF current_database() <> 'portal_capability_ci'
     OR current_user <> 'postgres'
     OR current_setting('server_version_num')::integer < 180000 THEN
    RAISE EXCEPTION 'Refusing non-disposable PostgreSQL18 owner lab';
  END IF;
END $$;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE SCHEMA portal_capability_lab;
REVOKE ALL ON SCHEMA portal_capability_lab FROM PUBLIC;

CREATE TABLE portal_capability_lab.signing_key (
  id integer PRIMARY KEY CHECK(id=1),
  value text NOT NULL CHECK(length(value)>=32)
);
INSERT INTO portal_capability_lab.signing_key VALUES
 (1,'ci-only-capability-hmac-key-not-for-production-2026-11');
REVOKE ALL ON portal_capability_lab.signing_key FROM PUBLIC;

CREATE TABLE portal_capability_lab.orders (
  id text PRIMARY KEY CHECK(id ~ '^TEST-[a-f0-9]{32}$'),
  organization_id text NOT NULL CHECK(organization_id IN ('test-org-a','test-org-b')),
  customer_id text NOT NULL CHECK(customer_id ~ '^demo-[a-f0-9]{32}$'),
  request_key text NOT NULL CHECK(request_key ~ '^TEST-[a-z0-9-]{8,64}$'),
  product text NOT NULL CHECK(product IN ('onepage','landing-pages','performance')),
  state text NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','approved')),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE(organization_id,customer_id,request_key)
);
ALTER TABLE portal_capability_lab.orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_capability_lab.orders FORCE ROW LEVEL SECURITY;
-- Even a connection with the shared role MUST NOT have direct table SELECT.
-- Superuser 'postgres' owns this disposable lab and is the only definer.
REVOKE ALL ON portal_capability_lab.orders FROM PUBLIC;
CREATE ROLE portal_capability_ci LOGIN
    PASSWORD 'ci-only-sql-client-disposable-2026'
    NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT CONNECT ON DATABASE portal_capability_ci TO portal_capability_ci;
GRANT USAGE ON SCHEMA portal_capability_lab TO portal_capability_ci;

CREATE FUNCTION portal_capability_lab.assert_capability(
  p_action text,p_organization text,p_customer text,p_order text,
  p_expiry bigint,p_mac text
) RETURNS void LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,pg_temp
AS $$
DECLARE secret text;
DECLARE expected text;
DECLARE payload text;
BEGIN
 IF p_action NOT IN ('create','list','approve') OR
    p_organization NOT IN ('test-org-a','test-org-b') OR
    p_customer !~ '^demo-[a-f0-9]{32}$' OR
    (p_action='list' AND p_order <> '-') OR
    (p_action<>'list' AND p_order !~ '^TEST-[a-f0-9]{32}$') OR
    p_expiry <= extract(epoch from clock_timestamp())::bigint OR
    p_expiry > extract(epoch from clock_timestamp())::bigint + 120 OR
    p_mac !~ '^[0-9a-f]{64}$' THEN
   RAISE EXCEPTION 'Synthetic capability denied' USING ERRCODE='42501';
 END IF;
 SELECT value INTO secret FROM portal_capability_lab.signing_key WHERE id=1;
 IF secret IS NULL THEN
   RAISE EXCEPTION 'Synthetic capability denied' USING ERRCODE='42501';
 END IF;
 payload := p_action || '|' || p_organization || '|' || p_customer
    || '|' || p_order || '|' || p_expiry::text;
 expected := encode(public.hmac(convert_to(payload,'UTF8'),
                                 convert_to(secret,'UTF8'),'sha256'),'hex');
 IF expected IS DISTINCT FROM p_mac THEN
   RAISE EXCEPTION 'Synthetic capability denied' USING ERRCODE='42501';
 END IF;
END;
$$;
REVOKE ALL ON FUNCTION portal_capability_lab.assert_capability(
 text,text,text,text,bigint,text) FROM PUBLIC;

CREATE FUNCTION portal_capability_lab.create_owned_order(
  p_org text,p_customer text,p_id text,p_key text,p_product text,
  p_expiry bigint,p_mac text
) RETURNS text LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,pg_temp
AS $$
DECLARE row_id text;
DECLARE found_product text;
BEGIN
 PERFORM portal_capability_lab.assert_capability(
   'create',p_org,p_customer,p_id,p_expiry,p_mac);
 IF p_key !~ '^TEST-[a-z0-9-]{8,64}$'
    OR p_product NOT IN ('onepage','landing-pages','performance') THEN
   RAISE EXCEPTION 'Invalid synthetic order' USING ERRCODE='22023';
 END IF;
 INSERT INTO portal_capability_lab.orders(
   id,organization_id,customer_id,request_key,product
 ) VALUES(p_id,p_org,p_customer,p_key,p_product)
 ON CONFLICT(organization_id,customer_id,request_key) DO NOTHING;
 SELECT id,product INTO row_id,found_product
 FROM portal_capability_lab.orders
 WHERE organization_id=p_org AND customer_id=p_customer AND request_key=p_key;
 IF row_id IS NULL OR found_product<>p_product THEN
   RAISE EXCEPTION 'Idempotency conflict' USING ERRCODE='23505';
 END IF;
 RETURN row_id;
END;
$$;
CREATE FUNCTION portal_capability_lab.list_owned_orders(
  p_org text,p_customer text,p_expiry bigint,p_mac text
) RETURNS TABLE(order_id text,product text,state text)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,pg_temp
AS $$
BEGIN
 PERFORM portal_capability_lab.assert_capability(
   'list',p_org,p_customer,'-',p_expiry,p_mac);
 RETURN QUERY SELECT o.id,o.product,o.state
 FROM portal_capability_lab.orders o
 WHERE o.organization_id=p_org AND o.customer_id=p_customer
 ORDER BY o.created_at,o.id;
END;
$$;
CREATE FUNCTION portal_capability_lab.approve_owned_order(
  p_org text,p_customer text,p_id text,p_expiry bigint,p_mac text
) RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,pg_temp
AS $$
BEGIN
 PERFORM portal_capability_lab.assert_capability(
   'approve',p_org,p_customer,p_id,p_expiry,p_mac);
 UPDATE portal_capability_lab.orders
 SET state='approved'
 WHERE id=p_id AND organization_id=p_org AND customer_id=p_customer
   AND state='pending';
 RETURN FOUND;
END;
$$;
REVOKE ALL ON FUNCTION portal_capability_lab.create_owned_order(
 text,text,text,text,text,bigint,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION portal_capability_lab.list_owned_orders(
 text,text,bigint,text) FROM PUBLIC;
REVOKE ALL ON FUNCTION portal_capability_lab.approve_owned_order(
 text,text,text,bigint,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_capability_lab.create_owned_order(
 text,text,text,text,text,bigint,text) TO portal_capability_ci;
GRANT EXECUTE ON FUNCTION portal_capability_lab.list_owned_orders(
 text,text,bigint,text) TO portal_capability_ci;
GRANT EXECUTE ON FUNCTION portal_capability_lab.approve_owned_order(
 text,text,text,bigint,text) TO portal_capability_ci;
COMMIT;
