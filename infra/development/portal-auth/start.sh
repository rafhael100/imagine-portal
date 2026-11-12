#!/bin/sh
set -eu
umask 077
test "$(id -u)" = 82
test ! -e /run/secrets/database-admin
test ! -L /run/secrets/app-key
test "$(stat -c '%u:%a:%h' /run/secrets/app-key)" = 82:600:1
test "$(wc -c < /run/secrets/app-key)" -le 128
export APP_KEY="$(cat /run/secrets/app-key)"
php -r '$v=getenv("APP_KEY");if(!is_string($v)||!str_starts_with($v,"base64:")||strlen(base64_decode(substr($v,7),true)?: "")!==32){exit(1);}'
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
exec php-fpm -F
