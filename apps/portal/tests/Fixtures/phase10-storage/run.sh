#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE7_CI:-}" == 1 && "${ADMIN_PHASE8_RECOVERY_CI:-}" == 1 && "${ADMIN_PHASE10_STORAGE_CI:-}" == 1 ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
[[ "${P7_PROJECT:-}" == "imagine-performance-p7-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "${P8_PROJECT:-}" == "imagine-performance-p8-restore-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "${P8_PRIVATE:-}" == "$RUNNER_TEMP/phase8-recovery-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "${P6_CI_MATERIAL:-}" == "$RUNNER_TEMP/phase7-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "$#" == 1 && ( "$1" == capture || "$1" == restore ) ]]
exec python3 "$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase10-storage/orchestrate.py" "$1"
