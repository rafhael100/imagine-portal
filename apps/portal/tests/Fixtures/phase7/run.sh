#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE7_CI:-}" == 1 ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
cd "$GITHUB_WORKSPACE"
require_local_docker(){
 python3 -B - <<'PY'
import importlib.util,pathlib,sys
path=pathlib.Path('apps/portal/deploy/phase6/readonly-inventory.py')
spec=importlib.util.spec_from_file_location('phase7_inventory_context',path)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
try:
 module._docker_context()
except Exception:
 print('PHASE7_LOCAL_DOCKER_REQUIRED',file=sys.stderr)
 sys.exit(1)
PY
}
# Refuse a remote/inherited endpoint before material creation or any Compose read.
require_local_docker
# Pin the already validated context for child commands; never select a fallback.
export DOCKER_CONTEXT=default
export P7_PROJECT="imagine-performance-p7-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
export P6_CI_MATERIAL="$RUNNER_TEMP/phase7-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
export P7_EVIDENCE="$RUNNER_TEMP/phase7-evidence" P7_BROWSER_VAULT="$RUNNER_TEMP/phase7-browser-vault.json"
export ADMIN_PHASE6_SMOKE_CI=1
[[ ! -e "$P6_CI_MATERIAL" ]]
mkdir -p "$P7_EVIDENCE"
sudo --preserve-env=GITHUB_ACTIONS,ADMIN_PHASE7_CI,ADMIN_PHASE6_SMOKE_CI,ADMIN_PHASE8_NAVIGATION_CI,P6_CI_MATERIAL python3 apps/portal/tests/Fixtures/phase7/materials.py
export P6_PORTAL_IMAGE=p7-portal-ci P6_PORTAL_WEB_IMAGE=p7-web-ci P6_RECEIVER_IMAGE=p7-receiver-ci
export P6_PG_IMAGE=postgres:18 P6_NGINX_IMAGE=nginx:1.29-alpine
export P6_PRIVATE_URL=https://127.0.0.1:18947 P6_LOOPBACK_PORT=18947 P6_RECEIVER_IP=10.87.6.3 P6_SERVICE_SUBNET=10.87.6.0/28
export P6_PORTAL_SECRET_DIR="$P6_CI_MATERIAL/portal-secrets" P6_PORTAL_PKI_DIR="$P6_CI_MATERIAL/portal-pki" P6_WEB_PKI_DIR="$P6_CI_MATERIAL/web-pki"
export P6_PORTAL_PG_DIR="$P6_CI_MATERIAL/portal-pg" P6_PERFORMANCE_PG_DIR="$P6_CI_MATERIAL/performance-pg" P6_RECEIVER_PRIVATE_DIR="$P6_CI_MATERIAL/receiver-private" P6_RECEIVER_TLS_DIR="$P6_CI_MATERIAL/receiver-tls"
export P6_ENGINE_SOURCE="$GITHUB_WORKSPACE/engine" P6_ADMIN_ENABLED=true P6_LAB_ENABLED=true P6_S2S_ENABLED=true P6_RECEIVER_ENABLED=1
base=(docker compose -p "$P7_PROJECT" -f apps/portal/deploy/phase6/compose.yaml)
[[ -z "$("${base[@]}" ps -aq)" ]]
p13Captured=0 p13CanariesPrepared=0 p7ResourcesStarted=0
cleanup(){
 result=$?
 if [[ "$p7ResourcesStarted" == 1 ]]; then
  if require_local_docker; then
   if [[ $result != 0 ]]; then
    "${base[@]}" ps -a || true
    # Sanitized projections only. Never upload raw container logs or a vault.
    "${base[@]}" logs --no-color --no-log-prefix portal-web 2>/dev/null | python3 -c 'import sys,json; [print(json.dumps(x)) for line in sys.stdin if line.startswith("{") for x in [json.loads(line)] if set(x)=={"schema","request_id","status","duration"}]' || true
   fi
   "${base[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
  else
   printf 'PHASE7_DOCKER_CLEANUP_REFUSED: local context no longer verified\n' >&2
   [[ $result != 0 ]] || result=1
  fi
 fi
 if [[ "$p13CanariesPrepared" == 1 ]]; then
  python3 -B apps/portal/tests/Fixtures/phase13-build/context-canaries.py cleanup >/dev/null || true
 fi
 if [[ "$p13Captured" == 1 ]]; then
  bash apps/portal/tests/Fixtures/phase13-build/run.sh cleanup || true
 fi
 certutil -d "sql:$HOME/.pki/nssdb" -D -n "Imagine Phase7 ${GITHUB_RUN_ID}" >/dev/null 2>&1 || true
 sudo rm -rf "$P6_CI_MATERIAL"
 rm -f "$P7_BROWSER_VAULT"
 exit "$result"
}
trap cleanup EXIT
capture_host_inventory(){
 local output="$1"
 if ! python3 -B apps/portal/deploy/phase6/readonly-inventory.py --port "$P6_LOOPBACK_PORT" --subnet "$P6_SERVICE_SUBNET" --receiver-ip "$P6_RECEIVER_IP" > "$output"; then
  python3 -B - "$output" <<'PY'
import json,pathlib,sys
report=json.loads(pathlib.Path(sys.argv[1]).read_text())
stage=report.get('stage','unknown')
assert stage in ['candidate','docker_context','compose_projects','containers','networks','routes','listeners','host_resources','named_volumes','unknown']
print('PHASE8_CI_HOST_INVENTORY_DENIED stage='+stage)
PY
  return 1
 fi
}
if [[ "${ADMIN_PHASE14_INVENTORY_CI:-}" == 1 ]]; then
 capture_host_inventory "$P7_EVIDENCE/host-inventory-before.json"
