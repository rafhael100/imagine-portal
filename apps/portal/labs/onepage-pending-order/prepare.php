<?php
/** Preparation prefix reused from #922, ending before buyer fixtures/tests. No startup here. */
declare(strict_types=1);
require __DIR__.'/ProcessPreflight.php';
App\Labs\OnepagePendingOrder\ProcessPreflight::assertClean();
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../onepage-buyer/Guard.php';
require __DIR__.'/../onepage-buyer/BuyerQuoteAdapter.php';
use App\Labs\OnepageBuyer\Guard;
use App\Labs\OnepageBuyer\BuyerQuoteAdapter;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\Artisan;

$checks=[];
function checkLab(bool $ok,string $name):void {
    global $checks;
    if(!$ok)throw new RuntimeException('CHECK_FAILED: '.$name);
    $checks[]=$name;
}
function requestLab(?string $reference,string $phase='business'):Request {
    $request=Request::create('/not-registered','POST');
    $session=new Store('onepage-lab',new ArraySessionHandler(120));$session->start();
    if($reference!==null)$session->put('portal.browser_auth',['phase'=>$phase,'reference'=>$reference]);
    $request->setLaravelSession($session);return $request;
}
try {
    if($argc!==3||getenv('ONEPAGE_BUYER_PG_LAB')!=='DISPOSABLE_ONLY')throw new RuntimeException('LAB_OPT_IN_REQUIRED');
    Guard::offline();
    $app=require __DIR__.'/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $proof=bin2hex(random_bytes(32));$key=random_bytes(32);$pepper=bin2hex(random_bytes(32));
    $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $password=PortalPasswordCredential::makeHash('synthetic-password-only');
    $sealed=PortalTotpSecretCipher::seal($secret,$key);
    Guard::fixtures($password,$sealed,$key,$secret,'synthetic-password-only');
    // Guard precedes connection creation. Cache/env overrides are inspected as loaded.
    $verified=function(string $database)use($app,$argv){
        Guard::configuration($app->environment(),config('database.default'),config('database.connections.pgsql'),$database);
        $db=$app['db']->connection('pgsql');
        Guard::configuration($app->environment(),config('database.default'),$db->getConfig(),$database);
        $pdo=$db->getPdo();
        $identity=Guard::identity($pdo,$argv[1],$argv[2],$database);
        $configDigest=hash('sha256',serialize(config('database.connections.pgsql')));
        $db->setReconnector(static function(){throw new RuntimeException('RECONNECTION_FORBIDDEN');});
        $barrier=function()use($app,$db,$pdo,$database,$identity,$configDigest,$argv){
            Guard::configuration($app->environment(),config('database.default'),$db->getConfig(),$database);
            if($pdo!==$db->getPdo() || hash('sha256',serialize(config('database.connections.pgsql')))!==$configDigest
                || Guard::identity($pdo,$argv[1],$argv[2],$database)!==$identity)
                throw new RuntimeException('PREPARATION_IDENTITY_CHANGED');
        };
        $db->beforeExecuting(static function()use($barrier){$barrier();});
        return [$db,$pdo,$barrier,$identity];
    };
    // Maintenance DB is verified too: CREATE DATABASE is not an unchecked setup step.
    [$maintenance]=$verified('postgres');
    echo "PASS ORDER_MAINTENANCE_PREFLIGHT_BEFORE_WRITES\n";
    if($maintenance->selectOne("SELECT 1 FROM pg_database WHERE datname='portal_onepage_buyer_ci'"))
        throw new RuntimeException('FRESH_DATABASE_REQUIRED');
    $maintenance->statement('CREATE DATABASE portal_onepage_buyer_ci');
    $app['db']->purge('pgsql');config(['database.connections.pgsql.database'=>'portal_onepage_buyer_ci']);
    [$db,$bootstrap,$barrier,$identity]=$verified('portal_onepage_buyer_ci');
    echo "PASS ORDER_BASE_IDENTITY_BEFORE_SCHEMA\n";
    checkLab((int)$db->selectOne("SELECT count(*) n FROM information_schema.tables WHERE table_schema='public'")->n===0,'fresh empty database');
    $migrations=[
        '2026_11_09_000002_create_portal_identity_foundation.php',
        '2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php',
        '2026_11_10_000004_add_org_revision_to_portal_sessions.php',
        '2026_11_10_000005_create_portal_auth_throttle_buckets.php',
        '2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php',
        '2026_11_11_000012_fence_portal_membership_revision.php',
        '2026_11_11_000013_fence_portal_organization_revision.php',
        '2026_11_11_000014_guard_portal_session_issuance.php',
        '2026_11_12_000016_fence_portal_identity_revision.php',
    ];
    foreach($migrations as $file){
        $barrier();
        if(Artisan::call('migrate',['--path'=>'database/migrations/'.$file,'--force'=>true,'--no-interaction'=>true])!==0)
            throw new RuntimeException('MIGRATION_FAILED: '.$file);
        $barrier();
    }
    foreach(['database/security/portal_browser_auth.sql','database/security/portal_browser_principal_candidate.sql','labs/onepage-buyer/store.sql'] as $file)
        $db->unprepared(file_get_contents(base_path($file)));
    // Only synthetic roles in this verified fresh cluster; cluster-local trust auth.
    $db->statement('ALTER ROLE portal_browser_runtime LOGIN');
    $db->statement('ALTER ROLE onepage_buyer_runtime LOGIN');
    $db->insert('INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)',[$proof]);
    echo "PASS ORDER_BASE_PREPARATION_ATTESTED\n";
} catch(Throwable $e) {
    fwrite(STDERR, 'ORDER_PREPARATION_FAILED: '.($e instanceof PDOException || $e instanceof Illuminate\Database\QueryException ? 'SQL_FAILURE' : $e->getMessage()).PHP_EOL);exit(1);
}
