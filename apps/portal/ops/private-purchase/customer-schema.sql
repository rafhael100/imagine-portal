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
ALTER TABLE purchase_rehearsal.customers ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.customers FORCE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.customer_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE purchase_rehearsal.customer_orders FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
 IF NOT EXISTS(SELECT FROM pg_policies WHERE schemaname='purchase_rehearsal' AND tablename='customers' AND policyname='customer_preview_scope') THEN
 CREATE POLICY customer_preview_scope ON purchase_rehearsal.customers FOR ALL USING(tenant='test-org-a' AND current_user='purchase_rehearsal_a') WITH CHECK(tenant='test-org-a' AND current_user='purchase_rehearsal_a');
 END IF;
 IF NOT EXISTS(SELECT FROM pg_policies WHERE schemaname='purchase_rehearsal' AND tablename='customer_orders' AND policyname='customer_order_scope') THEN
 CREATE POLICY customer_order_scope ON purchase_rehearsal.customer_orders FOR ALL USING(tenant='test-org-a' AND current_user='purchase_rehearsal_a') WITH CHECK(tenant='test-org-a' AND current_user='purchase_rehearsal_a');
 END IF;
END $$;
GRANT SELECT,INSERT ON purchase_rehearsal.customers TO purchase_rehearsal_a;
GRANT SELECT,INSERT,UPDATE ON purchase_rehearsal.customer_orders TO purchase_rehearsal_a;
COMMIT;
