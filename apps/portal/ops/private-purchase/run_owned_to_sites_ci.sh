#!/usr/bin/env bash
# CI-only: separate PG18 database and no live PSP/CRM/Leaseweb.
set -euo pipefail
umask 077
if [[ "$GITHUB_ACTIONS" != true || "$P0_OWNED_TO_SITES_CI" != 1 ]]; then
  echo 'NO-GO: explicit GitHub-only admission test' >&2
  exit 2
fi
test -n "$RUNNER_TEMP" && test -n "$GITHUB_WORKSPACE"
cd "$GITHUB_WORKSPACE"
for entry in \
  '_sites_pg_candidate:3d20d83d544577a70c091ca1725c084ab6824d2a' \
  '_sites_signed_candidate:21732e9558b730e38a6845adb593d29f309ff6a9' \
  '_portal_sites_signer_candidate:bf1b2794de4d6000f120ffa2b1e6dd557f34c35e'; do
  IFS=: read -r folder sha <<< "$entry"
  test "$(git -C "$folder" rev-parse HEAD)" = "$sha"
done
php -l apps/portal/tools/test_owned_customer_to_sites_pg18.php >/dev/null
container="imagine-ownedp0-sites-db"
cleanup() { docker rm -f "$container" >/dev/null 2>&1 || :; }
trap cleanup EXIT
docker run --rm -d --name "$container" \
  -e POSTGRES_DB=sites_f360_lab \
  -e POSTGRES_PASSWORD=f360-fictional-admin-disposable-only \
  -p 127.0.0.1:5432:5432 postgres:18-alpine >/dev/null
ready=0
for attempt in $(seq 1 60); do
  if docker exec "$container" psql -X -At -v ON_ERROR_STOP=1 \
      -U postgres -d sites_f360_lab -c 'SELECT 1' >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
test "$ready" = 1 || { echo 'NO-GO: Site PG18 not ready' >&2; exit 1; }
# Portal's Postgres is inside a separate Docker network; export ONLY synthetic
# order IDs, product, fake-payment and briefing-state from four browser accounts.
(
 cd apps/portal/ops/private-purchase
 docker compose exec -T database psql -X -v ON_ERROR_STOP=1 \
   -U postgres -d portal_purchase_rehearsal -At -F $'\t' -c "
     SELECT o.id,o.product,o.payment,
            CASE WHEN o.briefing IS NOT NULL THEN '1' ELSE '0' END
       FROM purchase_rehearsal.customer_orders o
       JOIN purchase_rehearsal.customers c ON c.id=o.customer_id
      WHERE c.username LIKE 'demo-comercial-%'
      ORDER BY o.product,o.id
   " > "$RUNNER_TEMP/p0-owned-commercial-orders.tsv"
)
test "$(wc -l < "$RUNNER_TEMP/p0-owned-commercial-orders.tsv")" -eq 4
chmod 0600 "$RUNNER_TEMP/p0-owned-commercial-orders.tsv"
export APP_ENV=testing
export SITES_F360_PG_LAB=1 SITES_F361_OUTBOX_LAB=1 SITES_F362_CROSS_STACK_LAB=1
export SITES_F363_NONCE_LAB=1 SITES_F364_BRIDGE_LAB=1
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432
export DB_DATABASE=sites_f360_lab DB_USERNAME=postgres
export DB_PASSWORD=f360-fictional-admin-disposable-only
export SITES_F362_PORTAL_PATH="$GITHUB_WORKSPACE/_portal_sites_signer_candidate"
export SITES_F362_SITES_PATH="$GITHUB_WORKSPACE/_sites_signed_candidate"
php _sites_pg_candidate/tests/f360-pg-durable-admission-outbox.php
php _sites_pg_candidate/tests/f361-pg-outbox-worker.php
php _sites_pg_candidate/tests/f362-pinned-portal-to-pg-outbox.php
php _sites_pg_candidate/tests/f363-pg-durable-nonce.php
php _sites_pg_candidate/tests/f364-transactional-signed-ingress-adapter.php
php apps/portal/tools/test_owned_customer_to_sites_pg18.php
python3 - <<'PY'
import hashlib,json,os
from pathlib import Path
file=Path(os.environ['RUNNER_TEMP'])/'p0-owned-customer-to-sites-evidence.json'
raw=file.read_bytes()
assert 100 < len(raw) < 3000
for marker in (b'TEST-',b'demo-',b'@',b'password',b'Bearer ',b'Cookie:'):
    assert marker not in raw, "Do not export synthetic identifiers/private payload"
report=json.loads(raw)
c=report['claims']
assert report['sha256_integrity_only']==hashlib.sha256(
    json.dumps(c,separators=(',',':'),ensure_ascii=False).encode()
).hexdigest()
assert c['schema']=='imagine.portal-p0.owned-customer-to-sites.pg18.v1'
assert c['customers_from_real_chromium_checkout']==4
assert c['sites_projects_admitted_nonpublishable']==2
assert c['sites_outbox_events_acknowledged']==2
assert c['performance_offers_excluded']==2
assert c['replay_and_duplicate_checks_passed'] is True
assert c['release_gate']=='NO_GO'
for key in ('customers_real','portal_identity_real','legal_contract_verified',
            'psp_payment_verified','external_revocation_real',
            'sites_public_endpoint_enabled','site_publishing_authorized',
            'vps_changed','production_release'):
    assert c[key] is False,key
print('PASS four owned Portal purchases -> two Sites projects/outbox/ACK, NO-GO and redacted evidence.')
PY
rm -f "$RUNNER_TEMP/p0-owned-commercial-orders.tsv"
echo 'PASS owned Portal→Sites PostgreSQL bridge; synthetic only, no live contract/payment' >> "$GITHUB_STEP_SUMMARY"
