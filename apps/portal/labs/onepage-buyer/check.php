<?php
// Read-only prerequisite; deliberately not an installer. Do not execute schema
// manually on an unverified connection. Full guarded installation remains unrun.
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/Guard.php';
use App\Labs\OnepageBuyer\Guard;
try {
    if (getenv('ONEPAGE_BUYER_PG_LAB') !== 'DISPOSABLE_ONLY' || $argc !== 3) throw new RuntimeException;
    Guard::offline();
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $config = config('database.connections.pgsql');
    Guard::configuration($app->environment(), config('database.default'), $config);
    $connection = $app['db']->connection('pgsql');
    Guard::configuration($app->environment(), config('database.default'), $connection->getConfig());
    $identity = Guard::identity($connection->getPdo(),$argv[1],$argv[2]);
    echo json_encode(['verified'=>true,'identity'=>$identity],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR,"DISPOSABLE_PREFLIGHT_DENIED; no schema or fixtures executed\n"); exit(1);
}