fi
if [[ "${ADMIN_PHASE12_MATERIALS_CI:-}" == 1 ]]; then
 if ! sudo --preserve-env=GITHUB_ACTIONS,ADMIN_PHASE12_MATERIALS_CI,ADMIN_PHASE7_CI,GITHUB_RUN_ID,GITHUB_RUN_ATTEMPT,ADMIN_PHASE8_NAVIGATION_CI,P6_CI_MATERIAL \
  python3 -B apps/portal/tests/Fixtures/phase12-materials/test_materials.py > "$P7_EVIDENCE/materials-preflight.json"; then
  cat "$P7_EVIDENCE/materials-preflight.json"
  exit 1
 fi
 cat "$P7_EVIDENCE/materials-preflight.json"
fi
# Exact prepared services, no overlay, host DB ports or test-only runtime identity.
"${base[@]}" config --quiet
p13PortalMetadata=() p13WebMetadata=() p13ReceiverMetadata=()
if [[ "${ADMIN_PHASE13_BUILD_CI:-}" == 1 ]]; then
 export P13_PRIVATE="$RUNNER_TEMP/phase13-build-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
 bash apps/portal/tests/Fixtures/phase13-build/run.sh capture
 p13Captured=1
 python3 -B apps/portal/tests/Fixtures/phase13-build/context-canaries.py prepare
 p13CanariesPrepared=1
 p13PortalMetadata=(--metadata-file "$P13_PRIVATE/portal.metadata.json")
 p13WebMetadata=(--metadata-file "$P13_PRIVATE/portal-web.metadata.json")
 p13ReceiverMetadata=(--metadata-file "$P13_PRIVATE/receiver.metadata.json")
fi
docker build -t "$P6_PORTAL_IMAGE" "${p13PortalMetadata[@]}" --build-context performance_engine=./engine -f apps/portal/deploy/staging/Dockerfile.php apps/portal >/dev/null
docker build -t "$P6_PORTAL_WEB_IMAGE" "${p13WebMetadata[@]}" -f apps/portal/deploy/staging/Dockerfile.nginx apps/portal >/dev/null
docker build -t "$P6_RECEIVER_IMAGE" "${p13ReceiverMetadata[@]}" --build-arg PHP_IMAGE=php:8.4-fpm-bookworm -f engine/deploy/private-admin/Dockerfile engine >/dev/null
# Cover partial creation by the first up; cleanup is restricted to this CI project.
p7ResourcesStarted=1
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
if [[ "${ADMIN_PHASE13_BUILD_CI:-}" == 1 ]]; then
 if ! python3 -B apps/portal/tests/Fixtures/phase13-build/context-canaries.py verify > "$P7_EVIDENCE/build-context-checks.json"; then
  cat "$P7_EVIDENCE/build-context-checks.json"
  exit 1
 fi
 cat "$P7_EVIDENCE/build-context-checks.json"
 python3 -B apps/portal/tests/Fixtures/phase13-build/context-canaries.py cleanup
 p13CanariesPrepared=0
 bash apps/portal/tests/Fixtures/phase13-build/run.sh verify
 printf 'PHASE13_LOCAL_BUILD_RECEIPT_PASS: three existing builds and runtime images, no registry release or authenticated provenance\n'
