<?php
declare(strict_types=1);

/**
 * CI-only pre-DDL guard for the existing Phase8 authority workflow.
 * Laravel's EFFECTIVE configuration and the SQL connection must both point
 * at the one disposable PostgreSQL18 database before artisan migrate runs.
 * No DDL, privilege changes, data writes, or credentials are emitted.
 */
try {
    foreach ([
        'GITHUB_ACTIONS' => 'true',
        'CI' => 'true',
        'ADMIN_PHASE4_PG_CI' => '1',
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'performance_phase4_ci',
        'DB_USERNAME' => 'postgres',
    ] as $key => $value) {
        if (getenv($key) !== $value) {
            throw new RuntimeException('Guard flag mismatch');
        }
    }

    require __DIR__ . '/../../../vendor/autoload.php';
    $app = require __DIR__ . '/../../../bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $config = $app->make('config');
    $connectionName = $config->get('database.default');
    $target = $config->get('database.connections.' . $connectionName);
    if ($connectionName !== 'pgsql' || !is_array($target)
        || ($target['driver'] ?? '') !== 'pgsql'
        || ($target['host'] ?? '') !== '127.0.0.1'
        || (int) ($target['port'] ?? 0) !== 5432
        || ($target['database'] ?? '') !== 'performance_phase4_ci'
        || ($target['username'] ?? '') !== 'postgres') {
        throw new RuntimeException('Effective Laravel database configuration mismatch');
    }

    $db = $app->make('db')->connection();
    if ($db->getDriverName() !== 'pgsql'
        || $db->getDatabaseName() !== 'performance_phase4_ci') {
        throw new RuntimeException('Effective Laravel connection mismatch');
    }

    $row = $db->selectOne(
        'SELECT current_database() AS name, current_user AS role, ' .
        "current_setting('server_version_num')::int AS version"
    );
    if ($row === null || $row->name !== 'performance_phase4_ci'
        || $row->role !== 'postgres' || (int) $row->version < 180000) {
        throw new RuntimeException('SQL endpoint mismatch');
    }
    echo "PASS PHASE8 EFFECTIVE LARAVEL AND SQL DISPOSABLE PREFLIGHT\n";
} catch (\Throwable $error) {
    // Deliberately never print exception messages, DSNs or secrets.
    fwrite(STDERR, "PHASE8_DISPOSABLE_PREFLIGHT_FAIL_CLOSED\n");
    exit(1);
}
