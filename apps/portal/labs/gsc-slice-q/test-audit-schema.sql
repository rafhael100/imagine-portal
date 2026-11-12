-- Isolated disposable PostgreSQL fixture ONLY, never production.
CREATE TABLE portal_gsc_security_events (
    event_id CHAR(32) PRIMARY KEY CHECK (event_id ~ '^[0-9a-f]{32}$'),
    event_code VARCHAR(48) NOT NULL CHECK (event_code IN (
        'oauth_pkce_prepared','oauth_callback_denied',
        'oauth_state_consumed','oauth_token_rejected',
        'property_grant_revoked','property_access_suspended'
    )),
    tenant_ref VARCHAR(64) NOT NULL,
    actor_ref VARCHAR(64) NOT NULL,
    property_fingerprint CHAR(64) NULL
        CHECK (property_fingerprint ~ '^[0-9a-f]{64}$'),
    event_at BIGINT NOT NULL
);
CREATE INDEX portal_gsc_audit_retention_idx
    ON portal_gsc_security_events (event_at,event_id);
