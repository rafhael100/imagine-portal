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
    getenv('PORTAL_GSC_KEY_ROTATION_LAB')!=='1'||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
       'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice U: isolated PostgreSQL key rotation only\n");
    exit(2);
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

    $oldKey=random_bytes(32);$newKey=random_bytes(32);
    $oldVault=new Vault($pdo,$oldKey,1);
    $rotating=new Vault($pdo,$newKey,2,$oldKey);
    $newVault=new Vault($pdo,$newKey,2);
    $wrongVault=new Vault($pdo,random_bytes(32),2,$oldKey);
    $now=1790000000;
    $tenant='imagine-demo';$actor='operator-123';
    $propA=str_repeat('a',64);
    $propB=str_repeat('b',64);
    $propC=str_repeat('c',64);
    $candidate=(new Policy())->inspect(200,json_encode([
        'access_token'=>'synthetic-rotation-access-only',
        'refresh_token'=>'synthetic-rotation-refresh-only',
        'expires_in'=>300,'scope'=>Pkce::READONLY_SCOPE,
        'token_type'=>'Bearer'
    ],JSON_THROW_ON_ERROR),$now);
    $check($candidate!==null);
    $check($oldVault->storeSyntheticCandidate(
        $tenant,$actor,$propA,$candidate,$now));
    $check($oldVault->storeSyntheticCandidate(
        $tenant,$actor,$propB,$candidate,$now));
    $check($rotating->inspectSyntheticCandidate(
        $tenant,$actor,$propA,$now+1)!==null);
    $check($newVault->inspectSyntheticCandidate(
        $tenant,$actor,$propA,$now+1)===null);
    $check(!$oldVault->rotateSyntheticCandidate(
        $tenant,$actor,$propA,$now+1));
    $check(!$newVault->rotateSyntheticCandidate(
        $tenant,$actor,$propA,$now+1));
    $check(!$rotating->rotateSyntheticCandidate(
        'other-demo',$actor,$propA,$now+1));
    $check($rotating->rotateSyntheticCandidate(
        $tenant,$actor,$propA,$now+1));
    $check(!$rotating->rotateSyntheticCandidate(
        $tenant,$actor,$propA,$now+1));
    $check($newVault->inspectSyntheticCandidate(
        $tenant,$actor,$propA,$now+1)!==null);
    $check($oldVault->inspectSyntheticCandidate(
        $tenant,$actor,$propA,$now+1)===null);
    $check($wrongVault->inspectSyntheticCandidate(
        $tenant,$actor,$propA,$now+1)===null);
    $check($newVault->inspectSyntheticCandidate(
        $tenant,$actor,$propB,$now+1)===null);
    $check($rotating->inspectSyntheticCandidate(
        $tenant,$actor,$propB,$now+1)!==null);
    $check($rotating->rotateSyntheticCandidate(
        $tenant,$actor,$propB,$now+1));
    $check($newVault->inspectSyntheticCandidate(
        $tenant,$actor,$propB,$now+1)!==null);
    $check($rotating->storeSyntheticCandidate(
        $tenant,$actor,$propC,$candidate,$now));
    $check($newVault->inspectSyntheticCandidate(
        $tenant,$actor,$propC,$now+1)!==null);
    $check($oldVault->inspectSyntheticCandidate(
        $tenant,$actor,$propC,$now+1)===null);

    $stmt=$pdo->query('SELECT sealed_tokens
        FROM portal_gsc_oauth_token_candidates');
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $sealed){
        $check(is_string($sealed)&&str_starts_with($sealed,'v2.'));
        $check(!str_contains($sealed,'synthetic-rotation-access-only'));
    }
    foreach([
        json_encode($rotating,JSON_THROW_ON_ERROR),
        print_r($rotating,true),
        var_export($rotating,true)
    ] as $dump){
        $check(!str_contains($dump,$oldKey)
            &&!str_contains($dump,$newKey)
            &&!str_contains($dump,'synthetic-rotation-refresh-only'));
    }
    $check($rotating->revokeSyntheticCandidate(
        $tenant,$actor,$propB,$now+2));
    $check(!$rotating->rotateSyntheticCandidate(
        $tenant,$actor,$propB,$now+3));
    $check(!$rotating->rotateSyntheticCandidate(
        $tenant,$actor,$propA,$now+301));

    try{
        new Vault($pdo,$newKey,3,$oldKey);
        throw new RuntimeException();
    }catch(InvalidArgumentException){}
    try{
        new Vault($pdo,$newKey,2,$newKey);
        throw new RuntimeException();
    }catch(InvalidArgumentException){}
}catch(Throwable){
    fwrite(STDERR,"GSC Slice U: key rotation contract refused\n");
    exit(1);
}finally{
    if(isset($pdo)&&$pdo instanceof PDO){
        try{$pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_token_candidates');}
        catch(Throwable){}
    }
}
echo "GSC Slice U: versioned AEAD rotation passed without token/key disclosure\n";
