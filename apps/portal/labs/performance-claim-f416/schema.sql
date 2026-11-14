\set ON_ERROR_STOP on
-- Synthetic-only PostgreSQL 18 DB. Never run in production or identity DB.
DO $guard$
BEGIN
 IF current_database() <> 'portal_purchase_rehearsal' THEN
  RAISE EXCEPTION 'F416 requires disposable portal_purchase_rehearsal DB';
 END IF;
END $guard$;

CREATE SCHEMA perf_claim_f416;
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA perf_claim_f416;

CREATE ROLE perf_claim_f416_issuer LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;
CREATE ROLE perf_claim_f416_app LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;
CREATE ROLE perf_claim_f416_worker LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;

CREATE TABLE perf_claim_f416.scope_keys (
 id integer PRIMARY KEY CHECK (id = 1),
 key_bytes bytea NOT NULL CHECK (octet_length(key_bytes) = 32)
);
INSERT INTO perf_claim_f416.scope_keys(id,key_bytes) VALUES
 (1,perf_claim_f416.gen_random_bytes(32));
REVOKE ALL ON perf_claim_f416.scope_keys FROM PUBLIC;

CREATE TABLE perf_claim_f416.activation_claims (
 envelope_hash text PRIMARY KEY CHECK (envelope_hash ~ '^[a-f0-9]{64}$'),
 envelope_mac text NOT NULL CHECK (envelope_mac ~ '^[a-f0-9]{64}$'),
 tenant_ref text NOT NULL CHECK (tenant_ref ~ '^org-[a-z0-9-]{3,48}$'),
 order_ref text NOT NULL CHECK (order_ref ~ '^order-[a-z0-9-]{3,48}$'),
 policy_revision integer NOT NULL CHECK (policy_revision BETWEEN 1 AND 1000000),
 channel_set_hash text NOT NULL CHECK (channel_set_hash ~ '^[a-f0-9]{64}$'),
 activation_cents integer NOT NULL CHECK (activation_cents IN (49000,98000,147000)),
 state text NOT NULL DEFAULT 'issued' CHECK (state IN ('issued','consumed','revoked')),
 issued_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,
 consumed_at timestamptz,
 UNIQUE (tenant_ref,order_ref,policy_revision),
 CHECK (expires_at > issued_at AND expires_at <= issued_at + interval '15 minutes'),
 CHECK ((state='consumed' AND consumed_at IS NOT NULL)
     OR (state IN ('issued','revoked') AND consumed_at IS NULL))
);
ALTER TABLE perf_claim_f416.activation_claims ENABLE ROW LEVEL SECURITY;
ALTER TABLE perf_claim_f416.activation_claims FORCE ROW LEVEL SECURITY;

-- Unlike a client-provided tenant GUC, this proof needs a MAC held only
-- by the DB owner. A direct app SQL login cannot mint another tenant's MAC.
CREATE FUNCTION perf_claim_f416.tenant_read_allowed(row_tenant text)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog
AS $fn$
 SELECT session_user='perf_claim_f416_app'
   AND current_setting('imagine.f416_tenant',true)=row_tenant
   AND EXISTS (
     SELECT 1 FROM perf_claim_f416.scope_keys k WHERE k.id=1
       AND encode(perf_claim_f416.hmac(
         convert_to('imagine-f416-synthetic-tenant:'||row_tenant,'UTF8'),
         k.key_bytes,'sha256'),'hex')
       = current_setting('imagine.f416_tenant_mac',true)
   );
$fn$;
REVOKE ALL ON FUNCTION perf_claim_f416.tenant_read_allowed(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION perf_claim_f416.tenant_read_allowed(text) TO perf_claim_f416_app;

CREATE POLICY f416_app_read ON perf_claim_f416.activation_claims
 FOR SELECT TO perf_claim_f416_app
 USING (perf_claim_f416.tenant_read_allowed(tenant_ref));
CREATE POLICY f416_issuer_insert ON perf_claim_f416.activation_claims
 FOR INSERT TO perf_claim_f416_issuer
 WITH CHECK (state='issued' AND consumed_at IS NULL AND expires_at>now());
CREATE POLICY f416_worker_read ON perf_claim_f416.activation_claims
 FOR SELECT TO perf_claim_f416_worker USING (true);
CREATE POLICY f416_worker_transition ON perf_claim_f416.activation_claims
 FOR UPDATE TO perf_claim_f416_worker
 USING (state='issued' AND expires_at>now())
 WITH CHECK (state IN ('consumed','revoked'));

REVOKE ALL ON perf_claim_f416.activation_claims FROM PUBLIC;
GRANT USAGE ON SCHEMA perf_claim_f416 TO
 perf_claim_f416_issuer,perf_claim_f416_app,perf_claim_f416_worker;
GRANT INSERT(envelope_hash,envelope_mac,tenant_ref,order_ref,
             policy_revision,channel_set_hash,activation_cents,
             issued_at,expires_at)
 ON perf_claim_f416.activation_claims TO perf_claim_f416_issuer;
GRANT SELECT ON perf_claim_f416.activation_claims TO
 perf_claim_f416_app,perf_claim_f416_worker;
GRANT UPDATE(state,consumed_at) ON perf_claim_f416.activation_claims
 TO perf_claim_f416_worker;
