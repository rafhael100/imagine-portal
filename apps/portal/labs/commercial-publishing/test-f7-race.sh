#!/usr/bin/env bash
# Teste somente no runner com PostgreSQL efemero.
set -euo pipefail
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# Duas conexoes diferentes concorrendo pela mesma reserva/row lock.
( psql -X -Atqc "SELECT commercial_publishing_lab.reserve_dispatch_once('tenant_gamma_01','release_gamma_01',repeat('c',64),'dispatch_gamma_01')" > "$tmp/worker1" ) &
p1=$!
( psql -X -Atqc "SELECT commercial_publishing_lab.reserve_dispatch_once('tenant_gamma_01','release_gamma_01',repeat('c',64),'dispatch_gamma_01')" > "$tmp/worker2" ) &
p2=$!
wait "$p1"
wait "$p2"

created="$(grep -hc '^t$' "$tmp/worker1" "$tmp/worker2" | awk '{s+=$1} END {print s+0}')"
already="$(grep -hc '^f$' "$tmp/worker1" "$tmp/worker2" | awk '{s+=$1} END {print s+0}')"
if [[ "$created" != 1 || "$already" != 1 ]]; then
  echo "F7: concurrent claim returned unexpected grants; created=$created repeat=$already" >&2
  exit 1
fi
recorded="$(psql -X -Atqc "SELECT count(*) FROM commercial_publishing_lab.dispatch_receipts WHERE organization_id='tenant_gamma_01'")"
if [[ "$recorded" != 1 ]]; then
  echo 'F7: concurrent race produced duplicate or missing reservation' >&2
  exit 1
fi
echo 'F7: parallel worker reservation serialized: exactly one new receipt, second idempotent.'
