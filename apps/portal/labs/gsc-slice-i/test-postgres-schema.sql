-- Synthetic CI fixture only. NEVER execute against customer or production DB.
-- The operational Laravel migration is reviewed separately in this same PR.
CREATE TABLE portal_gsc_oauth_states (
    state_hash CHAR(64) PRIMARY KEY CHECK (state_hash ~ '^[0-9a-f]{64}$'),
    tenant_ref VARCHAR(64) NOT NULL,
    actor_ref VARCHAR(64) NOT NULL,
    sealed_verifier TEXT NOT NULL CHECK (sealed_verifier LIKE 'v1.%'),
    issued_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    consumed_at BIGINT NULL,
    CONSTRAINT portal_gsc_oauth_states_ttl CHECK (expires_at-issued_at=600),
    CONSTRAINT portal_gsc_oauth_states_consumption CHECK (
        consumed_at IS NULL OR (consumed_at>=issued_at AND consumed_at<=expires_at)
    )
);
CREATE INDEX portal_gsc_oauth_states_expires_idx
    ON portal_gsc_oauth_states (expires_at);
