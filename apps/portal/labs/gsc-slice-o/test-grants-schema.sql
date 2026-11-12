-- Disposable CI database only, never production.
CREATE TABLE portal_gsc_property_grants (
 tenant_ref VARCHAR(64) NOT NULL,
 actor_ref VARCHAR(64) NOT NULL,
 property_fingerprint CHAR(64) NOT NULL
     CHECK (property_fingerprint ~ '^[0-9a-f]{64}$'),
 scope VARCHAR(128) NOT NULL
     CHECK (scope='https://www.googleapis.com/auth/webmasters.readonly'),
 grant_status VARCHAR(16) NOT NULL
     CHECK (grant_status IN ('active','revoked')),
 verified_at BIGINT NOT NULL,
 expires_at BIGINT NOT NULL,
 revoked_at BIGINT NULL,
 CONSTRAINT portal_gsc_grants_time
     CHECK (expires_at>=verified_at AND
           (revoked_at IS NULL OR revoked_at>=verified_at)),
 CONSTRAINT portal_gsc_grants_tenant_actor_property_pk
     PRIMARY KEY (tenant_ref,actor_ref,property_fingerprint)
);
CREATE INDEX portal_gsc_grants_expires_idx ON portal_gsc_property_grants(expires_at);
