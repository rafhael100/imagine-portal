#!/bin/bash
set -euo pipefail
export P8_PROJECT="imagine-performance-p8-restore-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
export P8_PRIVATE="$RUNNER_TEMP/phase8-recovery-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
export P8_CONTEXT="$P6_CI_MATERIAL/recovery-control"
export P8_EVIDENCE="$RUNNER_TEMP/phase8-recovery-evidence"
source "$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase8-recovery/common.sh"
umask 077
[[ ! -e "$P8_PRIVATE" && ! -e "$P8_CONTEXT" ]]
[[ -z "$(restored ps -aq)" && -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$P8_PROJECT")" ]]
mkdir -m 700 "$P8_PRIVATE"
mkdir -p "$P8_EVIDENCE"
cleanup() {
    result=$?
    restored down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$P8_PRIVATE"
    sudo rm -rf "$P8_CONTEXT"
    exit "$result"
}
trap cleanup EXIT
for kind in portal receiver; do
    owner_dir=portal-bootstrap; [[ "$kind" == portal ]] || owner_dir=receiver-bootstrap
    for target in live restore; do
        sudo install -d -m 700 -o 33 -g 33 "$P8_CONTEXT/$target/$kind"
        sudo install -m 400 -o 33 -g 33 "$P6_CI_MATERIAL/$owner_dir/cluster" "$P8_CONTEXT/$target/$kind/source-cluster"
    done
    sudo install -m 400 -o 33 -g 33 "$P6_CI_MATERIAL/$owner_dir/cluster" "$P8_CONTEXT/live/$kind/cluster"
done
sudo sha256sum "$P6_PORTAL_SECRET_DIR/portal_app_key" "$P6_PORTAL_SECRET_DIR/portal_service_identity" \
    "$P6_RECEIVER_PRIVATE_DIR/service-keyring.json" "$P6_PORTAL_PKI_DIR/portal_client.key" > "$P8_PRIVATE/key-fingerprints"
node "$fixture_dir/browser.cjs"
printf 'PHASE8_RECOVERY_PASS: exact-package restart, authenticated paired dumps, isolated restore, CURRENT revocation gate and bounded reconciliation; synthetic CI only\n'
