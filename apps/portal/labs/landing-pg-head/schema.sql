-- Disposable PG18 fixture, NOT an approved Portal migration or production ledger.
-- No trusted issuer/actor authentication is implemented by this schema.
CREATE SCHEMA portal_landing_candidate;
REVOKE ALL ON SCHEMA portal_landing_candidate FROM PUBLIC;

CREATE TABLE portal_landing_candidate.brief_revisions (
    tenant_ref varchar(128) NOT NULL,
    order_ref varchar(128) NOT NULL,
    site_ref uuid NOT NULL,
    contract_ref varchar(128) NOT NULL,
    contract_revision bigint NOT NULL CHECK (contract_revision > 0),
    source_version bigint NOT NULL CHECK (source_version > 0),
    source_sha256 char(64) NOT NULL CHECK (source_sha256 ~ '^[0-9a-f]{64}$'),
    source_bytes text NOT NULL CHECK (octet_length(source_bytes) BETWEEN 1 AND 150000),
    state varchar(16) NOT NULL CHECK (state IN ('draft','approved','revoked')),
    approval_ref varchar(128) NOT NULL,
    approval_epoch bigint NOT NULL CHECK (approval_epoch > 0),
    approved_actor_ref varchar(128) NOT NULL,
    revoked_at timestamptz,
    PRIMARY KEY (tenant_ref, order_ref, site_ref, source_version),
    CHECK (tenant_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CHECK (order_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CHECK (contract_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CHECK (approval_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CHECK (approved_actor_ref ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$')
);
CREATE TABLE portal_landing_candidate.approval_events (
    event_sequence bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_ref varchar(128) NOT NULL UNIQUE,
    tenant_ref varchar(128) NOT NULL,
    order_ref varchar(128) NOT NULL,
    site_ref uuid NOT NULL,
    source_version bigint NOT NULL,
    approval_epoch bigint NOT NULL CHECK (approval_epoch > 0),
    approval_ref varchar(128) NOT NULL,
    actor_ref varchar(128) NOT NULL,
    event_type varchar(16) NOT NULL CHECK (event_type IN ('approved','revoked','superseded')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (tenant_ref, order_ref, site_ref, source_version)
        REFERENCES portal_landing_candidate.brief_revisions
            (tenant_ref, order_ref, site_ref, source_version) ON DELETE RESTRICT,
    UNIQUE (tenant_ref, order_ref, site_ref, source_version, approval_epoch, event_type)
);
-- Deny by default even to a future role with SELECT grants. No tenant RLS policy
-- or service role is created because first-party authenticated authority is missing.
ALTER TABLE portal_landing_candidate.brief_revisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_landing_candidate.brief_revisions FORCE ROW LEVEL SECURITY;
ALTER TABLE portal_landing_candidate.approval_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_landing_candidate.approval_events FORCE ROW LEVEL SECURITY;
REVOKE ALL ON ALL TABLES IN SCHEMA portal_landing_candidate FROM PUBLIC;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA portal_landing_candidate FROM PUBLIC;
