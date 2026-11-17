#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE7_CI:-}" == 1 ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
cd "$GITHUB_WORKSPACE"
export P7_PROJECT="imagine-performance-p7-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
export P6_CI_MATERIAL="$RUNNER_TEMP/phase7-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
export P7_EVIDENCE="$RUNNER_TEMP/phase7-evidence" P7_BROWSER_VAULT="$RUNNER_TEMP/phase7-browser-vault.json"
export ADMIN_PHASE6_SMOKE_CI=1
[[ ! -e "$P6_CI_MATERIAL" ]]
mkdir -p "$P7_EVIDENCE"
sudo --preserve-env=GITHUB_ACTIONS,ADMIN_PHASE7_CI,ADMIN_PHASE6_SMOKE_CI,P6_CI_MATERIAL python3 apps/portal/tests/Fixtures/phase7/materials.py
export P6_PORTAL_IMAGE=p7-portal-ci P6_PORTAL_WEB_IMAGE=p7-web-ci P6_RECEIVER_IMAGE=p7-receiver-ci
export P6_PG_IMAGE=postgres:18 P6_NGINX_IMAGE=nginx:1.29-alpine
export P6_PRIVATE_URL=https://127.0.0.1:18947 P6_LOOPBACK_PORT=18947 P6_RECEIVER_IP=10.87.6.3 P6_SERVICE_SUBNET=10.87.6.0/28
export P6_PORTAL_SECRET_DIR="$P6_CI_MATERIAL/portal-secrets" P6_PORTAL_PKI_DIR="$P6_CI_MATERIAL/portal-pki" P6_WEB_PKI_DIR="$P6_CI_MATERIAL/web-pki"
export P6_PORTAL_PG_DIR="$P6_CI_MATERIAL/portal-pg" P6_PERFORMANCE_PG_DIR="$P6_CI_MATERIAL/performance-pg" P6_RECEIVER_PRIVATE_DIR="$P6_CI_MATERIAL/receiver-private" P6_RECEIVER_TLS_DIR="$P6_CI_MATERIAL/receiver-tls"
export P6_ENGINE_SOURCE="$GITHUB_WORKSPACE/engine" P6_ADMIN_ENABLED=true P6_LAB_ENABLED=true P6_S2S_ENABLED=true P6_RECEIVER_ENABLED=1
base=(docker compose -p "$P7_PROJECT" -f apps/portal/deploy/phase6/compose.yaml)
[[ -z "$("${base[@]}" ps -aq)" ]]
cleanup(){
 result=$?
 if [[ $result != 0 ]]; then
  "${base[@]}" ps -a || true
  # Sanitized projections only. Never upload raw container logs or a vault.
  "${base[@]}" logs --no-color --no-log-prefix portal-web 2>/dev/null | python3 -c 'import sys,json; [print(json.dumps(x)) for line in sys.stdin if line.startswith("{") for x in [json.loads(line)] if set(x)=={"schema","request_id","status","duration"}]' || true
 fi
 "${base[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
 certutil -d "sql:$HOME/.pki/nssdb" -D -n "Imagine Phase7 ${GITHUB_RUN_ID}" >/dev/null 2>&1 || true
 sudo rm -rf "$P6_CI_MATERIAL"
 rm -f "$P7_BROWSER_VAULT"
 exit "$result"
}
trap cleanup EXIT
# Exact prepared services, no overlay, host DB ports or test-only runtime identity.
"${base[@]}" config --quiet
docker build -t "$P6_PORTAL_IMAGE" --build-context performance_engine=./engine -f apps/portal/deploy/staging/Dockerfile.php apps/portal >/dev/null
docker build -t "$P6_PORTAL_WEB_IMAGE" -f apps/portal/deploy/staging/Dockerfile.nginx apps/portal >/dev/null
docker build -t "$P6_RECEIVER_IMAGE" --build-arg PHP_IMAGE=php:8.4-fpm-bookworm -f engine/deploy/private-admin/Dockerfile engine >/dev/null
"${base[@]}" up -d --wait portal-db performance-db
for tuple in 'portal-db portal_p6_migrator portal_p6_synthetic portal-bootstrap' 'performance-db performance_p6_migrator performance_p6_synthetic receiver-bootstrap'; do
 read -r service role database directory <<< "$tuple"
 cluster=$("${base[@]}" exec -T "$service" sh -ec 'export PGPASSWORD="$(cat /run/pg/bootstrap_password)"; psql -U "$1" -d "$2" -Atc "SELECT system_identifier FROM pg_control_system()"' sh "$role" "$database")
 [[ "$cluster" =~ ^[0-9]+$ ]]
 printf '%s\n' "$cluster" | sudo tee "$P6_CI_MATERIAL/$directory/cluster" >/dev/null
 sudo chown 33:33 "$P6_CI_MATERIAL/$directory/cluster"
 sudo chmod 400 "$P6_CI_MATERIAL/$directory/cluster"
