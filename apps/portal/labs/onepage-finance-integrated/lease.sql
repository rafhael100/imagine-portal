-- Fixture-local lease only. NOT the F392 inbox or operational queue.
BEGIN;
CREATE ROLE onepage_finance_lab_ack LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
GRANT USAGE ON SCHEMA onepage_finance_lab TO onepage_finance_lab_ack;
CREATE TABLE onepage_finance_lab.fixture_lease (
 event_id text PRIMARY KEY,account text NOT NULL,payment_id text NOT NULL,
 state text NOT NULL CHECK(state IN ('pending','claimed','acked')),token uuid,expires timestamptz
);
ALTER TABLE onepage_finance_lab.fixture_lease ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_finance_lab.fixture_lease FORCE ROW LEVEL SECURITY;
REVOKE ALL ON onepage_finance_lab.fixture_lease FROM PUBLIC;
GRANT SELECT ON onepage_finance_lab.fixture_lease TO onepage_finance_lab_ack;
GRANT UPDATE(state,token,expires) ON onepage_finance_lab.fixture_lease TO onepage_finance_lab_ack;
CREATE POLICY local_lease_scope ON onepage_finance_lab.fixture_lease TO onepage_finance_lab_ack
 USING(account='native-entry-sandbox' AND session_user='onepage_finance_lab_ack')
 WITH CHECK(account='native-entry-sandbox' AND session_user='onepage_finance_lab_ack');
COMMIT;
