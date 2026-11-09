<?php

declare(strict_types=1);

/** F6 synthetic offline admin action during real HTTP cookie tests. */
$operation = $argv[1] ?? '';
if (
    PHP_SAPI !== 'cli'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_HTTP_PG_LAB') !== '1'
    || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'portal_lab'
    || getenv('DB_USERNAME') !== 'postgres'
    || ! in_array($operation, ['membership', 'account-revision'], true)
) {
    fwrite(STDERR, '[FAIL F6] Refusing non-lab revoke operation'.PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection('pgsql');
if ($db->selectOne('select current_database() as name')->name !== 'portal_lab') {
    throw new \RuntimeException('Non-disposable database');
}

if ($operation === 'membership') {
    // Participates in F4 grant fencing protocol.
    require_once __DIR__.'/../client-auth/LabMembershipFence.php';
    $affected = \App\Labs\ClientAuth\LabMembershipFence::revoke('portal-user-a', 'org-a');
} else {
    $affected = $db->table('lab_portal_users')
        ->where('id', 'portal-user-a')
        ->where('session_revision', 1)
        ->update(['session_revision' => 2]);
}

if ($affected !== 1) {
    throw new \RuntimeException('Synthetic state transition did not affect exactly one fixture');
}

echo "[PASS F6] synthetic ".$operation." revoked/updated in disposable PostgreSQL\n";
