#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE7_CI:-}" == 1 && "${ADMIN_PHASE8_RECOVERY_CI:-}" == 1 ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
[[ "${P7_PROJECT:-}" == "imagine-performance-p7-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "${P8_PROJECT:-}" == "imagine-performance-p8-restore-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "${P8_PRIVATE:-}" == "$RUNNER_TEMP/phase8-recovery-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "${P8_CONTEXT:-}" == "$P6_CI_MATERIAL/recovery-control" ]]
compose_file="$GITHUB_WORKSPACE/apps/portal/deploy/phase6/compose.yaml"
fixture_dir="$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase8-recovery"
live() { docker compose -p "$P7_PROJECT" -f "$compose_file" "$@"; }
restored() {
    P6_PRIVATE_URL=https://127.0.0.1:18948 P6_LOOPBACK_PORT=18948 P6_RECEIVER_IP=10.87.7.3 P6_SERVICE_SUBNET=10.87.7.0/28 \
        docker compose -p "$P8_PROJECT" -f "$compose_file" "$@"
}
state() {
    local target="$1" kind="$2" action="$3" owner_dir compose
    [[ ( "$target" == live || "$target" == restore ) && ( "$kind" == portal || "$kind" == receiver ) ]]
    owner_dir=portal-bootstrap; [[ "$kind" == portal ]] || owner_dir=receiver-bootstrap
    compose=live; [[ "$target" == live ]] || compose=restored
    "$compose" run --rm --no-deps -T --entrypoint php -e GITHUB_ACTIONS=true -e ADMIN_PHASE8_RECOVERY_CI=1 \
        -v "$fixture_dir:/run/recovery-scripts:ro" \
        -v "$P6_CI_MATERIAL/$owner_dir:/run/recovery-owner:ro" \
        -v "$P8_CONTEXT/$target/$kind:/run/recovery-context:ro" \
        "$kind" /run/recovery-scripts/state.php "$kind" "$target" "$action"
}
bundle() {
    docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
        --user "$(id -u):$(id -g)" --entrypoint php \
        -e GITHUB_ACTIONS=true -e ADMIN_PHASE8_RECOVERY_CI=1 \
        -v "$fixture_dir:/run/recovery-scripts:ro" \
        -v "$GITHUB_WORKSPACE/apps/portal/tools/performance-recovery:/run/recovery-tools:ro" \
        -v "$P8_PRIVATE:/run/bundle" \
        "$P6_PORTAL_IMAGE" /run/recovery-scripts/bundle.php "$@"
}
only_restore_databases() {
    [[ "$(restored ps --status running --services | sort | tr '\n' ' ')" == 'performance-db portal-db ' ]]
}
