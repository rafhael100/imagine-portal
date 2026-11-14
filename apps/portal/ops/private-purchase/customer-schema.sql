-- Apply only to the dedicated synthetic database; never an identity database.
BEGIN;
DO $$ BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN RAISE EXCEPTION 'Wrong database'; END IF;
END $$;
CREATE TABLE IF NOT EXISTS purchase_rehearsal.customers (
 id text PRIMARY KEY CHECK(id ~ '^demo-[a-f0-9]{32}$'),
 tenant text NOT NULL DEFAULT 'test-org-a' CHECK(tenant='test-org-a'),
 username text NOT NULL UNIQUE CHECK(username ~ '^demo-[a-z0-9-]{3,40}$'),
 password_hash text NOT NULL, name text NOT NULL CHECK(length(name) BETWEEN 1 AND 100),
 company text NOT NULL CHECK(length(company) BETWEEN 1 AND 100), enabled boolean NOT NULL DEFAULT true
);
CREATE TABLE IF NOT EXISTS purchase_rehearsal.customer_orders (
 id text PRIMARY KEY CHECK(id ~ '^TEST-[a-f0-9]{32}$'),
 tenant text NOT NULL DEFAULT 'test-org-a' CHECK(tenant='test-org-a'),
 customer_id text NOT NULL REFERENCES purchase_rehearsal.customers(id), request_key text NOT NULL CHECK(request_key ~ '^TEST-[a-zA-Z0-9-]{8,64}$'),
 product text NOT NULL CHECK(product IN ('onepage','landing-pages','performance')),
 payment text NOT NULL DEFAULT 'pending' CHECK(payment IN ('pending','declined','approved')),
 revision integer NOT NULL DEFAULT 0 CHECK(revision>=0),briefing jsonb,created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(customer_id,request_key),CHECK(briefing IS NULL OR payment='approved')
);
-- Shared tenant SQL logins must not bypass individual order ownership.
-- Signed claim is verified against a bytea key held only by the database owner.
-- A direct SQL holder may SET arbitrary GUCs, but cannot mint a valid MAC.
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA purchase_rehearsal;
CREATE OR REPLACE FUNCTION purchase_rehearsal.preview_order_allowed(row_customer text)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog
AS $function$
 SELECT session_user='purchase_rehearsal_a'
   AND current_setting('imagine.preview_principal',true) IS NOT NULL
   AND (
     current_setting('imagine.preview_principal',true)='admin'
     OR current_setting('imagine.preview_principal',true)=row_customer
   )
   AND EXISTS (
     SELECT 1 FROM purchase_rehearsal.preview_scope_secret s
     WHERE s.id=1 AND
       encode(purchase_rehearsal.hmac(
         convert_to('imagine-owned-preview-v1:' || current_setting('imagine.preview_principal',true),'UTF8'),
         s.key,'sha256'),'hex'
       )=current_setting('imagine.preview_mac',true)
   );
$function$;
REVOKE ALL ON FUNCTION purchase_rehearsal.preview_order_allowed(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION purchase_rehearsal.preview_order_allowed(text) TO purchase_rehearsal_a;

ALTER TABLE purchase_rehearsal.customers ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.customers FORCE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.customer_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.customer_orders FORCE ROW LEVEL SECURITY;

-- PostgreSQL runtime has no unscoped identity-listing privilege. The
-- pre-login function returns one exact username at most; it is a synthetic
-- laboratory auth mechanism, not an operational login or rate-limit.
CREATE OR REPLACE FUNCTION purchase_rehearsal.preview_lookup_account(login_username text)
RETURNS TABLE(id text, username text, password_hash text, name text, company text, enabled boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog
AS $lookup$
  SELECT c.id,c.username,c.password_hash,c.name,c.company,c.enabled
    FROM purchase_rehearsal.customers c
   WHERE session_user='purchase_rehearsal_a'
     AND length(login_username) BETWEEN 8 AND 45
     AND login_username ~ '^demo-[a-z0-9-]{3,40}$'
     AND c.username=login_username AND c.enabled=true;
$lookup$;
REVOKE ALL ON FUNCTION purchase_rehearsal.preview_lookup_account(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION purchase_rehearsal.preview_lookup_account(text) TO purchase_rehearsal_a;

CREATE OR REPLACE FUNCTION purchase_rehearsal.preview_customer_count()
RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog
AS $count$
 SELECT CASE WHEN session_user='purchase_rehearsal_a'
    THEN (SELECT count(*) FROM purchase_rehearsal.customers) ELSE 0 END;
$count$;
REVOKE ALL ON FUNCTION purchase_rehearsal.preview_customer_count() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION purchase_rehearsal.preview_customer_count() TO purchase_rehearsal_a;

-- Customers use two purpose-specific policies: signed owner reads versus
-- bounded synthetic INSERT, NEVER a blanket FOR ALL tenant policy.
DROP POLICY IF EXISTS customer_preview_scope ON purchase_rehearsal.customers;
DROP POLICY IF EXISTS customer_preview_read_scope ON purchase_rehearsal.customers;
DROP POLICY IF EXISTS customer_preview_insert_scope ON purchase_rehearsal.customers;
CREATE POLICY customer_preview_read_scope ON purchase_rehearsal.customers FOR SELECT
 USING(tenant='test-org-a' AND purchase_rehearsal.preview_order_allowed(id));
CREATE POLICY customer_preview_insert_scope ON purchase_rehearsal.customers FOR INSERT
 WITH CHECK(tenant='test-org-a' AND current_user='purchase_rehearsal_a');

DROP POLICY IF EXISTS customer_order_scope ON purchase_rehearsal.customer_orders;
CREATE POLICY customer_order_scope ON purchase_rehearsal.customer_orders FOR ALL
 USING(tenant='test-org-a' AND purchase_rehearsal.preview_order_allowed(customer_id))
 WITH CHECK(tenant='test-org-a' AND purchase_rehearsal.preview_order_allowed(customer_id));
GRANT SELECT,INSERT ON purchase_rehearsal.customers TO purchase_rehearsal_a;
GRANT SELECT,INSERT,UPDATE ON purchase_rehearsal.customer_orders TO purchase_rehearsal_a;
COMMIT;
