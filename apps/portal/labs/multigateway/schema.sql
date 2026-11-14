-- PORTAL-PAY-05: LABORATORY ONLY (not an operational migration).
-- Disposable PostgreSQL 18, synthetic references, no application grants.
BEGIN;
CREATE SCHEMA IF NOT EXISTS multigateway_candidate;
REVOKE ALL ON SCHEMA multigateway_candidate FROM PUBLIC;

CREATE TABLE multigateway_candidate.policy_revisions (
    organization_ref text NOT NULL CHECK (organization_ref ~ '^[A-Za-z0-9._:-]{3,128}$'),
    revision bigint NOT NULL CHECK (revision > 0 AND revision <= 1000000),
    policy_digest char(64) NOT NULL CHECK (policy_digest ~ '^[0-9a-f]{64}$'),
    policy_document jsonb NOT NULL CHECK (jsonb_typeof(policy_document) = 'object'),
    operator_reference text NOT NULL CHECK (length(operator_reference) BETWEEN 3 AND 128),
    recorded_at timestamptz NOT NULL DEFAULT now(),
    state text NOT NULL DEFAULT 'review_only' CHECK (state = 'review_only'),
    PRIMARY KEY (organization_ref, revision)
);

CREATE TABLE multigateway_candidate.payment_intents (
    organization_ref text NOT NULL,
    intent_key text NOT NULL CHECK (intent_key ~ '^[A-Za-z0-9._:-]{8,128}$'),
    policy_revision bigint NOT NULL,
    gateway text NOT NULL CHECK (gateway IN ('asaas', 'c6')),
    method text NOT NULL CHECK (method IN ('pix', 'boleto', 'credit_card', 'pix_automatico')),
    amount_cents bigint NOT NULL CHECK (amount_cents BETWEEN 1 AND 100000000),
    status text NOT NULL DEFAULT 'planned'
        CHECK (status IN ('planned','submitting','uncertain','pending','settled','failed','absent','cancelled')),
    provider_external_id text,
    recorded_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (organization_ref, intent_key),
    FOREIGN KEY (organization_ref, policy_revision)
        REFERENCES multigateway_candidate.policy_revisions (organization_ref, revision),
    CHECK (provider_external_id IS NULL OR provider_external_id ~ '^[A-Za-z0-9._:-]{3,128}$')
);
CREATE UNIQUE INDEX payment_intents_provider_external_unique
    ON multigateway_candidate.payment_intents (gateway, provider_external_id)
    WHERE provider_external_id IS NOT NULL;

CREATE TABLE multigateway_candidate.gateway_event_inbox (
    gateway text NOT NULL CHECK (gateway IN ('asaas','c6')),
    event_key text NOT NULL CHECK (event_key ~ '^[A-Za-z0-9._:-]{8,128}$'),
    organization_ref text NOT NULL,
    intent_key text,
    body_hmac char(64) NOT NULL CHECK (body_hmac ~ '^[0-9a-f]{64}$'),
    seen_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (gateway, event_key),
    FOREIGN KEY (organization_ref, intent_key)
        REFERENCES multigateway_candidate.payment_intents (organization_ref, intent_key),
    CHECK (intent_key IS NULL OR intent_key ~ '^[A-Za-z0-9._:-]{8,128}$')
);

-- Fail closed, including the schema owner: no permissive policies and no
-- application grants. A subsequent release must explicitly introduce
-- scoped roles, restrictive policies, audited dispatcher and sealed secrets.
ALTER TABLE multigateway_candidate.policy_revisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE multigateway_candidate.policy_revisions FORCE ROW LEVEL SECURITY;
ALTER TABLE multigateway_candidate.payment_intents ENABLE ROW LEVEL SECURITY;
ALTER TABLE multigateway_candidate.payment_intents FORCE ROW LEVEL SECURITY;
ALTER TABLE multigateway_candidate.gateway_event_inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE multigateway_candidate.gateway_event_inbox FORCE ROW LEVEL SECURITY;
REVOKE ALL ON ALL TABLES IN SCHEMA multigateway_candidate FROM PUBLIC;
COMMIT;
