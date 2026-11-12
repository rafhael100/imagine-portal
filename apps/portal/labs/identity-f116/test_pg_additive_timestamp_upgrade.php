<?php

declare(strict_types=1);

// Synthetic upgrade rehearsal only, before F116 installs fixture SQL roles.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F116_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1' || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab'
    || getenv('DB_CONNECTION') !== 'pgsql' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '5432' || getenv('DB_DATABASE') !== 'portal_identity_lab'
    || getenv('DB_USERNAME') !== 'postgres') {
    throw new RuntimeException('Disposable local upgrade laboratory required.');
}
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'pgsql'
    || (array) $db->selectOne('SELECT current_user AS actor,current_database() AS db')
        !== ['actor' => 'postgres', 'db' => 'portal_identity_lab']
    || (int) $db->selectOne("SELECT current_setting('server_version_num')::integer / 10000 AS major")->major !== 18) {
    throw new RuntimeException('Disposable PostgreSQL 18 metadata required.');
}
$tables = [
    'portal_identities' => ['email_verified_at', 'mfa_confirmed_at'],
    'portal_auth_sessions' => ['mfa_verified_at', 'issued_at', 'expires_at', 'revoked_at'],
    'portal_email_verification_challenges' => ['issued_at', 'expires_at', 'consumed_at', 'revoked_at'],
];
foreach ($tables as $table => $_) {
    if ((int) $db->selectOne('SELECT count(*) AS n FROM public."' . $table . '"')->n !== 0) {
        throw new RuntimeException('Empty synthetic tables required before upgrade rehearsal.');
    }
}
$migration = require __DIR__ . '/../../database/migrations/2026_11_11_000015_preserve_portal_security_timestamp_precision.php';
$db->beginTransaction();
try {
    // Recreate old physical column types only in this disposable transaction.
    foreach ($tables as $table => $columns) {
        foreach ($columns as $column) {
            $db->statement('ALTER TABLE public."' . $table . '" ALTER COLUMN "' . $column . '" TYPE timestamp(0) with time zone');
        }
    }
    $db->insert("INSERT INTO public.portal_identities
        (id,email_normalized,status,email_verified_at,mfa_confirmed_at)
        VALUES ('10000000-0000-4000-8000-000000000001','precision-upgrade@example.invalid',
                'invited','2026-01-01 00:00:01+00',NULL)");
    $rowSql = "SELECT row_to_json(i)::text AS snapshot FROM public.portal_identities i";
    $before = $db->selectOne($rowSql)->snapshot;
    $securitySql = "SELECT c.relname,c.relrowsecurity,c.relforcerowsecurity,c.relowner,c.relacl::text,
                       (SELECT jsonb_agg(to_jsonb(p) ORDER BY p.policyname) FROM pg_policies p
                         WHERE p.schemaname='public' AND p.tablename=c.relname) AS policies,
                       (SELECT jsonb_agg(jsonb_build_array(a.attname,a.attacl::text) ORDER BY a.attnum)
                         FROM pg_attribute a WHERE a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped) AS columns
                      FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                     WHERE n.nspname='public' AND c.relname IN
                      ('portal_identities','portal_auth_sessions','portal_email_verification_challenges')
                     ORDER BY c.relname";
    $security = json_encode($db->select($securitySql), JSON_THROW_ON_ERROR);
    $migration->up();
    $migration->up(); // Reapplying an already upgraded schema is harmless.
    if ($db->selectOne($rowSql)->snapshot !== $before
        || json_encode($db->select($securitySql), JSON_THROW_ON_ERROR) !== $security) {
        throw new RuntimeException('Upgrade changed stored identity values or security metadata.');
    }
    foreach ($tables as $table => $columns) {
        foreach ($columns as $column) {
            $precision = $db->selectOne("SELECT datetime_precision FROM information_schema.columns
                WHERE table_schema='public' AND table_name=? AND column_name=?", [$table,$column]);
            if ($precision === null || (int) $precision->datetime_precision !== 6) {
                throw new RuntimeException('Additive migration did not upgrade every column.');
            }
        }
    }
    $refused = false;
    try {
        $migration->down();
    } catch (RuntimeException $error) {
        $refused = str_starts_with($error->getMessage(), 'Lossy timestamp precision rollback refused;');
    }
    if (!$refused || $db->selectOne($rowSql)->snapshot !== $before) {
        throw new RuntimeException('Lossy rollback was not safely refused.');
    }
    echo "PASS additive precision upgrade: 10 legacy columns, stored values, RLS/ACL, repeat application and rollback refusal\n";
} finally {
    $db->rollBack(); // Remove fixture and restore the original CI schema.
}
