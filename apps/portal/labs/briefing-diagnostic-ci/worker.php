<?php
declare(strict_types=1);
// Only launched by the qualified scope supervisor. No public entry point.
ini_set('display_errors','0');ini_set('log_errors','0');
require __DIR__.'/Trace.php';
use NativeBriefingFixture\Trace;
Trace::enter('AUTOLOAD');
try {
    require dirname(__DIR__,2).'/vendor/autoload.php';
    require __DIR__.'/CiGate.php';
    Trace::pass('PASS BRIEFING_AUTOLOAD_READY');
    Trace::enter('BOOTSTRAP');
    $app=require dirname(__DIR__,2).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Trace::pass('PASS BRIEFING_BOOTSTRAP_READY');
    Trace::enter('MAINTENANCE_CONFIG');
    $cfg=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>(int)getenv('PORTAL_NATIVE_ORDER_TEST_PORT'),
        'database'=>'postgres','username'=>'portal_native_test_bootstrap','password'=>'','charset'=>'utf8',
        'prefix'=>'','search_path'=>'public','sslmode'=>'disable'];
    config(['database.default'=>'pgsql','database.connections.pgsql'=>$cfg]);$app['db']->purge('pgsql');
    Trace::pass('PASS BRIEFING_MAINTENANCE_CONFIG_READY');
    Trace::enter('MAINTENANCE_GATE');
    $attestation=\NativeBriefingFixture\CiGate::beforeWrites('postgres');
    Trace::pass('PASS BRIEFING_MAINTENANCE_BEFORE_DDL');
    Trace::enter('CREATE_DATABASE');
    \NativeBriefingFixture\CiGate::sameConnection($app['db']->connection('pgsql')->getPdo(),$attestation['pdo']);
    $attestation['pdo']->exec('CREATE DATABASE portal_native_orders_ci');
    $app['db']->purge('pgsql');
    unset($attestation); // Release the already-used maintenance PDO before the test app starts.
    // runBare invokes the inherited Laravel setup/teardown, only this new test method.
    Trace::pass('PASS BRIEFING_DATABASE_CREATED');
    Trace::enter('TEST_BOOTSTRAP');
    require __DIR__.'/NativeBriefingPgTest.php';
    $test=new \Tests\Integration\NativeBriefingPgTest('test_private_durable_briefing_only');
    $test->runBare();
    Trace::enter('FINALIZE');
    Trace::pass('PASS BRIEFING_WORKER_COMPLETE');
} catch(\Throwable) {fwrite(STDERR,"BRIEFING_WORKER_REFUSED phase=".Trace::phase()."\n");exit(1);}
