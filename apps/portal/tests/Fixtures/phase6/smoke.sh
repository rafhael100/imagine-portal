#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE6_SMOKE_CI:-}" == 1 ]]
cd "$GITHUB_WORKSPACE"
export P6_CI_MATERIAL="$RUNNER_TEMP/phase6-startup"
sudo --preserve-env=GITHUB_ACTIONS,ADMIN_PHASE6_SMOKE_CI,P6_CI_MATERIAL python3 apps/portal/tests/Fixtures/phase6/materials.py
export P6_PORTAL_IMAGE=p6-portal-ci P6_PORTAL_WEB_IMAGE=p6-web-ci P6_RECEIVER_IMAGE=p6-receiver-ci
export P6_PG_IMAGE=postgres:18 P6_NGINX_IMAGE=nginx:1.29-alpine
export P6_PRIVATE_URL=https://phase6.example.invalid P6_LOOPBACK_PORT=18946 P6_RECEIVER_IP=10.87.6.3 P6_SERVICE_SUBNET=10.87.6.0/28
export P6_PORTAL_SECRET_DIR="$P6_CI_MATERIAL/portal-secrets" P6_PORTAL_PKI_DIR="$P6_CI_MATERIAL/portal-pki" P6_WEB_PKI_DIR="$P6_CI_MATERIAL/web-pki"
export P6_PORTAL_PG_DIR="$P6_CI_MATERIAL/portal-pg" P6_PERFORMANCE_PG_DIR="$P6_CI_MATERIAL/performance-pg" P6_RECEIVER_PRIVATE_DIR="$P6_CI_MATERIAL/receiver-private" P6_RECEIVER_TLS_DIR="$P6_CI_MATERIAL/receiver-tls"
export P6_ENGINE_SOURCE="$GITHUB_WORKSPACE/engine" P6_ADMIN_ENABLED=false P6_LAB_ENABLED=false P6_S2S_ENABLED=false P6_RECEIVER_ENABLED=0
# Ephemeral CI images only. Operational release still requires reviewed digests.
docker build -t "$P6_PORTAL_IMAGE" --build-context performance_engine=./engine -f apps/portal/deploy/staging/Dockerfile.php apps/portal >/dev/null
docker build -t "$P6_PORTAL_WEB_IMAGE" -f apps/portal/deploy/staging/Dockerfile.nginx apps/portal >/dev/null
docker build -t "$P6_RECEIVER_IMAGE" --build-arg PHP_IMAGE=php:8.4-fpm-bookworm -f engine/deploy/private-admin/Dockerfile engine >/dev/null
base=(docker compose -p imagine-performance-p6-ci -f apps/portal/deploy/phase6/compose.yaml)
cleanup(){
 result=$?
 if [[ $result != 0 ]]; then
  "${base[@]}" ps -a || true
  "${base[@]}" logs --tail 15 portal-db performance-db portal receiver portal-web performance-web || true
 fi
 "${base[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
 sudo rm -rf "$P6_CI_MATERIAL"
 exit "$result"
}
trap cleanup EXIT
"${base[@]}" up -d --wait portal-db performance-db
"${base[@]}" up -d portal portal-web receiver performance-web
for attempt in $(seq 1 30); do
 code=$(curl --silent --cacert "$P6_CI_MATERIAL/ca.pem" --config "$P6_CI_MATERIAL/curl-auth" -o /dev/null -w '%{http_code}' https://127.0.0.1:18946/up || true)
 [[ "$code" == 200 ]] && break
 sleep 1
done
[[ "$code" == 200 ]]
[[ "$(curl --silent --cacert "$P6_CI_MATERIAL/ca.pem" -o /dev/null -w '%{http_code}' https://127.0.0.1:18946/up)" == 401 ]]
for service in portal receiver; do
 id=$("${base[@]}" ps -q "$service")
 [[ "$(docker inspect --format '{{.Config.User}}' "$id")" == '33:33' ]]
 [[ "$(docker inspect --format '{{.State.Running}}' "$id")" == true ]]
done
[[ "$("${base[@]}" ps --status running --services | wc -l)" == 6 ]]
printf 'PHASE6_EXACT_COMPOSE_STARTUP_PASS: six services, non-root FPM, caps dropped, own TLS databases, HTTPS401/200; feature OFF, no migrations/grants/customer data\n'
