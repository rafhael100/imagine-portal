#!/usr/bin/env bash
# Entry point: refuse ambient configuration BEFORE invoking PHP or PostgreSQL.
set -euo pipefail
export PATH=/usr/bin:/bin
fail() { echo "ORDER_LAUNCH_REFUSED: $1" >&2; exit 2; }
for name in $(compgen -e); do
  case "$name" in
    PG_BIN|PHP_BIN) ;;
    PG*|PHP*|APP_*|DB_*|DATABASE_URL|BASH_ENV|ENV|LD_PRELOAD|LD_LIBRARY_PATH)
      fail 'ambient configuration' ;;
  esac
done
[[ "${ONEPAGE_ORDER_ALLOW_LOCAL_START:-}" == SYNTHETIC_ONLY ]] || fail 'opt in required'
[[ "${PG_BIN:-}" = /* && "${PHP_BIN:-}" = /* ]] || fail 'absolute tooling required'
lab_source=$(cd -- "$(dirname -- "$0")" && pwd)
app_root=$(cd -- "$lab_source/../.." && pwd)
for file in "$app_root/.env" "$app_root/.env.testing" "$app_root/bootstrap/cache/config.php"; do
  [[ ! -e "$file" && ! -L "$file" ]] || fail 'dotenv or configuration cache'
done
runtime_lib=${ONEPAGE_PG_RUNTIME_LIB:-}
[[ "$runtime_lib" = /* && -d "$runtime_lib" && "$runtime_lib" != *:* ]] || fail 'explicit runtime library directory required'
[[ -x "$PHP_BIN" && -x "$PG_BIN/initdb" && -x "$PG_BIN/pg_ctl" ]] || fail 'tooling unavailable'
# Reconstitute a minimal process; HOME cannot supply .pgpass/service configuration.
private_root=$(mktemp -d /tmp/onepage-order-clean.XXXXXXXX)
mkdir -m 700 "$private_root/home"
# Discovery runs without any PHP configuration and with only the selected loader libraries.
extension_dir=$(/usr/bin/env -i PATH=/usr/bin:/bin HOME="$private_root/home" LC_ALL=C LD_LIBRARY_PATH="$runtime_lib"  "$PHP_BIN" -n -r 'echo ini_get("extension_dir");')
[[ "$extension_dir" = /* && -d "$extension_dir" && "$extension_dir" != *:* ]] || fail 'PHP extension directory unavailable'
exec /usr/bin/env -i PATH=/usr/bin:/bin HOME="$private_root/home" LC_ALL=C LD_LIBRARY_PATH="$runtime_lib"  /bin/bash --noprofile --norc "$lab_source/run-clean.sh" "$PG_BIN" "$PHP_BIN" "$lab_source" "$extension_dir"
