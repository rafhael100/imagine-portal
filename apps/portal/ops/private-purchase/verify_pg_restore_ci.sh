#!/usr/bin/env bash
# Synthetic-only backup/restore drill. No filesystem archive, real identity or VPS.
set -euo pipefail
umask 077
if [[ "${GITHUB_ACTIONS:-}" != "true" || "${P0_PG_RESTORE_CI:-}" != "1" ]]; then
  echo 'NO-GO: PostgreSQL restore drill runs only on disposable GitHub CI' >&2
  exit 2
fi
cd "$(dirname "$0")"
restored='portal_purchase_restore_ci'
cleanup() {
  docker compose exec -T database psql -X -v ON_ERROR_STOP=1 -U postgres -d postgres \
    -c "DROP DATABASE IF EXISTS $restored WITH (FORCE)" >/dev/null 2>&1 || :
}
trap cleanup EXIT
count_sql="SELECT
  (SELECT count(*) FROM purchase_rehearsal.customers)::text || ':' ||
  (SELECT count(*) FROM purchase_rehearsal.customer_orders)::text || ':' ||
  (SELECT count(*) FROM purchase_rehearsal.customer_orders WHERE briefing IS NOT NULL)::text || ':' ||
  (SELECT count(*) FROM purchase_rehearsal.customer_order_events)::text || ':' ||
  (SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents)::text"
source_counts="$(docker compose exec -T database psql -X -A -t -v ON_ERROR_STOP=1 \
  -U postgres -d portal_purchase_rehearsal -c "$count_sql")"
[[ "$source_counts" =~ ^[0-9]+:[0-9]+:[0-9]+:[0-9]+:[0-9]+$ ]] || {
  echo 'NO-GO: invalid source backup count proof' >&2; exit 1;
}
IFS=: read -r customers orders briefings events intents <<< "$source_counts"
(( customers >= 2 && orders >= 3 && briefings >= 1 && events >= 7 && intents >= 1 )) || {
  # Synthetic aggregate counts only: never print IDs, credentials or payloads.
  echo "NO-GO: incomplete synthetic evidence (customer/order/briefing/event counts: $source_counts)" >&2
  exit 1
}
docker compose exec -T database psql -X -v ON_ERROR_STOP=1 -U postgres -d postgres \
  -c "CREATE DATABASE $restored" >/dev/null

# A streaming custom-format archive reaches a NEW database in the same disposable
# PostgreSQL service. No credential, customer export, host file or external object store.
docker compose exec -T database pg_dump -Fc -U postgres -d portal_purchase_rehearsal | \
  docker compose exec -T database pg_restore --exit-on-error -U postgres -d "$restored"

restored_counts="$(docker compose exec -T database psql -X -A -t -v ON_ERROR_STOP=1 \
  -U postgres -d "$restored" -c "$count_sql")"
test "$restored_counts" = "$source_counts" || {
  echo 'NO-GO: restored row counts differ from source' >&2; exit 1;
}
# FORCE RLS and customer-specific signed policies must survive pg_dump/pg_restore.
rls_sql="SELECT (
  SELECT relrowsecurity AND relforcerowsecurity
  FROM pg_class WHERE oid='purchase_rehearsal.customer_orders'::regclass
) AND (
  SELECT relrowsecurity AND relforcerowsecurity
  FROM pg_class WHERE oid='purchase_rehearsal.customers'::regclass
) AND EXISTS (
  SELECT 1 FROM pg_policies WHERE schemaname='purchase_rehearsal'
   AND tablename='customer_orders' AND policyname='customer_order_scope'
) AND (
  SELECT relrowsecurity AND relforcerowsecurity
  FROM pg_class WHERE oid='purchase_rehearsal.customer_order_events'::regclass
) AND EXISTS (
  SELECT 1 FROM pg_policies WHERE schemaname='purchase_rehearsal'
   AND tablename='customer_order_events' AND policyname='customer_order_event_scope'
   AND qual LIKE '%preview_order_allowed%'
) AND (
 SELECT relrowsecurity AND relforcerowsecurity
 FROM pg_class WHERE oid='purchase_rehearsal.sandbox_payment_intents'::regclass
) AND EXISTS (
 SELECT 1 FROM pg_policies WHERE schemaname='purchase_rehearsal'
  AND tablename='sandbox_payment_intents' AND policyname='sandbox_owner_intent_scope'
  AND qual LIKE '%preview_order_allowed%'
)"
rls="$(docker compose exec -T database psql -X -A -t -v ON_ERROR_STOP=1 \
  -U postgres -d "$restored" -c "$rls_sql")"
test "$rls" = "t" || {
  echo 'NO-GO: forced customer RLS or signed policy not restored' >&2; exit 1;
}
# A company-level SQL login must still see zero customers' orders without a
# signed, transaction-local principal, even AFTER restoring the archive.
unscoped="$(docker compose exec -T database psql -X -A -t -v ON_ERROR_STOP=1 \
  -U purchase_rehearsal_a -d "$restored" -c \
  "SELECT (SELECT count(*) FROM purchase_rehearsal.customer_orders)::text || ':' ||
          (SELECT count(*) FROM purchase_rehearsal.customers)::text || ':' ||
          (SELECT count(*) FROM purchase_rehearsal.customer_order_events)::text || ':' ||
          (SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents)::text")"
[[ "$unscoped" == "0:0:0:0" ]] || {
  echo 'NO-GO: restored database allowed unscoped customer, order or event reads' >&2; exit 1;
}
echo 'PASS synthetic PG18 backup streamed into independent database, counts/RLS/role verified; VPS/DR still NO-GO'
