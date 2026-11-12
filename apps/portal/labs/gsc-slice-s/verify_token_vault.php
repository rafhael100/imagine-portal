<?php
declare(strict_types=1);

$root=dirname(__DIR__,2).'/app/Domain/SeoReview/';
require_once $root.'GoogleSearchConsoleOAuthPreflight.php';
require_once $root.'GoogleSearchConsoleOAuthTokenResponsePolicy.php';
require_once $root.'GoogleSearchConsoleEncryptedTokenVault.php';

use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenResponsePolicy as Policy;
use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenVault as Vault;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_TOKEN_VAULT_LAB')!=='1'||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice S: synthetic vault lab only\n");exit(2);
}
$check=static function(bool $value):void{
    if(!$value)throw new RuntimeException();
};
try {
    $pdo=new PDO(getenv('PORTAL_GSC_LEDGER_DSN'),
        getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_token_candidates');
    $pdo->exec((string)file_get_contents(__DIR__.'/test-token-vault-schema.sql'));

    $now=1790000000;
    $key=random_bytes(32);
    $vault=new Vault($pdo,$key);
    $wrongKey=new Vault($pdo,random_bytes(32));
    $parser=new Policy();
    $access='synthetic-access-token-for-isolated-ci-only';
    $refresh='synthetic-refresh-token-for-isolated-ci-only';
    $body=[
        'access_token'=>$access,'refresh_token'=>$refresh,
        'expires_in'=>300,'scope'=>Pkce::READONLY_SCOPE,
        'token_type'=>'Bearer'
    ];
    $candidate=$parser->inspect(200,json_encode($body),$now);
    $check($candidate!==null);
    $tenant='imagine-demo';$actor='operator-123';
    $property=str_repeat('a',64);
    $other=str_repeat('b',64);
    $third=str_repeat('c',64);

    $check(!Vault::authorizesGoogleHttp()&&!Vault::authorizesTokenUse()
        &&!Vault::authorizesCustomerDisplay());
    $check($vault->storeSyntheticCandidate(
        $tenant,$actor,$property,$candidate,$now));
    $check(!$vault->storeSyntheticCandidate(
        $tenant,$actor,$property,$candidate,$now));
    $cipher=(string)$pdo->query('SELECT sealed_tokens FROM
        portal_gsc_oauth_token_candidates LIMIT 1')->fetchColumn();
    $check(str_starts_with($cipher,'v1.') &&
        !str_contains($cipher,$access) &&
        !str_contains($cipher,$refresh));
    $check($vault->inspectSyntheticCandidate(
        'other-demo',$actor,$property,$now+1)===null);
    $check($vault->inspectSyntheticCandidate(
        $tenant,'other-actor',$property,$now+1)===null);
    $check($vault->inspectSyntheticCandidate(
        $tenant,$actor,$other,$now+1)===null);
    $check($wrongKey->inspectSyntheticCandidate(
        $tenant,$actor,$property,$now+1)===null);
    $secret=$vault->inspectSyntheticCandidate(
        $tenant,$actor,$property,$now+1);
    $check($secret!==null);
    $safe=$secret->safeReview();
    $check($safe['scope']===Pkce::READONLY_SCOPE&&
        $safe['expiresAt']===$now+300&&
        $safe['containsRefreshToken']===true&&
        $safe['providerOriginVerified']===false&&
        $safe['tenantGrantVerified']===false&&
        $safe['tokenUseAuthorized']===false&&
        $safe['customerDisplayAuthorized']===false);
    $open=$secret->takeForTrustedBackend();
    $check($open['accessToken']===$access&&
        $open['refreshToken']===$refresh);
    foreach([
        json_encode($secret,JSON_THROW_ON_ERROR),
        print_r($secret,true),var_export($secret,true),
        json_encode($vault,JSON_THROW_ON_ERROR),
        print_r($vault,true),var_export($vault,true)
    ] as $dump){
        $check(!str_contains($dump,$access)&&
            !str_contains($dump,$refresh)&&
            !str_contains($dump,$key));
    }
    $check($vault->inspectSyntheticCandidate(
        $tenant,$actor,$property,$now+301)===null);

    // Ciphertext belonging to property A is never valid for property B.
    $check($vault->storeSyntheticCandidate(
        $tenant,$actor,$other,$candidate,$now));
    $modify=$pdo->prepare('UPDATE portal_gsc_oauth_token_candidates
        SET sealed_tokens=:cipher WHERE property_fingerprint=:property');
    $modify->execute(['cipher'=>$cipher,'property'=>$other]);
    $check($vault->inspectSyntheticCandidate(
        $tenant,$actor,$other,$now+1)===null);

    $check(!$vault->revokeSyntheticCandidate(
        'other-demo',$actor,$property,$now+1));
    $check($vault->revokeSyntheticCandidate(
        $tenant,$actor,$property,$now+1));
    $check(!$vault->revokeSyntheticCandidate(
        $tenant,$actor,$property,$now+2));
    $check($vault->inspectSyntheticCandidate(
        $tenant,$actor,$property,$now+2)===null);

    // A changed read-only scope is never accepted by PostgreSQL.
    try{
        $pdo->exec("UPDATE portal_gsc_oauth_token_candidates
            SET scope='https://www.googleapis.com/auth/webmasters'");
        throw new RuntimeException();
    }catch(PDOException){/* DB constraint rejected write scope. */}

    $check(!$vault->storeSyntheticCandidate(
        'bad tenant',$actor,$third,$candidate,$now));
    $check(!$vault->storeSyntheticCandidate(
        $tenant,$actor,str_repeat('F',64),$candidate,$now));
    $check(!$vault->storeSyntheticCandidate(
        $tenant,$actor,$third,$candidate,'1790000000'));
    $check(!$vault->storeSyntheticCandidate(
        $tenant,$actor,$third,$candidate,$now+301));
    $check($vault->storeSyntheticCandidate(
        $tenant,$actor,$third,$candidate,$now+1));
    $check($vault->inspectSyntheticCandidate(
        $tenant,$actor,$third,$now+2)!==null);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice S: encrypted token candidate contract refused\n");
    exit(1);
}finally{
    if(isset($pdo)&&$pdo instanceof PDO){
        try{$pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_token_candidates');}
        catch(Throwable){}
    }
}
echo "GSC Slice S: encrypted tokens isolated, revoked, no key or token exposure\n";
