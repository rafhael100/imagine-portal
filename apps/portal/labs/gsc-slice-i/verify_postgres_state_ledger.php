<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthCallbackPolicy.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthStateLedger.php';
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthCallbackPolicy as Callback;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthStateLedger as Ledger;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_POSTGRES_LAB')!=='1'||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice I: isolated PostgreSQL fixture only\n");
    exit(2);
}
$assert=static function(bool $ok):void{
    if(!$ok)throw new RuntimeException();
};
try {
    $dsn=getenv('PORTAL_GSC_LEDGER_DSN');
    $user=getenv('PORTAL_GSC_LEDGER_USER');
    $password=getenv('PORTAL_GSC_LEDGER_PASSWORD');
    $options=[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ];
    $pdo=new PDO($dsn,$user,$password,$options);
    $pdo2=new PDO($dsn,$user,$password,$options);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_states');
    $sql=file_get_contents(__DIR__.'/test-postgres-schema.sql');
    if(!is_string($sql))throw new RuntimeException();
    $pdo->exec($sql);
    $key=random_bytes(32);
    $ledger=new Ledger($pdo,$key);
    $otherWorker=new Ledger($pdo2,$key);
    $wrongKey=new Ledger($pdo2,random_bytes(32));
    $pkce=new Pkce();
    $callback=new Callback($pkce);
    $context=['tenantRef'=>'imagine-demo','actorRef'=>'operator-123'];
    $config=[
        'clientId'=>'123456-exampleclient.apps.googleusercontent.com',
        'registeredRedirectUri'=>'https://portal.example.com/integrations/google/search-console/callback'
    ];
    $now=1790000000;
    $intent=$pkce->prepare($config,$context,$now);
    $assert(is_array($intent)&&!Ledger::authorizesOutboundGoogleHttp()
        &&!Ledger::authorizesCustomerDisplay());
    $state=$intent['publicAuthorization']['state'];
    $record=$intent['serverOnly'];
    $assert($ledger->store($record,$now));
    $assert(!$ledger->store($record,$now));
    $sealed=(string)$pdo->query(
        'SELECT sealed_verifier FROM portal_gsc_oauth_states LIMIT 1'
    )->fetchColumn();
    $assert(str_starts_with($sealed,'v1.')&&
        !str_contains($sealed,$record['codeVerifier'])&&
        !str_contains($sealed,$state));
    $peek=$ledger->inspectPending($state,$context,$now+1);
    $assert(is_array($peek)&&$peek===$record);
    $inspect=$callback->inspect([
        'state'=>$state,'code'=>'4/0AbCdEfGhIJKL-opaque-code_TEST'
    ],$peek,$context,$now+1);
    $assert(is_array($inspect)&&
        $inspect['decision']==='CODE_RECEIVED_REQUIRES_ATOMIC_STATE_CONSUMPTION'&&
        $inspect['canExchangeTokens']===false);
    $assert($wrongKey->inspectPending($state,$context,$now+1)===null);
    $assert($ledger->inspectPending($state,[
        'tenantRef'=>'other-demo','actorRef'=>'operator-123'
    ],$now+1)===null);
    $assert($ledger->inspectPending($state,$context,$now+601)===null);

    // Workers use independent database connections; exactly one wins the
    // conditional UPDATE...RETURNING, and the second cannot replay.
    $consumed=$otherWorker->consume($state,$context,$now+2);
    $assert($consumed!==null&&
        $consumed->revealForTrustedBackend()===$record['codeVerifier']);
    $assert(json_encode($consumed,JSON_THROW_ON_ERROR)==='{}');
    $assert(!str_contains(var_export($consumed,true),$record['codeVerifier']));
    $assert($ledger->consume($state,$context,$now+3)===null);
    $assert($ledger->inspectPending($state,$context,$now+3)===null);
    $assert($ledger->consume($state,[
        'tenantRef'=>'other-demo','actorRef'=>'operator-123'
    ],$now+3)===null);

    // Tampering with the ciphertext cannot grant tokens. Wrong keys or
    // swapped rows do NOT result in a reusable state.
    $second=$pkce->prepare($config,$context,$now);
    $assert(is_array($second));
    $state2=$second['publicAuthorization']['state'];
    $assert($ledger->store($second['serverOnly'],$now));
    $assert($wrongKey->consume($state2,$context,$now+1)===null);
    $assert($ledger->consume($state2,$context,$now+1)===null);

    $third=$pkce->prepare($config,$context,$now);
    $fourth=$pkce->prepare($config,$context,$now);
    $assert(is_array($third)&&is_array($fourth));
    $assert($ledger->store($third['serverOnly'],$now));
    $assert($ledger->store($fourth['serverOnly'],$now));
    $hash3=$third['serverOnly']['stateSha256'];
    $hash4=$fourth['serverOnly']['stateSha256'];
    $stmt=$pdo->prepare('SELECT sealed_verifier FROM portal_gsc_oauth_states
        WHERE state_hash=:hash');
    $stmt->execute(['hash'=>$hash4]);
    $swapped=$stmt->fetchColumn();
    $assert(is_string($swapped));
    $modify=$pdo->prepare('UPDATE portal_gsc_oauth_states
        SET sealed_verifier=:cipher WHERE state_hash=:hash');
    $modify->execute(['cipher'=>$swapped,'hash'=>$hash3]);
    $assert($ledger->inspectPending(
        $third['publicAuthorization']['state'],$context,$now+1
    )===null);
    $assert($ledger->consume(
        $third['publicAuthorization']['state'],$context,$now+1
    )===null);
    $assert($ledger->consume(
        $third['publicAuthorization']['state'],$context,$now+2
    )===null);
    $assert($ledger->consume(
        $fourth['publicAuthorization']['state'],$context,$now+2
    )!==null);

    // Reject forged records, ambiguous clocks and direct authorization.
    $assert(!$ledger->store([...$record,'oauthVerified'=>true],$now));
    $assert(!$ledger->store([...$record,'consumed'=>true],$now));
    $assert(!$ledger->store($record,$now+1));
    $assert($ledger->consume('bad',$context,$now+1)===null);
    try {
        new Ledger($pdo,'not-a-valid-key');
        throw new RuntimeException();
    } catch(InvalidArgumentException) {
        // Expected.
    }
} catch(Throwable) {
    fwrite(STDERR,"GSC Slice I: PostgreSQL state ledger contract refused\n");
    exit(1);
} finally {
    if(isset($pdo)&&$pdo instanceof PDO){
        try {$pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_states');}
        catch(Throwable){}
    }
}
echo "GSC Slice I: encrypted state stored; one-use CAS, cross-tenant and tamper denial passed\n";
