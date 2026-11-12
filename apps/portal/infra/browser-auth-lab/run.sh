#!/bin/sh
set -eu
test "${APP_ENV:-}" = testing
test "${PORTAL_BROWSER_PG_LAB:-}" = DISPOSABLE_PG18
test "${PGDATABASE:-}" = portal_browser_lab
test "${PGHOST:-}" = 127.0.0.1
test "${PGPORT:-}" = 5432
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app
for migration in \
  2026_11_09_000002_create_portal_identity_foundation.php \
  2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php \
  2026_11_10_000004_add_org_revision_to_portal_sessions.php \
  2026_11_10_000005_create_portal_auth_throttle_buckets.php \
  2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php \
  2026_11_11_000012_fence_portal_membership_revision.php \
  2026_11_11_000013_fence_portal_organization_revision.php \
  2026_11_11_000014_guard_portal_session_issuance.php \
  2026_11_12_000016_fence_portal_identity_revision.php
do
  php artisan migrate --path="database/migrations/$migration" --force --no-interaction
done
php -r '
$db=new PDO("pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab","postgres",getenv("PGPASSWORD"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query("SELECT current_database()")->fetchColumn()!=="portal_browser_lab"){exit(1);}
$db->exec(file_get_contents("database/security/portal_browser_auth.sql"));
$db->exec(file_get_contents("database/security/portal_browser_envelopes.sql"));
$db->exec("ALTER ROLE portal_browser_runtime LOGIN PASSWORD ".$db->quote(getenv("PORTAL_BROWSER_RUNTIME_PASSWORD")));
$db->exec("ALTER ROLE portal_browser_envelope_runtime LOGIN PASSWORD ".$db->quote(getenv("PORTAL_BROWSER_ENVELOPE_PASSWORD")));
'
vendor/bin/phpunit tests/Integration/PortalBrowserAuthenticationPg18Test.php --no-progress --cache-directory=/tmp/phpunit-auth --fail-on-risky --fail-on-warning --fail-on-skipped
timeout 45s vendor/bin/phpunit tests/Integration/PortalBrowserEnvelopePg18Test.php --no-progress --cache-directory=/tmp/phpunit-envelope --fail-on-risky --fail-on-warning --fail-on-skipped
vendor/bin/phpunit tests/Feature/PortalBrowserAuthTransportTest.php --no-progress --cache-directory=/tmp/phpunit-http --fail-on-risky --fail-on-warning --fail-on-skipped
vendor/bin/phpunit tests/Feature/PortalDefaultDenyAuthorityBindingsTest.php --no-progress --cache-directory=/tmp/phpunit-bindings --fail-on-risky --fail-on-warning --fail-on-skipped
php artisan route:list --json | php -r '
$routes=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(count($routes)!==1||$routes[0]["uri"]!=="up"){exit(1);}
echo "PASS private browser-auth Docker rehearsal: no operational login route\n";
'
