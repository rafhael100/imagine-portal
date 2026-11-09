#!/usr/bin/env bash
set -euo pipefail
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
(psql -X -Atqc "SELECT commercial_publishing_lab.acquire_delivery_lease('tenant_beta_01','release_beta_01',repeat('b',64))" > "$tmp/first") &
p1=$!
(psql -X -Atqc "SELECT commercial_publishing_lab.acquire_delivery_lease('tenant_beta_01','release_beta_01',repeat('b',64))" > "$tmp/second") &
p2=$!
wait "$p1"
wait "$p2"
new="$(grep -hc '^1$' "$tmp/first" "$tmp/second" | awk '{s+=$1} END{print s+0}')"
busy="$(grep -hc '^0$' "$tmp/first" "$tmp/second" | awk '{s+=$1} END{print s+0}')"
if [[ "$new" != 1 || "$busy" != 1 ]]; then
  echo "unexpected race: acquired=$new, busy=$busy" >&2
  exit 1
fi
count="$(psql -X -Atqc "SELECT count(*) FROM commercial_publishing_lab.delivery_leases WHERE organization_id='tenant_beta_01'")"
[[ "$count" = 1 ]]
echo 'F11: two worker connections serialized; one new lease, one busy, one DB row.'
