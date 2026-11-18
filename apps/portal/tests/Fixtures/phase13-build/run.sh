#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE13_BUILD_CI:-}" == 1 && "${ADMIN_PHASE7_CI:-}" == 1 ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
[[ "${P13_PRIVATE:-}" == "${RUNNER_TEMP:?}/phase13-build-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "$RUNNER_TEMP" == /* && "${GITHUB_WORKSPACE:?}" == /* && ! -L "$P13_PRIVATE" ]]
export PYTHONDONTWRITEBYTECODE=1
umask 077
cd "$GITHUB_WORKSPACE"

cleanup() {
    [[ ! -L "$P13_PRIVATE" ]] || return 2
    [[ -d "$P13_PRIVATE" ]] || return 0
    local filename
    for filename in source-inputs.json portal.metadata.json portal-web.metadata.json receiver.metadata.json; do
        [[ ! -L "$P13_PRIVATE/$filename" ]] || return 2
        if [[ -f "$P13_PRIVATE/$filename" ]]; then
            rm -- "$P13_PRIVATE/$filename"
        elif [[ -e "$P13_PRIVATE/$filename" ]]; then
            return 2
        fi
    done
    rmdir -- "$P13_PRIVATE"
}

case "${1:-}" in
    capture)
        python3 apps/portal/tests/Fixtures/phase13-build/test_build_receipt.py
        mkdir -m 700 -- "$P13_PRIVATE"
        trap 'code=$?; if [[ "$code" != 0 ]]; then cleanup || true; fi; exit "$code"' EXIT
        set -C
        python3 apps/portal/tools/performance-release/build_receipt.py capture \
            --portal-root "$GITHUB_WORKSPACE" --engine-root "$GITHUB_WORKSPACE/engine" \
            > "$P13_PRIVATE/source-inputs.json"
        ;;
    verify)
        trap cleanup EXIT
        mkdir -m 700 -- "$RUNNER_TEMP/phase13-build-evidence"
        set -C
        python3 apps/portal/tools/performance-release/build_receipt.py verify \
            --portal-root "$GITHUB_WORKSPACE" --engine-root "$GITHUB_WORKSPACE/engine" \
            --inputs "$P13_PRIVATE/source-inputs.json" --metadata-dir "$P13_PRIVATE" \
            > "$RUNNER_TEMP/phase13-build-evidence/checks.json"
        ;;
    cleanup)
        cleanup
        ;;
    *)
        exit 2
        ;;
esac
