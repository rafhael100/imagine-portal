-- F359: irreversible-for-this-fixture quarantine AFTER a real synthetic PG restore.
-- Never execute against a customer/operational database. This only addresses
-- two hard-coded F24 DEMO identities in the disposable PostgreSQL CI lab.
BEGIN;
SET LOCAL lock_timeout = '3000ms';
SET LOCAL statement_timeout = '10000ms';
LOCK TABLE f24.session_fixture, f24.membership_fixture IN ACCESS EXCLUSIVE MODE;

DO $f359$
DECLARE
    changed_sessions int;
    changed_memberships int;
BEGIN
    IF current_database() <> 'postgres'
       OR session_user <> 'postgres'
       OR current_user <> 'postgres'
       OR (SELECT count(*) FROM f24.session_fixture) <> 2
       OR (SELECT count(*) FROM f24.membership_fixture) <> 2
       OR (SELECT count(*) FROM f24.session_fixture
           WHERE (organization_id,actor_id,session_id) IN (
             ('org-a','DEMO-USER-A','DEMO-SESSION-A'),
             ('org-b','DEMO-USER-B','DEMO-SESSION-B')
           )) <> 2
       OR (SELECT count(*) FROM f24.membership_fixture
           WHERE (organization_id,actor_id) IN (
             ('org-a','DEMO-USER-A'),('org-b','DEMO-USER-B')
           )) <> 2
       OR EXISTS (
           SELECT 1 FROM f24.session_fixture
           WHERE session_revision NOT BETWEEN 1 AND 2
           OR mock_state NOT IN ('active','revoked')
       )
       OR EXISTS (
           SELECT 1 FROM f24.membership_fixture
           WHERE membership_revision NOT BETWEEN 1 AND 2
           OR mock_state NOT IN ('active','revoked','suspended')
       ) THEN
        RAISE EXCEPTION 'F359 refuses non-exact disposable two-tenant fixture';
    END IF;

    -- This disposable fixture has no real token store or factor credentials.
    -- Revoking both rows and advancing revisions simulates an ALL-SESSION
    -- quarantine; no route can authorize HTTP from these lab results.
    UPDATE f24.session_fixture
       SET mock_state='revoked', session_revision=session_revision + 1
     WHERE mock_state='active';
    GET DIAGNOSTICS changed_sessions = ROW_COUNT;

    UPDATE f24.membership_fixture
       SET mock_state='revoked',
           can_view_services=false,
           membership_revision=membership_revision + 1
     WHERE mock_state <> 'revoked' OR can_view_services;
    GET DIAGNOSTICS changed_memberships = ROW_COUNT;

    IF changed_sessions NOT BETWEEN 0 AND 2
       OR changed_memberships NOT BETWEEN 0 AND 2
       OR (SELECT count(*) FROM f24.session_fixture
           WHERE mock_state='revoked' AND session_revision=2) <> 2
       OR (SELECT count(*) FROM f24.membership_fixture
           WHERE mock_state='revoked' AND membership_revision=2
             AND NOT can_view_services) <> 2 THEN
        RAISE EXCEPTION 'F359 quarantine postcondition failed';
    END IF;
END
$f359$;
COMMIT;
