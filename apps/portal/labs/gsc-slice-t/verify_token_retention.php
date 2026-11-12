<?php
declare(strict_types=1);

$base=dirname(__DIR__,2).'/app/Domain/SeoReview/';
require_once $base.'GoogleSearchConsoleOAuthPreflight.php';
require_once $base.'GoogleSearchConsoleOAuthTokenResponsePolicy.php';
require_once $base.'GoogleSearchConsoleEncryptedTokenVault.php';
require_once $base.'GoogleSearchConsoleTokenCandidateRetention.php';

use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenResponsePolicy as Policy;
use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenVault as Vault;
use App\Domain\SeoReview\GoogleSearchConsoleTokenCandidateRetention as Retention;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_TOKEN_RETENTION_LAB')!=='1'||
   getenv('PORTAL_GSC_LEDGER_DSN')!==
    'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice T: isolated PostgreSQL only\n");exit(2);
}
$check=static function(bool $x):void{if(!$x)throw new RuntimeException();};
try{
    $pdo=new PDO(getenv('PORTAL_GSC_LEDGER_DSN'),
        getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_token_candidates');
    $pdo->exec((string)file_get_contents(
        dirname(__DIR__).'/gsc-slice-s/test-token-vault-schema.sql'
    ));

    $now=1790000000;
    $vault=new Vault($pdo,random_bytes(32));
    $policy=new Policy();
    $retention=new Retention($pdo);
    $tenant='imagine-demo';$actor='operator-123';
    $make=static function(string $property,int $issued)
        use($vault,$policy,$tenant,$actor,$check):void{
        $token=$policy->inspect(200,json_encode([
            'access_token'=>'synthetic-only-access-candidate-12345',
            'refresh_token'=>'synthetic-only-refresh-candidate-12345',
            'expires_in'=>300,'scope'=>Pkce::READONLY_SCOPE,
            'token_type'=>'Bearer'
        ],JSON_THROW_ON_ERROR),$issued);
        $check($token!==null);
        $check($vault->storeSyntheticCandidate(
            $tenant,$actor,$property,$token,$issued
        ));
    };
    $expired=str_repeat('a',64);
    $oldRevoked=str_repeat('b',64);
    $recentRevoked=str_repeat('c',64);
    $active=str_repeat('d',64);
    $make($expired,$now-601);
    $make($oldRevoked,$now-200);
    $make($recentRevoked,$now-100);
    $make($active,$now-50);
    $check($vault->revokeSyntheticCandidate(
        $tenant,$actor,$oldRevoked,$now-120));
    $check($vault->revokeSyntheticCandidate(
        $tenant,$actor,$recentRevoked,$now-20));
    $check($vault->inspectSyntheticCandidate(
        $tenant,$actor,$active,$now)!==null);
    $check(!Retention::authorizesGoogleRevocation()&&
        !Retention::authorizesTokenUse());

    foreach([
        [null,1],[0,1],['1790000000',1],
        [$now,0],[$now,501],[$now,-1],[$now,'2']
    ] as [$time,$limit]){
        $check($retention->purge($time,$limit)===null);
    }
    $check($retention->purge($now,1)===1);
    $check($retention->purge($now,1)===1);
    $check($retention->purge($now,1)===0);
    $check($pdo->query(
        'SELECT COUNT(*) FROM portal_gsc_oauth_token_candidates'
    )->fetchColumn()==2);
    $check($vault->inspectSyntheticCandidate(
        $tenant,$actor,$active,$now)!==null);
    $check($retention->purge($now+41,1)===1);
    $check($retention->purge($now+41,1)===0);
    $check($vault->inspectSyntheticCandidate(
        $tenant,$actor,$active,$now+41)!==null);
    $check($retention->purge($now+251,500)===1);
    $check($pdo->query(
        'SELECT COUNT(*) FROM portal_gsc_oauth_token_candidates'
    )->fetchColumn()==0);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice T: encrypted candidate retention contract refused\n");
    exit(1);
}finally{
    if(isset($pdo)&&$pdo instanceof PDO){
        try{$pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_token_candidates');}
        catch(Throwable){}
    }
}
echo "GSC Slice T: expired/revoked encrypted candidate cleanup bounded and complete\n";
