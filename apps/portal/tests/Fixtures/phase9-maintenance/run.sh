#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE9_MAINTENANCE_CI:-}" == 1 ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
[[ -n "${P6_PORTAL_IMAGE:-}" ]]
prefix="imagine-performance-p9-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
bootstrap_volume="${prefix}-bootstrap"
storage_volume="${prefix}-storage"
fixture="$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase9-maintenance"
maintenance="$GITHUB_WORKSPACE/apps/portal/tools/performance-maintenance"
evidence="$RUNNER_TEMP/phase9-maintenance-evidence"
for volume in "$bootstrap_volume" "$storage_volume"; do
    ! docker volume inspect "$volume" >/dev/null 2>&1
done
for suffix in seed verify; do
    ! docker container inspect "${prefix}-${suffix}" >/dev/null 2>&1
done
cleanup() {
    result=$?
    docker rm -f "${prefix}-seed" "${prefix}-verify" >/dev/null 2>&1 || true
    docker volume rm "$bootstrap_volume" "$storage_volume" >/dev/null 2>&1 || true
    exit "$result"
}
trap cleanup EXIT
umask 077
mkdir -p "$evidence"
for volume in "$bootstrap_volume" "$storage_volume"; do
    docker volume create --label imagine.performance.fixture=phase9-maintenance --label "imagine.performance.ci-run=${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" "$volume" >/dev/null
done
run_fixture() {
    local action="$1"
    docker run --rm --name "${prefix}-${action}" --network none --read-only --user 33:33 \
        --cap-drop ALL --security-opt no-new-privileges --entrypoint php \
        -e GITHUB_ACTIONS=true -e ADMIN_PHASE9_MAINTENANCE_CI=1 \
        --mount "type=volume,src=$bootstrap_volume,dst=/var/www/html/bootstrap/cache" \
        --mount "type=volume,src=$storage_volume,dst=/var/www/html/storage" \
        --mount "type=bind,src=$fixture,dst=/run/maintenance-tests,readonly" \
        --mount "type=bind,src=$maintenance,dst=/var/www/html/tools/performance-maintenance,readonly" \
        "$P6_PORTAL_IMAGE" /run/maintenance-tests/verify.php "$action"
}
# FPM/Laravel is never started on these exclusive fixture volumes. A different
# one-shot container then reopens the same volumes and observes the stale cache.
run_fixture seed >/dev/null
run_fixture verify > "$evidence/checks.json"
printf 'PHASE9_MAINTENANCE_PASS: derived caches only, persisted data and sessions preserved, idempotent, symlinks and nonregular candidates refused; disposable CI only\n'
