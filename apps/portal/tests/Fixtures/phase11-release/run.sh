#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE11_RELEASE_CI:-}" == 1 ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
export PYTHONDONTWRITEBYTECODE=1
cd "$GITHUB_WORKSPACE"
python3 apps/portal/tests/Fixtures/phase11-release/test_preflight.py
python3 apps/portal/tests/Fixtures/phase11-release/exercise.py
