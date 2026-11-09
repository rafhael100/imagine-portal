-- Portal PERF-F14: disposable portal_lab roles only, after F11 fixture creation.
-- No grants on live Portal, no privileged service authority mutation.
BEGIN;
CREATE ROLE portal_perf_source_reader LOGIN PASSWORD 'ci-only-portal-perf-reader' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE portal_perf_ack_demo LOGIN PASSWORD 'ci-only-portal-perf-ack' NOSUPERUSER NOCREATEDB NOCREATEROLE;
GRANT CONNECT ON DATABASE portal_lab TO portal_perf_source_reader, portal_perf_ack_demo;
GRANT USAGE ON SCHEMA public TO portal_perf_source_reader, portal_perf_ack_demo;
GRANT SELECT ON lab_portal_perf_revocation_outbox TO portal_perf_source_reader;
GRANT SELECT ON lab_portal_perf_authority TO portal_perf_source_reader;
CREATE POLICY portal_perf_f14_reader_only_service_b ON lab_portal_perf_authority
FOR SELECT TO portal_perf_source_reader
USING (org_id='org-b' AND service_id='service-b' AND service_ref='svc-demo-b');
-- ACK can only change delivery metadata in the mock outbox; never grants.
GRANT SELECT (event_id,status,attempts)
    ON lab_portal_perf_revocation_outbox TO portal_perf_ack_demo;
GRANT UPDATE (status,attempts)
    ON lab_portal_perf_revocation_outbox TO portal_perf_ack_demo;
COMMIT;
