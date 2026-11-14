-- F1-398 synthetic, disposable PostgreSQL 18 ONLY. Never run on Leaseweb.
-- Two fictional tenants, append-only versioned binding, restricted reader.
\set ON_ERROR_STOP on
BEGIN;
CREATE SCHEMA portal_release AUTHORIZATION postgres;
CREATE EXTENSION pgcrypto WITH SCHEMA portal_release;
CREATE ROLE portal_release_reader LOGIN
  PASSWORD 'CI-ONLY-PORTAL-RELEASE-READ-NOT-REAL'
  NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION;
GRANT CONNECT ON DATABASE portal_release_ci TO portal_release_reader;
GRANT USAGE ON SCHEMA portal_release TO portal_release_reader;

CREATE TABLE portal_release.tenant_hmac_keys (
    tenant_ref text PRIMARY KEY CHECK (tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    secret bytea NOT NULL CHECK (octet_length(secret)=32)
);
INSERT INTO portal_release.tenant_hmac_keys (tenant_ref,secret) VALUES
  ('tenant-ci-a',decode(repeat('ab',32),'hex')),
  ('tenant-ci-b',decode(repeat('cd',32),'hex'));
REVOKE ALL ON portal_release.tenant_hmac_keys FROM PUBLIC,portal_release_reader;

CREATE TABLE portal_release.binding_history (
    tenant_ref text NOT NULL CHECK (tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    order_ref text NOT NULL CHECK (order_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    contract_ref text NOT NULL CHECK (contract_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    contract_revision integer NOT NULL CHECK (contract_revision > 0),
    product_code text NOT NULL CHECK (product_code IN ('onepage','landing_page')),
    site_ref text NOT NULL CHECK (site_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    release_ref text NOT NULL CHECK (release_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    artifact_digest text NOT NULL CHECK (artifact_digest ~ '^[a-f0-9]{64}$'),
    editorial_revision integer NOT NULL CHECK (editorial_revision > 0),
    binding_revision integer NOT NULL CHECK (binding_revision > 0),
    binding_state text NOT NULL CHECK (binding_state IN ('active','revoked')),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (order_ref,binding_revision)
);
CREATE INDEX release_binding_lookup_ci
  ON portal_release.binding_history (tenant_ref,order_ref,binding_revision DESC);

-- Verifier runs as a privileged SQL definer, but tenant keys cannot be
-- selected by the runtime role. GUCs are forgeable; HMAC is not.
CREATE FUNCTION portal_release.reader_tenant_allowed(row_tenant text)
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog
AS $sql$
 SELECT session_user='portal_release_reader'
    AND current_user='portal_release_reader'
    AND current_setting('imagine.release_tenant',true)=row_tenant
    AND EXISTS (
       SELECT 1 FROM portal_release.tenant_hmac_keys k
        WHERE k.tenant_ref=row_tenant
          AND encode(portal_release.hmac(
                convert_to('portal-sites-binding-v1:'||row_tenant,'UTF8'),
                k.secret,'sha256'),'hex') =
                current_setting('imagine.release_mac',true)
    );
$sql$;
REVOKE ALL ON FUNCTION portal_release.reader_tenant_allowed(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_release.reader_tenant_allowed(text)
 TO portal_release_reader;

ALTER TABLE portal_release.binding_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_release.binding_history FORCE ROW LEVEL SECURITY;
CREATE POLICY portal_release_signed_tenant_read
  ON portal_release.binding_history FOR SELECT TO portal_release_reader
  USING (portal_release.reader_tenant_allowed(tenant_ref));
REVOKE ALL ON portal_release.binding_history FROM PUBLIC,portal_release_reader;
GRANT SELECT ON portal_release.binding_history TO portal_release_reader;

INSERT INTO portal_release.binding_history (
  tenant_ref,order_ref,contract_ref,contract_revision,product_code,
  site_ref,release_ref,artifact_digest,editorial_revision,
  binding_revision,binding_state
) VALUES
 ('tenant-ci-a','order-ci-a','contract-ci-a',2,'onepage',
  'site-ci-a','release-ci-a',repeat('a',64),4,1,'active'),
 ('tenant-ci-b','order-ci-b','contract-ci-b',3,'landing_page',
  'site-ci-b','release-ci-b',repeat('b',64),5,1,'active');
COMMIT;
