#!/usr/bin/env bash
# PORTAL-F21 lab ONLY. Run from repository root in a disposable CI container.
# No external connection, data, account or app login. psql is inside container.
set -euo pipefail
: "$F21_PG_CONTAINER"

psql_lab() {
  docker exec -i "$F21_PG_CONTAINER" \
    psql -X -qAt -v ON_ERROR_STOP=1 -U postgres -d postgres
}
psql_role() {
  local role="$1"
  if [[ "$role" != "f21_org_a" && "$role" != "f21_org_b" ]]; then
    printf 'Refuse non-laboratory SQL session role.\n' >&2
    return 1
  fi
  docker exec -i "$F21_PG_CONTAINER" \
    psql -X -qAt -v ON_ERROR_STOP=1 -U "$role" -d postgres
}
assert_contains() {
  local output="$1" expected="$2"
  if ! grep -Fxq -- "$expected" <<< "$output"; then
    printf 'Missing expected synthetic assertion label: %s\n' "$expected" >&2
    exit 1
  fi
  printf '[PASS F21] %s\n' "$expected"
}
expect_denied() {
  local label="$1" role="$2" sql="$3"
  if [[ "$role" != "postgres" && "$role" != "f21_org_a" && "$role" != "f21_org_b" ]]; then
    printf 'Unknown synthetic expected-deny role.\n' >&2
    return 1
  fi
  if [[ "$role" == "postgres" ]]; then
    if printf '%s\n' "$sql" | psql_lab >/dev/null 2>&1; then
      printf '[FAIL F21] Denied action succeeded: %s\n' "$label" >&2
      exit 1
    fi
  elif printf '%s\n' "$sql" | psql_role "$role" >/dev/null 2>&1; then
    printf '[FAIL F21] Denied action succeeded: %s\n' "$label" >&2
    exit 1
  fi
  printf '[PASS F21-DENY] %s\n' "$label"
}

psql_lab < apps/portal/labs/service-relations-f21/schema.sql >/dev/null
printf '[PASS F21] Disposable PG18 schema/fixtures created with RLS on all three tables\n'

audit="$(psql_lab <<'SQL'
SELECT 'pg_major=' || (current_setting('server_version_num')::int / 10000);
SELECT 'rls_tables=' || count(*)
  FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname='f21' AND c.relname IN ('service','request','contract')
   AND c.relrowsecurity AND c.relforcerowsecurity;
SELECT 'security_invoker=' || ('security_invoker=true' = ANY(c.reloptions))::text
  FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname='f21' AND c.relname='service_summary';
SQL
)"
assert_contains "$audit" "pg_major=18"
assert_contains "$audit" "rls_tables=3"
assert_contains "$audit" "security_invoker=true"

a="$(psql_role f21_org_a <<'SQL'
SELECT 'a_identity=' || session_user;
SELECT 'a_services=' || count(*) FROM f21.service;
SELECT 'a_requests=' || count(*) FROM f21.request;
SELECT 'a_contracts=' || count(*) FROM f21.contract;
SELECT 'a_view_rows=' || count(*) FROM f21.service_summary;
SELECT 'a_foreign_service=' || count(*) FROM f21.service WHERE organization_id='org-b';
SELECT 'a_foreign_view=' || count(*) FROM f21.service_summary WHERE organization_id='org-b';
SELECT 'a_zero=' || requests_count || ':' || contracts_count
  FROM f21.service_summary WHERE service_reference='DEMO-SVC-ZERO';
SELECT 'a_onepage=' || requests_count || ':' || contracts_count
  FROM f21.service_summary WHERE service_reference='DEMO-SVC-ONEPAGE';
SELECT 'a_landing=' || requests_count || ':' || contracts_count
  FROM f21.service_summary WHERE service_reference='DEMO-SVC-LANDING';
SELECT 'a_summary_totals=' || sum(requests_count) || ':' || sum(contracts_count)
  FROM f21.service_summary;
