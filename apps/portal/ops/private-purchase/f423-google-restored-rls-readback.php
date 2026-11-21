<?php
declare(strict_types=1);
// CI-only: exercise *effective* PostgreSQL RLS on the restored Google-only
// F423 ledgers. Neither an official IAM authority nor a second app database.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_PREVIEW_LOOPBACK_TEST') !== '1'
    || getenv('APP_ENV') !== 'staging'
    || getenv('APP_DEBUG') !== 'false'
    || getenv('APP_URL') !== 'http://127.0.0.1:18083') {
    fwrite(STDERR, "F423 restored RLS: CI-only staging guard failed\n");
    exit(1);
}
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'staging'
    || config('app.url') !== 'http://127.0.0.1:18083'
    || config('app.debug') !== false) exit(1);
$stat = @stat('/run/secrets/preview-runtime.json');
$raw = @file_get_contents('/run/secrets/preview-runtime.json');
$cfg = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($stat) || ($stat['mode'] & 077) !== 0
    || $stat['uid'] !== 82 || $stat['size'] > 2048
    || !is_array($cfg)
    || !preg_match('/\A[a-f0-9]{64}\z/D', $cfg['database_password'] ?? '')
    || !preg_match('/\A[a-f0-9]{64}\z/D', $cfg['customer_scope_key'] ?? '')) {
    fwrite(STDERR, "F423 restored RLS: no protected CI runtime configuration\n");
    exit(1);
}
$tables = [
    'f423_briefing_reviews' => 1,
    'f423_performance_decisions' => 1,
    'f423_google_diagnostic_plans' => 1,
    'f423_google_plan_approvals' => 1,
    'f423_google_internal_drafts' => 1,
    'f423_google_monitoring_samples' => 2,
    'f423_google_offline_improvements' => 1,
    'f423_google_improvement_approvals' => 1,
    'f423_google_offline_applied_results' => 1,
];
function rlsCounts(PDO $db, array $tables): array
{
    $found = [];
    foreach ($tables as $name => $expected) {
        // All names originate from the fixed local allowlist above.
        $found[$name] = (int) $db->query(
            'SELECT count(*) FROM purchase_rehearsal.'.$name
        )->fetchColumn();
    }
    return $found;
}
function requireCounts(array $found, array $expected): void
{
    if ($found !== $expected) {
        throw new RuntimeException('Restored tenant RLS returned unexpected rows');
    }
}
try {
    $db = new PDO(
        'pgsql:host=database;port=5432;dbname=portal_f423_restore_disposable_ci;connect_timeout=3',
        'purchase_rehearsal_a', $cfg['database_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $identity = $db->query("SELECT current_database() db,current_user usr,session_user sess,
        current_setting('server_version_num')::int version,inet_server_addr() IS NOT NULL tcp")
        ->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity) || $identity['db'] !== 'portal_f423_restore_disposable_ci'
        || $identity['usr'] !== 'purchase_rehearsal_a'
        || $identity['sess'] !== 'purchase_rehearsal_a'
        || (int) $identity['version'] < 180000 || (int) $identity['version'] >= 190000
        || !in_array($identity['tcp'], [true,'t','1',1], true)) {
        throw new RuntimeException('Restored TCP SQL target mismatch');
    }
    $role = $db->query("SELECT rolsuper,rolbypassrls,rolcreatedb,rolcreaterole
        FROM pg_roles WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($role)) throw new RuntimeException('Missing restricted role');
    foreach ($role as $flag) {
        if (in_array($flag, [true,'t','1',1], true)) {
            throw new RuntimeException('Restored role unexpectedly privileged');
        }
    }
    foreach ($tables as $table => $expected) {
        $flags = $db->query("SELECT relrowsecurity,relforcerowsecurity FROM pg_class
            WHERE oid='purchase_rehearsal.".$table."'::regclass")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($flags)) throw new RuntimeException('Missing restored table');
        foreach ($flags as $flag) {
            if (!in_array($flag, [true,'t','1',1], true)) {
                throw new RuntimeException('FORCE RLS lost after restore');
            }
        }
    }
    // The actual LOGIN has no scoped claim and must not see any ledger.
    requireCounts(rlsCounts($db,$tables), array_fill_keys(array_keys($tables),0));

    // This is a generated *test* scope, not a real customer or IAM grant.
    $store = new \App\Homologation\CustomerPreviewStore($db,$cfg['customer_scope_key']);
    $owner = $store->withVerifiedScope(null, function () use ($db):string {
        $rows = $db->query("SELECT DISTINCT customer_id
            FROM purchase_rehearsal.f423_google_offline_applied_results")->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1 || !preg_match('/\Ademo-[a-f0-9]{32}\z/D', $rows[0])) {
            throw new RuntimeException('Expected one synthetic owner');
        }
        return $rows[0];
    });
    $verified = $store->withVerifiedScope($owner, fn() => rlsCounts($db,$tables));
    requireCounts($verified,$tables);
    // A second (even correctly signed) buyer cannot reuse the original row.
    $other = 'demo-'.str_repeat($owner === 'demo-'.str_repeat('e',32) ? 'd' : 'e',32);
    $foreign = $store->withVerifiedScope($other, fn() => rlsCounts($db,$tables));
    requireCounts($foreign,array_fill_keys(array_keys($tables),0));
    // Arbitrarily setting the GUC principal without its correct MAC must fail.
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT set_config('imagine.preview_principal',?,true),
            set_config('imagine.preview_mac',?,true)");
        $stmt->execute([$owner,str_repeat('0',64)]);
        requireCounts(rlsCounts($db,$tables),array_fill_keys(array_keys($tables),0));
    } finally {
        $db->rollBack();
    }
    // SET LOCAL scope must not survive either committed or rolled-back transaction.
    requireCounts(rlsCounts($db,$tables),array_fill_keys(array_keys($tables),0));
    if (in_array($db->query("SELECT has_table_privilege(current_user,
        'purchase_rehearsal.preview_scope_secret','SELECT')")->fetchColumn(),[true,'t','1',1],true)) {
        throw new RuntimeException('Restricted role can read signing key');
    }
    echo "PASS F423 RESTORED EFFECTIVE RLS: 9 ledgers only for signed buyer, 2 dated offline rows,
wrong signed buyer 0 rows, forged MAC 0 rows, unscoped 0 rows, signing secret unreadable; PG18 TCP, NO-GO\n";
} catch (Throwable $error) {
    // No exception details: avoid accidentally printing identifiers or secrets.
    fwrite(STDERR, "F423 restored RLS: fail closed\n");
    exit(1);
}
