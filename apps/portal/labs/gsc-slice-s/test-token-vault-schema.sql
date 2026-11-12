-- Disposable CI only. No real Google OAuth tokens or customer records.
CREATE TABLE portal_gsc_oauth_token_candidates (
    tenant_ref VARCHAR(64) NOT NULL,
    actor_ref VARCHAR(64) NOT NULL,
    property_fingerprint CHAR(64) NOT NULL
        CHECK (property_fingerprint ~ '^[0-9a-f]{64}$'),
    scope VARCHAR(128) NOT NULL
        CHECK (scope='https://www.googleapis.com/auth/webmasters.readonly'),
    sealed_tokens TEXT NOT NULL CHECK (sealed_tokens LIKE 'v1.%' OR sealed_tokens LIKE 'v2.%'),
    issued_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked_at BIGINT NULL,
    CONSTRAINT portal_gsc_token_candidates_pk
        PRIMARY KEY (tenant_ref,actor_ref,property_fingerprint),
    CONSTRAINT portal_gsc_token_time
        CHECK (expires_at>issued_at AND expires_at<=issued_at+3600
            AND (revoked_at IS NULL OR revoked_at>=issued_at))
);
CREATE INDEX portal_gsc_token_candidates_expiry_idx
    ON portal_gsc_oauth_token_candidates (expires_at);
