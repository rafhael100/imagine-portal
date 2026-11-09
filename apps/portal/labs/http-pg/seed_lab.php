<?php

declare(strict_types=1);

/**
 * Prepare disposable PostgreSQL for F6 (never execute with production data).
 * Roles are created separately by F1 bootstrap_lab.php in this GH runner.
 */
if (PHP_SAPI !== 'cli'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_HTTP_PG_LAB') !== '1'
    || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'portal_lab'
    || getenv('DB_USERNAME') !== 'postgres') {
    fwrite(STDERR, "[FAIL F6] Refusing non-laboratory fixture setup\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection('pgsql');
if ($db->selectOne('SELECT current_database() AS name')->name !== 'portal_lab') {
    throw new \RuntimeException('Refusing non-disposable PostgreSQL');
}

$migration = require __DIR__.'/../client-auth/create_lab_schema.php';
$migration->down();
$migration->up();

$db->table('lab_portal_users')->insert([
    [
        'id' => 'portal-user-a', 'email' => 'a@example.invalid',
        'password_hash' => \Illuminate\Support\Facades\Hash::make('synthetic-pass-A-2026'),
        'status' => 'active', 'session_revision' => 1,
    ],
    [
        'id' => 'portal-user-b', 'email' => 'b@example.invalid',
        'password_hash' => \Illuminate\Support\Facades\Hash::make('synthetic-pass-B-2026'),
        'status' => 'active', 'session_revision' => 1,
    ],
]);

$db->table('lab_portal_auth_factors')->insert([
    [
        'user_id' => 'portal-user-a',
        'secret_hex' => '3132333435363738393031323334353637383930',
        'last_step' => -1,
    ],
    [
        'user_id' => 'portal-user-b',
        'secret_hex' => '4142434445464748494a4b4c4d4e4f5051525354',
        'last_step' => -1,
    ],
]);

$db->table('lab_portal_organizations')->insert([
    ['id' => 'org-a', 'name' => 'Aurora Synthetic', 'status' => 'active'],
    ['id' => 'org-b', 'name' => 'Boreal Synthetic', 'status' => 'active'],
]);
$db->table('lab_portal_memberships')->insert([
    ['org_id' => 'org-a', 'user_id' => 'portal-user-a', 'status' => 'active'],
    ['org_id' => 'org-b', 'user_id' => 'portal-user-b', 'status' => 'active'],
]);
$db->table('lab_portal_services')->insert([
    ['org_id' => 'org-a', 'id' => 'service-a', 'label' => 'Onepage synthetic A'],
    ['org_id' => 'org-b', 'id' => 'service-b', 'label' => 'Performance synthetic B'],
]);

echo "[PASS F6] PostgreSQL ephemeral fixtures seeded: 2 synthetic accounts, organizations and isolated services\n";
