<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthStateLedger.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthStateRetention.php';

use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthStateLedger as Ledger;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthStateRetention as Retention;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_RETENTION_LAB')!=='1'||
   getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice K: isolated PostgreSQL test only\n");
    exit(2);
}
$check=static function(bool $ok):void{if(!$ok)throw new RuntimeException();};
try{
    $pdo=new PDO(getenv('PORTAL_GSC_LEDGER_DSN'),
        getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_states');
    $pdo->exec((string)file_get_contents(dirname(__DIR__).'/gsc-slice-i/test-postgres-schema.sql'));

    $now=1790001000;
    $key=random_bytes(32);
    $pkce=new Pkce();
    $ledger=new Ledger($pdo,$key);
    $clean=new Retention($pdo);
    $context=['tenantRef'=>'imagine-demo','actorRef'=>'operator-123'];
    $config=['clientId'=>'123456-exampleclient.apps.googleusercontent.com',
        'registeredRedirectUri'=>'https://portal.example.com/integrations/google/search-console/callback'];
    $make=static function(int $issued) use ($pkce,$config,$context,$ledger,$check):array{
        $intent=$pkce->prepare($config,$context,$issued);
        $check(is_array($intent));
        $check($ledger->store($intent['serverOnly'],$issued));
        return $intent;
    };
    $expired=$make($now-601);
    $oldUsed=$make($now-100);
    $newUsed=$make($now-20);
    $active=$make($now);
    $check($ledger->consume(
        $oldUsed['publicAuthorization']['state'],$context,$now-98)!==null);
    $check($ledger->consume(
        $newUsed['publicAuthorization']['state'],$context,$now-19)!==null);

    $check(!Retention::authorizesTokenRevocation()&&
        !Retention::authorizesCustomerDisplay());
    $check($clean->purge($now,1)===1);
    $check($clean->purge($now,1)===1);
    $check($clean->purge($now,1)===0);
    $check($ledger->inspectPending(
        $expired['publicAuthorization']['state'],$context,$now)===null);
    $check($ledger->inspectPending(
        $active['publicAuthorization']['state'],$context,$now)!==null);
    $check($pdo->query(
        'SELECT COUNT(*) FROM portal_gsc_oauth_states'
    )->fetchColumn()==2);

    foreach([0,-1,501,1.5,'2',null] as $limit){
        $check($clean->purge($now,$limit)===null);
    }
    foreach([null,0,'1790001000',NAN,2200000601] as $badClock){
        $check($clean->purge($badClock,2)===null);
    }
    $check($clean->purge($now+42,1)===1); // recently consumed now aged >60s
    $check($clean->purge($now+42,1)===0);
    $check($ledger->inspectPending(
        $active['publicAuthorization']['state'],$context,$now+42)!==null);

    // The AEAD key is wrapped with SensitiveParameterValue, including for
    // object inspection; its raw bytes do not appear in dumps.
    foreach([
        json_encode($ledger,JSON_THROW_ON_ERROR),
        print_r($ledger,true),
        var_export($ledger,true)
    ] as $dump){
        $check(!str_contains($dump,$key));
    }
    $check($clean->purge($now+601,500)===1);
    $check($pdo->query(
        'SELECT COUNT(*) FROM portal_gsc_oauth_states'
    )->fetchColumn()==0);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice K: bounded retention contract refused\n");
    exit(1);
}finally{
    if(isset($pdo)&&$pdo instanceof PDO){
        try{$pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_states');}
        catch(Throwable){}
    }
}
echo "GSC Slice K: state retention bounded; secrets scrubbed after TTL or use\n";
