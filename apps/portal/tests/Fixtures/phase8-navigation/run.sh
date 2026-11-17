#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${ADMIN_PHASE7_CI:-}" == 1 && "${ADMIN_PHASE8_NAVIGATION_CI:-}" == 1 ]]
[[ "${P7_PROJECT:-}" == "imagine-performance-p7-ci-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
[[ "$P6_CI_MATERIAL" == "$RUNNER_TEMP/phase7-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" ]]
cd "$GITHUB_WORKSPACE"
export P8_NAV_BROWSER_VAULT="$RUNNER_TEMP/phase8-navigation-vault.json"
[[ ! -e "$P8_NAV_BROWSER_VAULT" ]]
trap 'rm -f "$P8_NAV_BROWSER_VAULT"' EXIT
# Fresh nominal users avoid sharing the Phase7 administrator's revoked session
# or consuming an already-used TOTP. Only this one-shot receives their vault.
sudo --preserve-env=P6_CI_MATERIAL,P7_BROWSER_VAULT python3 - <<'PY'
import json,os,secrets
from pathlib import Path
original=json.loads(Path(os.environ['P7_BROWSER_VAULT']).read_text())
vault={'httpCredentials':original['httpCredentials'],'users':[
    {'email':f'phase8-navigation-{index}@example.test','password':secrets.token_hex(24),
     'secret':secrets.token_hex(20),'company':user['company']}
    for index,user in enumerate(original['users'])]}
target=Path(os.environ['P6_CI_MATERIAL'])/'portal-bootstrap'/'navigation-vault.json'
with target.open('x') as handle:json.dump(vault,handle)
os.chown(target,33,33)
target.chmod(0o400)
PY
sudo install -m 600 -o "$(id -u)" -g "$(id -g)" "$P6_CI_MATERIAL/portal-bootstrap/navigation-vault.json" "$P8_NAV_BROWSER_VAULT"
bash apps/portal/tests/Fixtures/phase8-navigation/control.sh portal prepare
bash apps/portal/tests/Fixtures/phase8-navigation/control.sh receiver prepare
node apps/portal/tests/Fixtures/phase8-navigation/browser.cjs
printf 'PHASE8_NAVIGATION_PASS: independent read grants, signed requested filters, source state and responsive native flow; disposable CI only\n'
