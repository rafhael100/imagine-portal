<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use PortalTestTools\P20\DisposablePreparation as Gate;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/DisposablePreparation.php';
Gate::entry(static function () use ($argv): array {
    Gate::require(count($argv) === 5 && in_array($argv[1], ['--check', '--migrate'], true));
    Gate::require(ctype_digit($argv[4]) && (string) (int) $argv[4] === $argv[4]);
    $port = (int) $argv[4];
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    // Load effective cached/.env configuration before booting application providers.
    (new LoadEnvironmentVariables)->bootstrap($app);
    (new LoadConfiguration)->bootstrap($app);
    $snapshot = static fn (): array => ['environment' => $app->environment(),
        'default' => $app['config']->get('database.default'),
        'connection' => $app['config']->get('database.connections.pgsql'),
        'ci' => getenv('CI'), 'p20' => getenv('P20_AUTHORITY_PG_CI')];
    $beforeBootstrap = $snapshot();
    Gate::configuration($beforeBootstrap, $port);
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    Gate::require($snapshot() === $beforeBootstrap);
    $db = null;
    $pdo = null;
    return Gate::run($snapshot,
        static fn (): array => Gate::localEvidence($argv[2], $argv[3], $port),
        static function () use ($app, &$db, &$pdo, $snapshot, $port): array {
            $db = $app['db']->connection('pgsql');
            $effective = $snapshot();
            $effective['connection'] = array_diff_key($db->getConfig(), ['name' => true]);
            Gate::require(Gate::configuration($effective, $port) === Gate::configuration($snapshot(), $port));
            $db->setReconnector(static function (): never {
                throw new RuntimeException('P20_RECONNECT_FORBIDDEN');
            });
            $pdo = $db->getPdo();
            $sql = (array) $db->selectOne("SELECT current_database() AS database,
                inet_server_addr()::text AS address, inet_server_port() AS port,
                current_setting('data_directory') AS directory,
                floor(extract(epoch FROM pg_postmaster_start_time()))::bigint AS started,
                system_identifier::text FROM pg_control_system()", [], false);
            return $sql;
        },
        static function (array $proof) use ($argv, $kernel, $app, &$db, &$pdo): int {
            Gate::require($proof['schema'] === 'P20_DISPOSABLE_TARGET_V1');
            Gate::require($app['db']->connection('pgsql') === $db && $db->getPdo() === $pdo);
            if ($argv[1] === '--check') {
                return 0;
            }
            // No shell gap, second bootstrap, config reload or fresh connection.
            $output = new BufferedOutput;
            $code = $kernel->call('migrate', ['--database' => 'pgsql', '--force' => true,
                '--no-interaction' => true], $output);
            Gate::require($code === 0);
            fwrite(STDOUT, "P20_MIGRATIONS_COMPLETED\n");
            return $code;
        }, $port);
});
