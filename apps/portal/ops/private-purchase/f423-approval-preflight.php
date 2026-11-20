<?php
declare(strict_types=1);

/**
 * Read-only, fail-closed preflight BEFORE the F423 schema is applied.
 * Check the effective Laravel bootstrap AND the actual SQL connection used by
 * the isolated private-purchase router; never infer this from .env alone.
 * This is a CI attestation, not production IAM or authorisation.
 */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_PREVIEW_LOOPBACK_TEST') !== '1'
    || getenv('PORTAL_PURCHASE_PREVIEW_ENABLED') !== '1'
    || getenv('PORTAL_DEVELOPMENT_AUTH_ENABLED') !== 'false'
    || getenv('APP_ENV') !== 'staging'
    || getenv('APP_DEBUG') !== 'false'
    || getenv('APP_URL') !== 'http://127.0.0.1:18083') {
    fwrite(STDERR, "F423 preflight: not an isolated CI Laravel runtime\n");
    exit(1);
}
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->environment() !== 'staging'
    || config('app.env') !== 'staging'
    || config('app.url') !== 'http://127.0.0.1:18083'
    || config('app.debug') !== false) {
    fwrite(STDERR, "F423 preflight: effective Laravel configuration mismatch\n");
    exit(1);
}
$secret='/run/secrets/preview-runtime.json';
$stat=@stat($secret);
if (!is_array($stat) || ($stat['mode'] & 077) !== 0 || $stat['uid'] !== 82 || $stat['size'] > 2048) {
    fwrite(STDERR,"F423 preflight: invalid dedicated SQL secret mount\n");
    exit(1);
}
$raw=@file_get_contents($secret);
$config=is_string($raw)?json_decode($raw,true):null;
unset($raw);
if (!is_array($config)
    || !is_string($config['database_password']??null)
    || preg_match('/\A[a-f0-9]{64}\z/D',$config['database_password'])!==1) {
    fwrite(STDERR,"F423 preflight: unavailable isolated SQL credential\n");
    exit(1);
}
try {
    $pdo=new \PDO('pgsql:host=database;port=5432;dbname=portal_purchase_rehearsal;connect_timeout=3',
        'purchase_rehearsal_a',$config['database_password'],
        [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
    unset($config);
    $s=$pdo->query("SELECT current_database() AS db, current_user AS username,
      session_user AS session_name, current_setting('server_version_num')::int AS version,
      inet_server_addr() IS NOT NULL AS tcp")->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($s) || $s['db']!=='portal_purchase_rehearsal'
        || $s['username']!=='purchase_rehearsal_a'
        || $s['session_name']!=='purchase_rehearsal_a'
        || (int)$s['version']<180000 || (int)$s['version']>=190000
        || !in_array($s['tcp'],[true,'t',1,'1'],true)) {
        throw new \RuntimeException('Unexpected actual SQL connection');
    }
    $r=$pdo->query("SELECT rolsuper, rolbypassrls, rolcreaterole, rolcreatedb
        FROM pg_roles WHERE rolname=current_user")->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($r) || in_array(true,array_map(static fn($v):bool =>
         in_array($v,[true,'t',1,'1'],true),array_values($r)),true)) {
        throw new \RuntimeException('SQL role is privileged');
    }
    $present=$pdo->query("SELECT to_regclass('purchase_rehearsal.orders') IS NOT NULL")
        ->fetchColumn();
    $existing=$pdo->query("SELECT to_regclass('purchase_rehearsal.f423_briefing_reviews') IS NOT NULL")
        ->fetchColumn();
    if (!in_array($present,[true,'t',1,'1'],true)
        || in_array($existing,[true,'t',1,'1'],true)) {
        throw new \RuntimeException('Not a new disposable SQL workspace');
    }
} catch (\Throwable $e) {
    fwrite(STDERR,"F423 preflight: SQL identity/preexisting schema not disposable\n");
    exit(1);
}
echo "PASS F423 PRE-DDL: effective Laravel staging + actual disposable PG18 role/database, no preexisting review ledger\n";
