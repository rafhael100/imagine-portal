-- Run only against a fresh disposable PG18 instance as postgres superuser.
\set ON_ERROR_STOP on
INSERT INTO multigateway_candidate.policy_revisions
(organization_ref,revision,policy_digest,policy_document,operator_reference)
VALUES ('lab-org-a',1,repeat('a',64),'{"source":"synthetic-test-only"}','lab-admin-01'),
       ('lab-org-b',1,repeat('b',64),'{"source":"synthetic-test-only"}','lab-admin-02');

INSERT INTO multigateway_candidate.payment_intents
(organization_ref,intent_key,policy_revision,gateway,method,amount_cents)
VALUES ('lab-org-a','LAB-A-INTENT-01',1,'asaas','pix',10000),
       ('lab-org-b','LAB-B-INTENT-01',1,'c6','boleto',25000);

INSERT INTO multigateway_candidate.gateway_event_inbox
(gateway,event_key,organization_ref,intent_key,body_hmac)
VALUES ('asaas','LAB-A-EVENT-001','lab-org-a','LAB-A-INTENT-01',repeat('c',64));

-- Duplicate messages cannot create another row, irrespective of tenancy.
INSERT INTO multigateway_candidate.gateway_event_inbox
(gateway,event_key,organization_ref,intent_key,body_hmac)
VALUES ('asaas','LAB-A-EVENT-001','lab-org-a','LAB-A-INTENT-01',repeat('c',64))
ON CONFLICT DO NOTHING;

DO $test$
DECLARE n integer;
BEGIN
    SELECT count(*) INTO n FROM multigateway_candidate.gateway_event_inbox;
    IF n <> 1 THEN RAISE EXCEPTION 'Inbox idempotency violated: %', n; END IF;
    SELECT count(*) INTO n FROM pg_class cls
        JOIN pg_namespace ns ON ns.oid=cls.relnamespace
        WHERE ns.nspname='multigateway_candidate'
        AND cls.relkind='r'
        AND (NOT cls.relrowsecurity OR NOT cls.relforcerowsecurity);
    IF n <> 0 THEN RAISE EXCEPTION 'Missing FORCE RLS: %', n; END IF;
    SELECT count(*) INTO n FROM pg_policies
        WHERE schemaname='multigateway_candidate';
    IF n <> 0 THEN RAISE EXCEPTION 'Unexpected permissive policy: %', n; END IF;
END
$test$;

-- Explicit SELECT grant alone is insufficient: the role still sees zero rows
-- because FORCE RLS has no policies. The grant is limited to this disposable DB.
CREATE ROLE multigateway_ci_reader NOLOGIN NOBYPASSRLS;
GRANT USAGE ON SCHEMA multigateway_candidate TO multigateway_ci_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA multigateway_candidate TO multigateway_ci_reader;
SET ROLE multigateway_ci_reader;
DO $assert$
DECLARE n integer;
BEGIN
    SELECT count(*) INTO n FROM multigateway_candidate.policy_revisions;
    IF n <> 0 THEN RAISE EXCEPTION 'Policy review leaked: %', n; END IF;
    SELECT count(*) INTO n FROM multigateway_candidate.payment_intents;
    IF n <> 0 THEN RAISE EXCEPTION 'Intent leaked: %', n; END IF;
    SELECT count(*) INTO n FROM multigateway_candidate.gateway_event_inbox;
    IF n <> 0 THEN RAISE EXCEPTION 'Webhook leaked: %', n; END IF;
END
$assert$;
RESET ROLE;
REVOKE ALL ON ALL TABLES IN SCHEMA multigateway_candidate FROM multigateway_ci_reader;
REVOKE USAGE ON SCHEMA multigateway_candidate FROM multigateway_ci_reader;
DROP ROLE multigateway_ci_reader;

SELECT 'PORTAL-PAY-05 disposable PostgreSQL RLS and deduplication PASS' AS result;
