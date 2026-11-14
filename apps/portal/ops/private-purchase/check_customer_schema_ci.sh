#!/usr/bin/env bash
# CI-only PostgreSQL 18 syntax and customer-policy preflight. Never touches VPS.
set -euo pipefail
if [[ "$GITHUB_ACTIONS" != "true" ]]; then
  echo 'NO-GO: disposable CI only' >&2; exit 2
fi
root="$(cd -- "$(dirname -- "$0")" && pwd)"
name="imagine-preview-sql-preflight-$$"
trap 'docker rm -f "$name" >/dev/null 2>&1 || :' EXIT
docker run -d --rm --name "$name" --network none \
  -e POSTGRES_DB=portal_purchase_rehearsal \
  -e POSTGRES_PASSWORD=ci-synthetic-preflight-only postgres:18-alpine >/dev/null
ready=0
for i in $(seq 1 40); do
  # pg_isready can succeed before the requested POSTGRES_DB has finished initdb.
  if docker exec "$name" psql -X -At -v ON_ERROR_STOP=1 -U postgres -d portal_purchase_rehearsal -c 'SELECT 1' >/dev/null 2>&1; then
    ready=1; break
  fi
  sleep 1
done
if [[ "$ready" != 1 ]]; then echo 'NO-GO: PG18 not ready' >&2; exit 1; fi
# The synthetic main preview prepares this schema, role, verifier table and
# random secret before loading customer-schema.sql. This reproduces only shape.
docker exec -i "$name" psql -X -v ON_ERROR_STOP=1 \
  -U postgres -d portal_purchase_rehearsal >/dev/null <<'SQL'
CREATE SCHEMA purchase_rehearsal;
CREATE ROLE purchase_rehearsal_a LOGIN PASSWORD 'ci-preflight-only'
 NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT CONNECT ON DATABASE portal_purchase_rehearsal TO purchase_rehearsal_a;
GRANT USAGE ON SCHEMA purchase_rehearsal TO purchase_rehearsal_a;
CREATE TABLE purchase_rehearsal.preview_scope_secret (
 id integer PRIMARY KEY CHECK(id=1),
 key bytea NOT NULL CHECK(octet_length(key)=32));
INSERT INTO purchase_rehearsal.preview_scope_secret(id,key)
 VALUES(1,decode(repeat('a1',32),'hex'));
REVOKE ALL ON purchase_rehearsal.preview_scope_secret FROM PUBLIC, purchase_rehearsal_a;
SQL
docker exec -i "$name" psql -X -v ON_ERROR_STOP=1 \
  -U postgres -d portal_purchase_rehearsal < "$root/customer-schema.sql" >/dev/null
docker exec -i "$name" psql -X -v ON_ERROR_STOP=1 \
  -U postgres -d portal_purchase_rehearsal >/dev/null <<'SQL'
INSERT INTO purchase_rehearsal.customers(id,username,password_hash,name,company)
 VALUES ('demo-'||repeat('a',32),'demo-ci-check','test-hash','Test Client','Fictional Company');
INSERT INTO purchase_rehearsal.customer_orders(id,customer_id,request_key,product)
 VALUES ('TEST-'||repeat('a',32),'demo-'||repeat('a',32),
         'TEST-ci-only-12345678','onepage');
SQL
sql() {
  docker exec "$name" psql -X -At -v ON_ERROR_STOP=1 \
    -U purchase_rehearsal_a -d portal_purchase_rehearsal -c "$1"
}
assert_eq() {
  if [[ "$1" != "$2" ]]; then
    echo "NO-GO: $3 (expected $2, got $1)" >&2; exit 1
  fi
}
assert_eq "$(sql 'SELECT count(*) FROM purchase_rehearsal.customers')" 0 'raw identity SELECT'
assert_eq "$(sql 'SELECT count(*) FROM purchase_rehearsal.customer_orders')" 0 'raw order SELECT'
assert_eq "$(sql "SELECT count(*) FROM purchase_rehearsal.preview_lookup_account('demo-ci-check')")" 1 'exact account function'
assert_eq "$(sql 'SELECT purchase_rehearsal.preview_customer_count()')" 1 'account limit function'
if docker exec "$name" psql -X -v ON_ERROR_STOP=1 \
  -U purchase_rehearsal_a -d portal_purchase_rehearsal \
  -c 'SELECT key FROM purchase_rehearsal.preview_scope_secret' >/dev/null 2>&1; then
  echo 'NO-GO: checkout SQL role reads verifier secret' >&2; exit 1
fi
policy_count="$(docker exec "$name" psql -X -At -U postgres -d portal_purchase_rehearsal -c "
  SELECT count(*) FROM pg_policies WHERE schemaname='purchase_rehearsal' AND
  ((tablename='customers' AND policyname='customer_preview_read_scope'
    AND qual LIKE '%preview_order_allowed%')
   OR (tablename='customer_orders' AND policyname='customer_order_scope'
    AND qual LIKE '%preview_order_allowed%'
    AND with_check LIKE '%preview_order_allowed%'))"
)"
assert_eq "$policy_count" 2 'signed RLS policies'
rls_count="$(docker exec "$name" psql -X -At -U postgres -d portal_purchase_rehearsal -c "
  SELECT count(*) FROM pg_class WHERE oid IN
   ('purchase_rehearsal.customers'::regclass,
    'purchase_rehearsal.customer_orders'::regclass)
  AND relrowsecurity AND relforcerowsecurity"
)"
assert_eq "$rls_count" 2 'FORCE RLS both customer/order tables'
echo 'PASS SQL preflight: PostgreSQL18 syntax, signed RLS, raw-read refusal, verifier isolation'
