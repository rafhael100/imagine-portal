#!/usr/bin/env bash
# Local development checks only. No authentication secrets, remediation or alerts to third parties.
set -euo pipefail
umask 077
if [[ $EUID -ne 0 ]]; then
    printf '%s\n' 'Run as root to write the private status file.' >&2
    exit 64
fi
exec 9>/run/lock/imagine-dev-healthcheck.lock
flock -n 9 || exit 0
state=/var/lib/imagine-dev
install -d -m 0700 "$state"
temporary=$(mktemp "$state/.health.XXXXXXXX")
trap 'rm -f "$temporary"' EXIT
overall=0
separator=''
printf '{"checked_at":"%s","checks":[' "$(date -u +%FT%TZ)" > "$temporary"
record() {
    local name=$1 result=$2
    printf '%s{"name":"%s","ok":%s}' "$separator" "$name" "$result" >> "$temporary"
    separator=','
    if [[ $result != true ]]; then overall=1; fi
}
probe() {
    local name=$1 expected=$2 url=$3 response
    shift 3
    if response=$(curl --noproxy '*' --connect-timeout 3 --max-time 8 --silent \
        --output /dev/null --write-out '%{http_code}:%{ssl_verify_result}' "$@" "$url" 2>/dev/null) \
        && [[ $response == "$expected" ]]; then
        record "$name" true
    else
        record "$name" false
    fi
}
probe portal_internal 200:0 http://127.0.0.1:18080/up
probe crm_internal 200:0 http://127.0.0.1:18081/up
# Resolve locally while still verifying the real hostname and certificate.
# These checks test local ingress, not external DNS or Internet reachability.
probe portal_https_auth 401:0 https://portal-dev.imaginecomunica.com.br \
    --resolve portal-dev.imaginecomunica.com.br:443:127.0.0.1 --proto '=https'
probe crm_https_auth 401:0 https://crm-dev.imaginecomunica.com.br \
    --resolve crm-dev.imaginecomunica.com.br:443:127.0.0.1 --proto '=https'
for service in nginx docker certbot.timer; do
    if systemctl is-active --quiet "$service"; then
        record "$service" true
    else
        record "$service" false
    fi
done
healthy=false
if [[ $overall == 0 ]]; then healthy=true; fi
printf '],"healthy":%s}\n' "$healthy" >> "$temporary"
chmod 0600 "$temporary"
mv -f "$temporary" "$state/health.json"
trap - EXIT
if [[ $overall != 0 ]]; then
    logger -t imagine-dev-healthcheck -- 'Local development health check failed; inspect /var/lib/imagine-dev/health.json'
fi
printf 'Imagine development healthy=%s\n' "$healthy"
exit "$overall"
