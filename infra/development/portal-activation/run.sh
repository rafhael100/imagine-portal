#!/usr/bin/env bash
set -euo pipefail
umask 077
ACTIVATION_MODE="${1:-}"
case "$ACTIVATION_MODE" in activate|probe-login) ;; *) echo 'Use activate ou probe-login.' >&2; exit 64;; esac
test "$EUID" -eq 0
test -t 0 && test -t 1
ACTIVATION_TOOL_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
test "$ACTIVATION_TOOL_DIR" = /srv/imagine/portal-activation/infra/development/portal-activation
cd /srv/imagine/portal-development/infra/development/portal-db
/usr/local/sbin/imagine-portal-db-backup > /root/portal-activation-before.log 2>&1
echo 'Backup anterior concluido.'
if docker compose run --rm --no-deps -it --interactive=true \
    -e PORTAL_OPERATOR_ACTIVATION=PRIVATE_AUTH_ONLY \
    -v "$ACTIVATION_TOOL_DIR:/opt/portal-activation:ro" \
    provision php "/opt/portal-activation/$ACTIVATION_MODE.php"; then
    ACTIVATION_RESULT=0
else
    ACTIVATION_RESULT=$?
fi
/usr/local/sbin/imagine-portal-db-backup > /root/portal-activation-after.log 2>&1
echo 'Backup posterior concluido.'
exit "$ACTIVATION_RESULT"
