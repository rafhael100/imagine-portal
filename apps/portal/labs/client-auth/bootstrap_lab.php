<?php

declare(strict_types=1);

/** Never run outside ephemeral CLI tests on GitHub's synthetic database. */
if (PHP_SAPI !== 'cli'
    || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'portal_lab') {
    fwrite(STDERR, "Refusing non-lab SQL role bootstrap.\n");
    exit(1);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = \Illuminate\Support\Facades\DB::connection('pgsql');
$databaseName = $connection->selectOne('SELECT current_database() as name')->name;
if ($databaseName !== 'portal_lab') {
    throw new RuntimeException('Refusing non-disposable PostgreSQL database');
}

$connection->statement(
    "CREATE ROLE portal_lab_runtime LOGIN PASSWORD 'portal-lab-runtime-only' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT"
);
$connection->statement('GRANT CONNECT ON DATABASE portal_lab TO portal_lab_runtime');
$connection->statement('GRANT USAGE ON SCHEMA public TO portal_lab_runtime');
echo "[PASS LAB BOOTSTRAP] separate read-only runtime role created in ephemeral portal_lab\n";