fi
if [[ "${ADMIN_PHASE11_RELEASE_CI:-}" == 1 ]]; then
 bash apps/portal/tests/Fixtures/phase11-release/run.sh
fi
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
if [[ "${ADMIN_PHASE8_NAVIGATION_CI:-}" == 1 || "${ADMIN_PHASE8_RECOVERY_CI:-}" == 1 || "${ADMIN_PHASE14_INVENTORY_CI:-}" == 1 ]]; then
 capture_host_inventory "$P7_EVIDENCE/ci-host-inventory.json"
 python3 - <<'PY'
import json,os,pathlib
snapshot=json.loads((pathlib.Path(os.environ['P7_EVIDENCE'])/'ci-host-inventory.json').read_text())
assert snapshot['status']=='NO_GO_PENDING_REVIEW_AND_APPROVAL' and snapshot['installation_authorized'] is False
assert snapshot['proposal']['port_observed_unused'] is False and snapshot['proposal']['subnet_conflicts']
assert any(item['source']=='configured_bindings' for item in snapshot['proposal']['port_conflicts'])
assert any(item['ip']==os.environ['P6_RECEIVER_IP'] for item in snapshot['proposal']['receiver_ip_conflicts'])
assert len(snapshot['containers'])>=6
print('PHASE8_CI_HOST_INVENTORY_PASS: real disposable Docker projections, no VPS inventory or resource reservation')
PY
fi
if [[ "${ADMIN_PHASE14_INVENTORY_CI:-}" == 1 ]]; then
 if ! python3 -B apps/portal/tests/Fixtures/phase8-readiness/exercise_compare_inventory.py \
  --before "$P7_EVIDENCE/host-inventory-before.json" --after "$P7_EVIDENCE/ci-host-inventory.json" > "$P7_EVIDENCE/inventory-comparison.json"; then
  cat "$P7_EVIDENCE/inventory-comparison.json"
  exit 1
 fi
 cat "$P7_EVIDENCE/inventory-comparison.json"
fi
if [[ "${ADMIN_PHASE8_NAVIGATION_CI:-}" == 1 ]]; then
 bash apps/portal/tests/Fixtures/phase8-navigation/run.sh
fi
if [[ "${ADMIN_PHASE9_MAINTENANCE_CI:-}" == 1 ]]; then
 bash apps/portal/tests/Fixtures/phase9-maintenance/run.sh
fi
if [[ "${ADMIN_PHASE8_RECOVERY_CI:-}" == 1 ]]; then
 bash apps/portal/tests/Fixtures/phase8-recovery/run.sh
fi
python3 - <<'PY'
import hashlib,json,os,pathlib,re,subprocess
out=pathlib.Path(os.environ['P7_EVIDENCE'])
compose='apps/portal/deploy/phase6/compose.yaml'
ids=subprocess.check_output(['docker','compose','-p',os.environ['P7_PROJECT'],'-f',compose,'ps','-q'],text=True).splitlines()
assert len(ids)==6 and all(re.fullmatch('[a-f0-9]{12,64}',value) for value in ids)
projection='{"service":{{json (index .Config.Labels "com.docker.compose.service")}},"image_reference":{{json .Config.Image}},"image_id":{{json .Image}}}'
images=[json.loads(line) for line in subprocess.check_output(['docker','inspect','--type','container','--format',projection,*ids],text=True).splitlines()]
assert {entry['service'] for entry in images}=={'portal','portal-web','portal-db','receiver','performance-web','performance-db'}
assert all(re.fullmatch('sha256:[a-f0-9]{64}',entry['image_id']) for entry in images)
manifest={'schema':'performance.phase7.sources.v1','portal_sha':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),
          'engine_sha':subprocess.check_output(['git','-C','engine','rev-parse','HEAD'],text=True).strip(),
          'compose':compose,'compose_sha256':hashlib.sha256(pathlib.Path(compose).read_bytes()).hexdigest(),
          'tested_images':sorted(images,key=lambda item:item['service']),'registry_release_published':False,
          'runtime_overlay':False,'source':'synthetic',
          'vps_modified':False,'deployment_authorized':False}
(out/'sources.json').write_text(json.dumps(manifest,indent=2))
PY
printf 'PHASE7_EXACT_COMPOSE_PASS: native password/MFA, encrypted database sessions, least privilege, two verified TLS databases, private mTLS and revocation; disposable CI only\n'
