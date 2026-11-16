<?php

declare(strict_types=1);

use PortalTestTools\P20\DisposablePreparation as Gate;

require __DIR__.'/../../../tools/p20/DisposablePreparation.php';
Gate::entry(static function () use ($argv): mixed {
    $case = $argv[1];
    if ($case === 'exit-zero') {
        exit(0);
    }
    if ($case === 'missing-result') {
        return null;
    }
    if ($case === 'empty-proof') {
        return ['schema' => 'P20_PREPARATION_RESULT_V1', 'completed' => true, 'target' => []];
    }
    if ($case === 'exception') {
        throw new RuntimeException('SYNTHETIC_SECRET_MUST_NOT_LEAK');
    }
    if ($case === 'fatal') {
        nonexistent_p20_test_function();
    }
    $config = ['environment' => 'testing', 'default' => 'pgsql', 'ci' => 'true', 'p20' => '1',
        'connection' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432',
            'database' => Gate::DATABASE, 'username' => 'postgres', 'password' => 'synthetic',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']];
    $local = ['directory' => '/synthetic/p20', 'pid' => 123, 'started' => 1794858009,
        'port' => 5432, 'system_identifier' => '123456789012345'];
    $sql = ['database' => Gate::DATABASE, 'address' => '127.0.0.1/32', 'port' => 5432,
        'directory' => $local['directory'], 'started' => $local['started'], 'system_identifier' => $local['system_identifier']];
    $changes = [
        'production' => ['environment', 'production'], 'wrong-default' => ['default', 'sqlite'],
        'no-ci' => ['ci', 'false'], 'no-p20' => ['p20', '0'],
    ];
    if (isset($changes[$case])) {
        [$key, $value] = $changes[$case]; $config[$key] = $value;
    }
    $connectionChanges = ['remote' => ['host', '192.0.2.1'], 'hostname' => ['host', 'localhost'],
        'multi-host' => ['host', ['127.0.0.1', '192.0.2.1']], 'cidr-config' => ['host', '127.0.0.1/32'],
        'bad-port' => ['port', '5432x'], 'other-port' => ['port', '5433'],
        'wrong-db' => ['database', 'not_the_disposable_database'], 'url' => ['url', 'synthetic-url'],
        'read-routing' => ['read', ['host' => '192.0.2.1']], 'dsn-override' => ['connect_via_database', 'other'],
        'options' => ['options', []], 'wrong-driver' => ['driver', 'mysql']];
    if (isset($connectionChanges[$case])) {
        [$key, $value] = $connectionChanges[$case]; $config['connection'][$key] = $value;
    }
    $sqlChanges = ['sql-db' => ['database', 'other'], 'sql-addr' => ['address', '192.0.2.1'],
        'sql-subnet' => ['address', '127.0.0.1/24'], 'sql-port' => ['port', 5433],
        'sql-dir' => ['directory', '/other'], 'sql-start' => ['started', 1794858010],
        'sql-id' => ['system_identifier', '987654321']];
    if (isset($sqlChanges[$case])) {
        [$key, $value] = $sqlChanges[$case]; $sql[$key] = $value;
    }
    if ($case === 'valid-plain') { $sql['address'] = '127.0.0.1'; }
    $reads = 0;
    $localReads = 0;
    return Gate::run(static function () use (&$reads, $config, $case): array {
        ++$reads;
        if ($case === 'config-changed' && $reads > 1) { $config['connection']['password'] = 'changed'; }
        return $config;
    }, static function () use ($case, $local, &$localReads): mixed {
        ++$localReads;
        if ($case === 'cluster-changed' && $localReads > 1) { ++$local['started']; }
        if ($case === 'control-failed') { Gate::controlIdentity(2, 'Database system identifier: 123'); }
        if ($case === 'control-empty') { Gate::controlIdentity(0, ''); }
        if ($case === 'control-duplicate') { Gate::controlIdentity(0, "Database system identifier: 123\nDatabase system identifier: 123\n"); }
        if ($case === 'missing-local') { return null; }
        return $local;
    }, static function () use ($sql, $case, $argv): mixed {
        file_put_contents($argv[2].'.query', 'QUERY_CALLED');
        if ($case === 'sql-exception') { throw new RuntimeException('SYNTHETIC_SECRET_MUST_NOT_LEAK'); }
        if ($case === 'missing-sql') { return null; }
        return $sql;
    }, static function (array $proof) use ($argv): int {
        file_put_contents($argv[2], json_encode($proof, JSON_THROW_ON_ERROR));
        return 0;
    }, 5432);
});
