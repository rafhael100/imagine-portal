#!/usr/bin/env bash
# PROPOSED local/CI command, never a workflow. DO NOT RUN in socket-blocked executor.
set -euo pipefail
: "${ONEPAGE_ALLOW_LOCAL_START:?Set to SYNTHETIC_ONLY in a permitted isolated executor}"
[[ "$ONEPAGE_ALLOW_LOCAL_START" == SYNTHETIC_ONLY ]]
: "${PG_BIN:?Absolute PostgreSQL18 bin directory required}"
[[ "$PG_BIN" = /* ]]
php_cmd=${PHP_BIN:-php}
lab_source=$(cd -- "$(dirname -- "$0")" && pwd)
export LC_ALL=C
# Fail offline before even creating the startup directory or initializing a cluster.
"$php_cmd" -r 'require $argv[1]; App\Labs\OnepageBuyer\Guard::offline();' "$lab_source/Guard.php"
# Never reuse an existing cluster, including a failed run.
run_root=$(mktemp -d "${TMPDIR:-/tmp}/onepage-run.XXXXXXXX")
cluster_parent="$run_root/onepage-pg-lab"
mkdir -m 700 "$cluster_parent" "$cluster_parent/socket"
cluster="$cluster_parent/data"
cleanup() { "$PG_BIN/pg_ctl" -D "$cluster" -m fast -w stop >/dev/null 2>&1 || true; }
trap cleanup EXIT
"$PG_BIN/initdb" -D "$cluster" -U onepage_lab_bootstrap --auth-local=trust --auth-host=trust --no-locale > "$run_root/initdb.log"
"$PG_BIN/pg_ctl" -D "$cluster" -l "$run_root/postgres.log" -o "-h 127.0.0.1 -p 55439 -k $cluster_parent/socket" -w start
# Explicit clean Laravel environment; no .env file or persistent config changes.
export APP_ENV=testing APP_DEBUG=false ONEPAGE_BUYER_PG_LAB=DISPOSABLE_ONLY
export APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=postgres DB_USERNAME=onepage_lab_bootstrap DB_PASSWORD=''
unset DB_URL DATABASE_URL
export SESSION_DRIVER=array CACHE_STORE=array LOG_CHANNEL=stderr
# Invalid-host check invokes the real harness, must exit before even opening SQL.
if DB_HOST=localhost "$php_cmd" "$lab_source/harness.php" "$cluster" "$PG_BIN/pg_controldata" > "$run_root/invalid.log" 2>&1; then
  echo 'Invalid configuration unexpectedly accepted' >&2; exit 1
fi
grep -q 'DISPOSABLE_CONFIG_REQUIRED' "$run_root/invalid.log"
"$php_cmd" "$lab_source/harness.php" "$cluster" "$PG_BIN/pg_controldata" | tee "$run_root/results.log"
printf 'Local evidence retained: %s\n' "$run_root"