SQL
)"
for result in \
  a_identity=f21_org_a a_services=4 a_requests=3 a_contracts=3 \
  a_view_rows=4 a_foreign_service=0 a_foreign_view=0 \
  a_zero=0:0 a_onepage=2:1 a_landing=0:2 a_summary_totals=3:3
do assert_contains "$a" "$result"; done

b="$(psql_role f21_org_b <<'SQL'
SELECT 'b_identity=' || session_user;
SELECT 'b_services=' || count(*) FROM f21.service;
SELECT 'b_requests=' || count(*) FROM f21.request;
SELECT 'b_contracts=' || count(*) FROM f21.contract;
SELECT 'b_view_rows=' || count(*) FROM f21.service_summary;
SELECT 'b_foreign_service=' || count(*) FROM f21.service WHERE organization_id='org-a';
SELECT 'b_summary=' || string_agg(service_reference || ':' || requests_count || ':' || contracts_count, ',')
  FROM f21.service_summary;
SQL
)"
for result in \
  b_identity=f21_org_b b_services=1 b_requests=1 b_contracts=1 \
  b_view_rows=1 b_foreign_service=0 b_summary=DEMO-SVC-PRIVATE-B:1:1
do assert_contains "$b" "$result"; done

# This GUC is controlled by a mock caller; it MUST NOT affect RLS.
forged="$(psql_role f21_org_a <<'SQL'
SET app.tenant_id = 'org-b';
SELECT 'spoofed_guc_still_a=' || count(*)
  FROM f21.service WHERE organization_id='org-a';
SELECT 'spoofed_guc_sees_b=' || count(*)
  FROM f21.service WHERE organization_id='org-b';
SELECT 'spoofed_guc_view_sees_b=' || count(*)
  FROM f21.service_summary WHERE organization_id='org-b';
SQL
)"
assert_contains "$forged" "spoofed_guc_still_a=4"
assert_contains "$forged" "spoofed_guc_sees_b=0"
assert_contains "$forged" "spoofed_guc_view_sees_b=0"

expect_denied "read-only A cannot INSERT" f21_org_a \
  "INSERT INTO f21.service VALUES ('org-a','DEMO-SVC-INJECTED','Never');"
expect_denied "read-only B cannot DELETE" f21_org_b \
  "DELETE FROM f21.service WHERE organization_id='org-a';"
expect_denied "read-only A cannot UPDATE foreign row" f21_org_a \
  "UPDATE f21.service SET label='Bad' WHERE organization_id='org-b';"
expect_denied "A cannot SET ROLE B" f21_org_a \
  "SET ROLE f21_org_b;"
expect_denied "A cannot impersonate B session user" f21_org_a \
  "SET SESSION AUTHORIZATION f21_org_b;"
expect_denied "A cannot ALTER security policy" f21_org_a \
  "ALTER TABLE f21.service DISABLE ROW LEVEL SECURITY;"
expect_denied "cross-tenant composite FK rejected even for fixture owner" postgres \
  "INSERT INTO f21.request VALUES ('org-a','DEMO-REQ-FOREIGN-FK','DEMO-SVC-PRIVATE-B','draft');"

post="$(psql_lab <<'SQL'
SELECT 'total_service_rows=' || count(*) FROM f21.service;
SELECT 'total_request_rows=' || count(*) FROM f21.request;
SELECT 'total_contract_rows=' || count(*) FROM f21.contract;
SQL
)"
assert_contains "$post" "total_service_rows=5"
assert_contains "$post" "total_request_rows=4"
assert_contains "$post" "total_contract_rows=4"

printf '[F21] All synthetic PG18 assertions passed.\n'
printf '[F21] WARNING: local-socket login roles do not establish trusted application identity.\n'
printf '[F21] WARNING: no real Laravel DB source, customer authorizer, grants or production RLS was tested.\n'
