#!/usr/bin/env bash
# Internal clean-process worker. The public entrypoint owns environment sanitization.
set -euo pipefail
[[ $# == 4 ]] || exit 2
for name in $(compgen -e); do
  case "$name" in
    PATH|HOME|LC_ALL|LD_LIBRARY_PATH|PWD|SHLVL|_) ;;
    *) echo 'ORDER_WORKER_REFUSED: ambient configuration' >&2; exit 2 ;;
  esac
done
PG_BIN=$1
php_binary=$2
lab_source=$3
extension_dir=$4
# No php.ini, scanned ini or auto-prepend. Load only known extensions from selected PHP installation.
php_cmd=("$php_binary" -n -d memory_limit=512M)
for module in pdo pdo_pgsql mbstring dom xml xmlreader xmlwriter simplexml tokenizer ctype fileinfo phar iconv sodium; do
  if [[ -f "$extension_dir/$module.so" ]]; then php_cmd+=(-d "extension=$extension_dir/$module.so"); fi
done
"${php_cmd[@]}" -r 'require $argv[1]; App\Labs\OnepagePendingOrder\ProcessPreflight::assertClean();' "$lab_source/ProcessPreflight.php"
echo 'PASS ORDER_CLEAN_PHP_BEFORE_STARTUP'
# Fail offline before even creating the startup directory or initializing a cluster.
"${php_cmd[@]}" -r 'require $argv[1]; App\Labs\OnepageBuyer\Guard::offline();' "$lab_source/../onepage-buyer/Guard.php"
# Never reuse an existing cluster, including a failed run.
run_root=$HOME/../run
mkdir -m 700 "$run_root"
cluster_parent="$run_root/onepage-pg-lab"
mkdir -m 700 "$cluster_parent" "$cluster_parent/socket"
cluster="$cluster_parent/data"
cleanup() {
  status=$?
  if [[ -f "$cluster/postmaster.pid" ]]; then
    if "$PG_BIN/pg_ctl" -D "$cluster" -m fast -w stop >/dev/null 2>&1; then
      echo 'PASS ORDER_CLUSTER_STOPPED'
    else
      echo 'ORDER_CLUSTER_STOP_FAILED' >&2
      status=1
    fi
  fi
  exit "$status"
}
trap cleanup EXIT
"$PG_BIN/initdb" -D "$cluster" -U onepage_lab_bootstrap --auth-local=trust --auth-host=trust --no-locale > "$run_root/initdb.log"
"$PG_BIN/pg_ctl" -D "$cluster" -l "$run_root/postgres.log" -o "-h 127.0.0.1 -p 55439 -k $cluster_parent/socket" -w start
# Explicit clean Laravel environment; no .env file or persistent config changes.
export APP_ENV=testing APP_DEBUG=false ONEPAGE_BUYER_PG_LAB=DISPOSABLE_ONLY
export ONEPAGE_PENDING_ORDER_LAB=SYNTHETIC_ONLY
export APP_KEY=base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE=
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=postgres DB_USERNAME=onepage_lab_bootstrap DB_PASSWORD=''
unset DB_URL DATABASE_URL
export SESSION_DRIVER=array CACHE_STORE=array LOG_CHANNEL=stderr
# Invalid-host check invokes the real harness, must exit before even opening SQL.
if DB_HOST=localhost "${php_cmd[@]}" "$lab_source/prepare.php" "$cluster" "$PG_BIN/pg_controldata" > "$run_root/invalid.log" 2>&1; then
  echo 'Invalid configuration unexpectedly accepted' >&2; exit 1
fi
grep -q 'DISPOSABLE_CONFIG_REQUIRED' "$run_root/invalid.log"
echo 'PASS ORDER_INVALID_CONFIG_DENIED_BEFORE_SQL'
"${php_cmd[@]}" "$lab_source/prepare.php" "$cluster" "$PG_BIN/pg_controldata" | tee "$run_root/results.log"
export DB_DATABASE=portal_onepage_buyer_ci
"${php_cmd[@]}" "$lab_source/pg-cases.php" "$cluster" "$PG_BIN/pg_controldata" | tee "$run_root/orders.log"
echo 'PASS ORDER_RUNNER_COMPLETE_NO_RELEASE'
# EXIT now stops the same cluster only after preparation and order cases finish.
