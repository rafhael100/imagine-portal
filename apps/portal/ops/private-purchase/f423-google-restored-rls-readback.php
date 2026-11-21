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
$stage = 'connect';
try {
    $db = new PDO(
        'pgsql:host=database;port=5432;dbname=portal_f423_restore_disposable_ci;connect_timeout=3',
        'purchase_rehearsal_a', $cfg['database_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $stage = 'sql_identity';
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
    $stage = 'restricted_role';
    $role = $db->query("SELECT rolsuper,rolbypassrls,rolcreatedb,rolcreaterole
        FROM pg_roles WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($role)) throw new RuntimeException('Missing restricted role');
    foreach ($role as $flag) {
        if (in_array($flag, [true,'t','1',1], true)) {
            throw new RuntimeException('Restored role unexpectedly privileged');
        }
    }
    $stage = 'force_rls_flags';
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
    // Read the exact buyer from the already-attested SOURCE fixture over STDIN.
    // Never discover a buyer through an admin claim in the restored database.
    $stage = 'source_fixture_buyer';
    $buyerLine = fgets(STDIN);
    if (!is_string($buyerLine) || !preg_match('/\Ademo-[a-f0-9]{32}\n\z/D', $buyerLine)
        || fgets(STDIN) !== false) {
        throw new RuntimeException('Missing unique source fixture buyer');
    }
    $owner = substr($buyerLine, 0, -1);

    // The restricted login without a signed scope must see no ledger rows.
    $stage = 'unscoped_select';
    requireCounts(rlsCounts($db,$tables), array_fill_keys(array_keys($tables),0));

    // Reuse the same signed, transaction-local claim contract as the CI
    // CustomerPreviewStore, without constructing its SOURCE-db-only adapter.
    // This is not an IAM principal and cannot authorize a real Ads account.
    $scopedCounts = static function (string $principal) use ($db,$cfg,$tables): array {
        if (!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$principal) || $db->inTransaction()) {
            throw new RuntimeException('Invalid isolated fixture scope');
        }
        $key = hex2bin($cfg['customer_scope_key']);
        if ($key === false) throw new RuntimeException('Invalid fixture verifier');
        $mac = hash_hmac('sha256','imagine-owned-preview-v1:'.$principal,$key);
        $db->beginTransaction();
        try {
            $stmt=$db->prepare("SELECT set_config('imagine.preview_principal',?,true),
                                      set_config('imagine.preview_mac',?,true)");
            $stmt->execute([$principal,$mac]);
            $counts=rlsCounts($db,$tables);
            $db->commit();
            return $counts;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    };

    $stage = 'source_buyer_signed_scope';
    requireCounts($scopedCounts($owner),$tables);
    // A different validly signed synthetic buyer cannot see the source rows.
    $stage = 'other_buyer_signed_scope';
    $other = 'demo-'.str_repeat($owner === 'demo-'.str_repeat('e',32) ? 'd' : 'e',32);
    requireCounts($scopedCounts($other),array_fill_keys(array_keys($tables),0));
    // An unsigned/forged GUC must never bypass the database policy.
    $stage = 'forged_scope';
    $db->beginTransaction();
    try {
        $stmt=$db->prepare("SELECT set_config('imagine.preview_principal',?,true),
                                   set_config('imagine.preview_mac',?,true)");
        $stmt->execute([$owner,str_repeat('0',64)]);
        requireCounts(rlsCounts($db,$tables),array_fill_keys(array_keys($tables),0));
    } finally {
        $db->rollBack();
    }
    // Every SET LOCAL scope must have vanished on commit or rollback.
    $stage = 'post_transaction_unscoped';
    requireCounts(rlsCounts($db,$tables),array_fill_keys(array_keys($tables),0));
    $stage = 'signing_secret_privilege';
    if (in_array($db->query("SELECT has_table_privilege(current_user,
        'purchase_rehearsal.preview_scope_secret','SELECT')")->fetchColumn(),[true,'t','1',1],true)) {
        throw new RuntimeException('Restricted role can read signing key');
    }
    echo "PASS F423 RESTORED EFFECTIVE RLS: 9 ledgers only for signed buyer, 2 dated offline rows,
wrong signed buyer 0 rows, forged MAC 0 rows, unscoped 0 rows, signing secret unreadable; PG18 TCP, NO-GO\n";
} catch (Throwable $error) {
    // No exception details: avoid accidentally printing identifiers or secrets.
    fwrite(STDERR, "F423 restored RLS: fail closed at ".$stage."\n");
    exit(1);
}
