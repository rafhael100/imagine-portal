<?php
declare(strict_types=1);
// Isolated CI-only guard BEFORE pg_dump/createdb/pg_restore.
// The customer-facing Laravel connection remains on the original disposable
// database; this gate does NOT authorize a second application connection.
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_PREVIEW_LOOPBACK_TEST') !== '1'
    || getenv('APP_ENV') !== 'staging'
    || getenv('APP_DEBUG') !== 'false'
    || getenv('APP_URL') !== 'http://127.0.0.1:18083') {
    fwrite(STDERR, "F423 restore: forbidden outside isolated CI staging\n");
    exit(1);
}
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (config('app.env') !== 'staging'
    || config('app.url') !== 'http://127.0.0.1:18083'
    || config('app.debug') !== false) exit(1);
$st = @stat('/run/secrets/preview-runtime.json');
if (!is_array($st) || ($st['mode'] & 077) !== 0 || $st['uid'] !== 82 || $st['size'] > 2048) exit(1);
$cfg = json_decode((string) @file_get_contents('/run/secrets/preview-runtime.json'), true);
if (!is_array($cfg) || !preg_match('/\\A[a-f0-9]{64}\\z/D', $cfg['database_password'] ?? '')) exit(1);
try {
    $db = new PDO(
        'pgsql:host=database;port=5432;dbname=portal_purchase_rehearsal;connect_timeout=3',
        'purchase_rehearsal_a', $cfg['database_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $row = $db->query("SELECT current_database() AS db, current_user AS usr,
        session_user AS sess, current_setting('server_version_num')::int AS version,
        inet_server_addr() IS NOT NULL AS tcp")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['db'] !== 'portal_purchase_rehearsal'
        || $row['usr'] !== 'purchase_rehearsal_a' || $row['sess'] !== 'purchase_rehearsal_a'
        || (int) $row['version'] < 180000 || (int) $row['version'] >= 190000
        || !in_array($row['tcp'], ['t', true, '1', 1], true)) throw new RuntimeException();
    $role = $db->query("SELECT rolsuper,rolbypassrls,rolcreatedb,rolcreaterole
        FROM pg_roles WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($role)) throw new RuntimeException();
    foreach ($role as $flag) if (in_array($flag, [true, 't', '1', 1], true)) throw new RuntimeException();
    if ($db->query("SELECT 1 FROM pg_database
        WHERE datname='portal_f423_restore_disposable_ci'")->fetchColumn() !== false) {
        throw new RuntimeException('Destination is not new');
    }
    foreach ([
        'f423_briefing_reviews', 'f423_performance_decisions',
        'f423_google_diagnostic_plans', 'f423_google_plan_approvals',
        'f423_google_internal_drafts', 'f423_google_monitoring_samples',
        'f423_google_offline_improvements', 'f423_google_improvement_approvals',
        'f423_google_offline_applied_results'
    ] as $table) {
        $flags = $db->query("SELECT relrowsecurity,relforcerowsecurity
            FROM pg_class WHERE oid='purchase_rehearsal.".$table."'::regclass")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($flags)) throw new RuntimeException();
        foreach ($flags as $flag) if (!in_array($flag, [true, 't', '1', 1], true)) throw new RuntimeException();
    }
} catch (Throwable $e) {
    fwrite(STDERR, "F423 restore: effective Laravel/TCP SQL destination not attested, abort DDL\n");
    exit(1);
}
echo "PASS F423 RESTORE PRE-DDL: exact Laravel staging, restricted TCP PG18, existing FORCE RLS, absent fresh destination\n";
