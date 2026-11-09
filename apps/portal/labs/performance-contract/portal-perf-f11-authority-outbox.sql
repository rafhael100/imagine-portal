-- PORTAL-PERF-F11: disposable PostgreSQL 18 lab only. Not an app migration.
-- Run exclusively in portal_lab after F1-F10 synthetic schema bootstrap.
BEGIN;

CREATE TABLE lab_portal_perf_authority (
    org_id varchar(64) NOT NULL,
    service_id varchar(64) NOT NULL,
    organization_ref text NOT NULL CHECK (organization_ref ~ '^org-demo-[a-z0-9-]{1,80}$'),
    tenant_ref text NOT NULL CHECK (tenant_ref ~ '^perf-demo-[a-z0-9-]{1,80}$'),
    service_ref text NOT NULL UNIQUE CHECK (service_ref ~ '^svc-demo-[a-z0-9-]{1,80}$'),
    min_revision integer NOT NULL CHECK (min_revision > 0),
    active boolean NOT NULL DEFAULT true,
    PRIMARY KEY (org_id, service_id),
    FOREIGN KEY (org_id, service_id) REFERENCES lab_portal_services(org_id, id)
);

CREATE TABLE lab_portal_perf_revocation_outbox (
    event_id text PRIMARY KEY CHECK (event_id ~ '^evt-demo-[a-z0-9-]{1,80}$'),
    service_ref text NOT NULL REFERENCES lab_portal_perf_authority(service_ref),
    organization_ref text NOT NULL CHECK (organization_ref ~ '^org-demo-[a-z0-9-]{1,80}$'),
    tenant_ref text NOT NULL CHECK (tenant_ref ~ '^perf-demo-[a-z0-9-]{1,80}$'),
    source_revision integer NOT NULL CHECK (source_revision > 0),
    event_type text NOT NULL CHECK (event_type = 'service.authorization_changed.synthetic'),
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'acked')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (service_ref, source_revision)
);

REVOKE ALL ON lab_portal_perf_authority FROM PUBLIC;
REVOKE ALL ON lab_portal_perf_revocation_outbox FROM PUBLIC;
GRANT SELECT ON lab_portal_perf_authority TO portal_lab_runtime;
GRANT SELECT ON lab_portal_perf_revocation_outbox TO portal_lab_worker;

ALTER TABLE lab_portal_perf_authority ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_portal_perf_authority FORCE ROW LEVEL SECURITY;
CREATE POLICY lab_portal_perf_authority_runtime_select ON lab_portal_perf_authority
FOR SELECT TO portal_lab_runtime USING (
    org_id = NULLIF(current_setting('portal.lab_tenant', true), '')
    AND EXISTS (
        SELECT 1 FROM lab_portal_memberships m
        JOIN lab_portal_organizations o ON o.id=m.org_id
        JOIN lab_portal_users u ON u.id=m.user_id
        WHERE m.org_id=lab_portal_perf_authority.org_id
          AND m.user_id=NULLIF(current_setting('portal.lab_actor', true), '')
          AND m.status='active'
          AND o.status='active'
          AND u.status='active'
    )
);

-- The worker cannot UPDATE authority or the outbox. ACK/transport are in
-- Performance F1-11's independent mocked DB, not active here.
COMMIT;
