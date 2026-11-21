<?php
declare(strict_types=1);

/**
 * CI-only pre-DDL guard for the existing Phase8 authority workflow.
 * Laravel's EFFECTIVE configuration and the SQL connection must both point
 * at the one disposable PostgreSQL18 database before artisan migrate runs.
 * No DDL, privilege changes, data writes, or credentials are emitted.
 */
$stage = 'FLAGS';
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

    $stage = 'BOOTSTRAP';
    require __DIR__ . '/../../../vendor/autoload.php';
    $app = require __DIR__ . '/../../../bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $stage = 'EFFECTIVE_CONFIG';
    $config = $app->make('config');
    $connectionName = $config->get('database.default');
    $target = $config->get('database.connections.' . $connectionName);
    if ($connectionName !== 'pgsql' || !is_array($target)
        || ($target['driver'] ?? '') !== 'pgsql'
        || ($target['host'] ?? '') !== '127.0.0.1'
        || (int) ($target['port'] ?? 0) !== 5432
        || ($target['database'] ?? '') !== 'performance_phase4_ci'
        || ($target['username'] ?? '') !== 'postgres'
        // A URL can override Laravel's effective endpoint. Read/write
        // sub-connections can route migrations to a different writer.
        || !empty($target['url']) || isset($target['read']) || isset($target['write'])
        || !empty($target['unix_socket'])) {
        throw new RuntimeException('Effective Laravel database configuration mismatch');
    }

    $stage = 'CONNECTION';
    $db = $app->make('db')->connection();
    if ($db->getDriverName() !== 'pgsql'
        || $db->getDatabaseName() !== 'performance_phase4_ci') {
        throw new RuntimeException('Effective Laravel connection mismatch');
    }

    $stage = 'SQL_QUERY';
    $row = $db->selectOne(
        'SELECT current_database() AS name, current_user AS role, ' .
        "current_setting('server_version_num')::int AS version, " .
        "CASE WHEN inet_server_addr() IS NULL THEN 0 ELSE 1 END AS tcp_transport, " .
        "inet_server_port() AS server_port"
    );
    $stage = 'SQL_IDENTITY';
    if ($row === null || $row->name !== 'performance_phase4_ci'
        || $row->role !== 'postgres' || (int) $row->version < 180000) {
        throw new RuntimeException('SQL identity mismatch');
    }
    // A PostgreSQL inet value can include a mask and the server runs behind
    // Actions Docker NAT. Verify TCP, not the textual form of the address;
    // Laravel's effective client endpoint was checked separately above.
    $stage = 'SQL_TCP_ADDRESS';
    if ((int) $row->tcp_transport !== 1) {
        throw new RuntimeException('SQL TCP transport missing');
    }
    $stage = 'SQL_TCP_PORT';
    if ((int) $row->server_port !== 5432) {
        throw new RuntimeException('SQL TCP port mismatch');
    }
    echo "PASS PHASE8 EFFECTIVE LARAVEL AND SQL DISPOSABLE PREFLIGHT\n";
} catch (\Throwable $error) {
    // Deliberately never print exception messages, DSNs or secrets.
    fwrite(STDERR, "PHASE8_DISPOSABLE_PREFLIGHT_FAIL_CLOSED\n");
    // Fixed stage codes only; never expose Throwable messages/DSNs/values.
    fwrite(STDERR, "PHASE8_PREFLIGHT_STAGE_{$stage}\n");
    exit(1);
}
