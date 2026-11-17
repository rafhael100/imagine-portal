#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE7_CI:-}" == 1 ]]
[[ "${P7_PROJECT:-}" == "imagine-performance-p7-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "$#" == 2 && ( "$1" == portal || "$1" == receiver ) ]]
base=(docker compose -p "$P7_PROJECT" -f "$GITHUB_WORKSPACE/apps/portal/deploy/phase6/compose.yaml")
args=(run --rm --no-deps -T --entrypoint php -e GITHUB_ACTIONS=true -e ADMIN_PHASE7_CI=1
    -v "$GITHUB_WORKSPACE/apps/portal/tests/Fixtures/phase7:/run/phase7-scripts:ro")
if [[ "$1" == portal ]]; then
    args+=(-e DB_USERNAME=portal_p6_migrator
        -v "$P6_CI_MATERIAL/portal-bootstrap:/run/phase7:ro"
        -v "$P6_CI_MATERIAL/portal-bootstrap/owner-password:/run/secrets/portal_db_password:ro")
else
    args+=(-v "$P6_CI_MATERIAL/receiver-bootstrap:/run/phase7:ro"
        -v "$GITHUB_WORKSPACE/engine/contracts/admin/phase2:/run/phase7-contracts:ro")
fi
"${base[@]}" "${args[@]}" "$1" /run/phase7-scripts/fixture.php "$1" "$2"

