#!/usr/bin/env bash
# F432: separate PostgreSQL18 container restore, synthetic GitHub CI only.
# Never point this smoke test at an existing DB, VPS or production server.
set -Eeuo pipefail
umask 077
[[ "$GITHUB_ACTIONS" == true &&
   "$RUNNER_ENVIRONMENT" == github-hosted &&
   "$PORTAL_F432_DISPOSABLE_ONLY" == 1 &&
   "$PAYMENTS_ENABLED" == false ]] || {
    echo 'F432: GitHub-hosted disposable-only guard failed' >&2
    exit 64
}
[[ -f apps/portal/labs/performance-monthly-f427/schema.sql ]] || exit 64
if docker ps -a --format '{{.Names}}' | grep -q '^portal-f432-'; then
    echo 'F432: do not re-use existing containers' >&2
    exit 65
fi
scratch="$(mktemp -d /tmp/portal-f432-XXXXXXXX)"
cleanup() {
    status=$?
    trap - EXIT
    docker rm -f portal-f432-source portal-f432-target >/dev/null 2>&1 || :
    rm -rf -- "$scratch"
    exit "$status"
}
trap cleanup EXIT

for name in portal-f432-source portal-f432-target; do
    docker run -d --name "$name" --network none --memory 1g --cpus 1 \
      -e POSTGRES_HOST_AUTH_METHOD=trust postgres:18-alpine >/dev/null
    ok=0
    for retry in $(seq 1 60); do
        if docker exec "$name" psql -X -qAt -U postgres -d postgres \
           -c 'SELECT 1' 2>/dev/null | grep -qx 1; then
            ok=1
            break
        fi
        sleep 1
    done
    [[ "$ok" == 1 && -z "$(docker port "$name")" ]] || exit 1
    docker exec "$name" psql -X -v ON_ERROR_STOP=1 -U postgres \
        -d postgres -c 'CREATE DATABASE portal_purchase_rehearsal' >/dev/null
done

docker exec -i portal-f432-source psql -X -v ON_ERROR_STOP=1 \
    -U postgres -d portal_purchase_rehearsal \
    < apps/portal/labs/performance-monthly-f427/schema.sql >/dev/null

month="$(date -u -d "$(date -u +%Y-%m-01) -1 month" +%Y-%m-01)"
[[ "$month" =~ ^20[0-9]{2}-(0[1-9]|1[0-2])-01$ ]] || exit 1
docker exec portal-f432-source psql -X -v ON_ERROR_STOP=1 \
  -U postgres -d portal_purchase_rehearsal -c "
SET SESSION AUTHORIZATION perf_monthly_f427_issuer;
INSERT INTO perf_monthly_f427.monthly_reviews(
 envelope_hash,envelope_mac,tenant_ref,order_ref,report_version,
 report_sha256,candidate_sha256,period_start,amount_cents,issued_at,expires_at)
VALUES (repeat('a',64),repeat('b',64),'org-f432-ci','order-f432-ci',1,
 repeat('1',64),repeat('2',64),'$month',17000,now(),now()+interval '10 minutes');
RESET SESSION AUTHORIZATION;" >/dev/null

consume="
SET SESSION AUTHORIZATION perf_monthly_f427_worker;
SELECT perf_monthly_f427.consume_review(
 repeat('a',64),repeat('b',64),'org-f432-ci','order-f432-ci',
 '$month'::date,1,repeat('1',64),repeat('2',64),17000);
RESET SESSION AUTHORIZATION;"
original="$(docker exec portal-f432-source psql -X -qAt -v ON_ERROR_STOP=1 \
 -U postgres -d portal_purchase_rehearsal -c "$consume")"
[[ "$original" == t ]] || { echo 'F432 source consumption failed' >&2; exit 1; }

docker exec portal-f432-source pg_dump -Fc -U postgres \
 -d portal_purchase_rehearsal > "$scratch/closed-month.dump"
[[ -s "$scratch/closed-month.dump" ]] || exit 1
docker exec -i portal-f432-source pg_restore -l \
 < "$scratch/closed-month.dump" > "$scratch/archive.list"
[[ -s "$scratch/archive.list" ]] || exit 1
(cd "$scratch" && sha256sum closed-month.dump > checksum && sha256sum -c checksum) >/dev/null

: > "$scratch/broken.dump"
if docker exec -i portal-f432-target pg_restore -l \
    < "$scratch/broken.dump" >/dev/null 2>&1; then
    echo 'F432 corrupted backup accepted' >&2
    exit 1
fi

# The backup contains objects and ACLs, not cluster-level roles.
# Recreate them in the quarantined target as NOLOGIN, not usable runtime logins.
docker exec -i portal-f432-target psql -X -v ON_ERROR_STOP=1 \
 -U postgres -d postgres <<'SQL' >/dev/null
CREATE ROLE perf_monthly_f427_issuer NOLOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;
CREATE ROLE perf_monthly_f427_viewer NOLOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;
CREATE ROLE perf_monthly_f427_worker NOLOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;
REVOKE CONNECT ON DATABASE portal_purchase_rehearsal FROM PUBLIC;
SQL

docker exec -i portal-f432-target pg_restore --exit-on-error --no-owner \
 -U postgres -d portal_purchase_rehearsal \
 < "$scratch/closed-month.dump" >/dev/null

query() {
 docker exec portal-f432-target psql -X -qAt -v ON_ERROR_STOP=1 \
  -U postgres -d portal_purchase_rehearsal -c "$1"
}
[[ "$(query "SELECT count(*) FROM perf_monthly_f427.monthly_reviews WHERE state='consumed' AND consumed_at IS NOT NULL")" == 1 ]] || exit 1
[[ "$(query "SELECT count(*) FROM perf_monthly_f427.monthly_reviews WHERE envelope_mac=repeat('b',64) AND candidate_sha256=repeat('2',64) AND amount_cents=17000")" == 1 ]] || exit 1
[[ "$(query "SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='perf_monthly_f427.monthly_reviews'::regclass")" == t ]] || exit 1
[[ "$(query "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'perf_monthly_f427_%' AND rolcanlogin")" == 0 ]] || exit 1
[[ "$(query "SELECT has_database_privilege('perf_monthly_f427_worker','portal_purchase_rehearsal','CONNECT')")" == f ]] || exit 1
[[ "$(query "$consume")" == f ]] || {
    echo 'F432 replay after restore was not rejected' >&2
    exit 1
}
source_after="$(docker exec portal-f432-source psql -X -qAt -v ON_ERROR_STOP=1 \
 -U postgres -d portal_purchase_rehearsal \
 -c "SELECT count(*) FROM perf_monthly_f427.monthly_reviews WHERE state='consumed'")"
[[ "$source_after" == 1 ]] || {
    echo 'F432 source modified by restore' >&2
    exit 1
}
echo 'PASS F432: separate PG18 cluster restore with FORCE RLS, source unchanged, consumed claim remains used.'
echo 'NO-GO: this is not a Leaseweb backup or production rollback.'