done
bash apps/portal/tests/Fixtures/phase7/control.sh portal prepare
bash apps/portal/tests/Fixtures/phase7/control.sh receiver prepare
# Re-running preparation must refuse this now populated database before migration.
if bash apps/portal/tests/Fixtures/phase7/control.sh portal prepare > "$RUNNER_TEMP/phase7-reprepare.log" 2>&1; then exit 1; fi
grep -q 'stage=empty-database' "$RUNNER_TEMP/phase7-reprepare.log"
"${base[@]}" up -d portal portal-web receiver performance-web
# Diagnose real runtime credentials/TLS before an HTTP failure can obscure them.
for service in portal receiver; do
 "${base[@]}" exec -T -e GITHUB_ACTIONS=true -e ADMIN_PHASE7_CI=1 "$service" php /dev/stdin "$service" < apps/portal/tests/Fixtures/phase7/runtime.php
 id=$("${base[@]}" ps -q "$service")
 [[ "$(docker inspect --format '{{.Config.User}}' "$id")" == 33:33 ]]
 # The migrator secret mounts are confined to completed one-shots.
 ! docker inspect --format '{{json .Mounts}}' "$id" | grep -q 'bootstrap/owner-password\|/run/phase7'
done
[[ "$("${base[@]}" ps --status running --services | wc -l)" == 6 ]]
for attempt in $(seq 1 45); do
 code=$(curl --silent --cacert "$P6_CI_MATERIAL/ca.pem" --config "$P6_CI_MATERIAL/curl-auth" -o /dev/null -w '%{http_code}' "$P6_PRIVATE_URL/portal-admin/login" || true)
 [[ "$code" == 200 ]] && break
 sleep 1
done
if [[ "$code" != 200 ]]; then printf 'PHASE7_HTTP_LOGIN_DENIED status=%s\n' "$code" >&2; exit 1; fi
[[ "$(curl --silent --cacert "$P6_CI_MATERIAL/ca.pem" -o /dev/null -w '%{http_code}' "$P6_PRIVATE_URL/portal-admin/login")" == 401 ]]
mkdir -p "$HOME/.pki/nssdb"
if [[ ! -f "$HOME/.pki/nssdb/cert9.db" ]]; then certutil -d "sql:$HOME/.pki/nssdb" -N --empty-password; fi
certutil -d "sql:$HOME/.pki/nssdb" -A -t 'C,,' -n "Imagine Phase7 ${GITHUB_RUN_ID}" -i "$P6_CI_MATERIAL/ca.pem"
sudo install -m 600 -o "$(id -u)" -g "$(id -g)" "$P6_CI_MATERIAL/portal-bootstrap/browser-vault.json" "$P7_BROWSER_VAULT"
node apps/portal/tests/Fixtures/phase7/browser.cjs
python3 - <<'PY'
import json,os,pathlib,subprocess
out=pathlib.Path(os.environ['P7_EVIDENCE'])
manifest={'schema':'performance.phase7.sources.v1','portal_sha':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),
          'engine_sha':subprocess.check_output(['git','-C','engine','rev-parse','HEAD'],text=True).strip(),
          'compose':'apps/portal/deploy/phase6/compose.yaml','runtime_overlay':False,'source':'synthetic',
          'vps_modified':False,'deployment_authorized':False}
(out/'sources.json').write_text(json.dumps(manifest,indent=2))
PY
printf 'PHASE7_EXACT_COMPOSE_PASS: native password/MFA, encrypted database sessions, least privilege, two verified TLS databases, private mTLS and revocation; disposable CI only\n'

