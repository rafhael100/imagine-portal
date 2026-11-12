<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsolePropertyIdentifier.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleRecordedPropertyGrant.php';

use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier as Property;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleRecordedPropertyGrant as Grant;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_GRANT_LAB')!=='1'||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice O: disposable PostgreSQL only\n");exit(2);
}
$check=static function(bool $ok):void{if(!$ok)throw new RuntimeException();};
try{
    $pdo=new PDO(getenv('PORTAL_GSC_LEDGER_DSN'),
        getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_property_grants');
    $pdo->exec((string)file_get_contents(__DIR__.'/test-grants-schema.sql'));

    $guard=new Grant($pdo);
    $tenant='imagine-demo';$actor='operator-123';
    $site='sc-domain:example.com';
    $hash=Property::fingerprint($site);
    $check(is_string($hash)&&strlen($hash)===64);
    $now=1790000000;
    $insert=$pdo->prepare('INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
         verified_at,expires_at)
        VALUES (:tenant,:actor,:fingerprint,:scope,:status,:verified,:expires)');
    $insert->execute([
        'tenant'=>$tenant,'actor'=>$actor,'fingerprint'=>$hash,
        'scope'=>Pkce::READONLY_SCOPE,'status'=>'active',
        'verified'=>$now-60,'expires'=>$now+600
    ]);
    $result=$guard->inspect($tenant,$actor,$site,$now);
    $check(is_array($result)&&
        $result['recordedGrantFresh']===true&&
        $result['propertyFingerprint']===$hash&&
        $result['recordedScope']===Pkce::READONLY_SCOPE&&
        $result['googleApiCheckedNow']===false&&
        $result['sessionMfaVerified']===false&&
        $result['tenantMembershipVerified']===false&&
        $result['propertyAuthorizationVerified']===false&&
        $result['customerDisplayAuthorized']===false&&
        $result['publicationAuthorized']===false&&
        $result['networkPerformed']===false);
    $check(!Grant::authorizesCustomerDisplay()&&
        !Grant::authorizesGoogleRequest()&&
        !Grant::authorizesGrantProvisioning());
    $check(!str_contains(json_encode($result),$site));

    foreach([
        [$tenant,'other-actor',$site,$now],
        ['other-tenant',$actor,$site,$now],
        [$tenant,$actor,'https://example.com/',$now],
        [$tenant,$actor,'sc-domain:other.com',$now],
        [$tenant,$actor,'sc-domain:EXAMPLE.com',$now],
        [$tenant,$actor,'sc-domain:example.com?token=1',$now],
        [$tenant,$actor,'sc-domain:example.com/',$now],
        [$tenant,$actor,$site,$now+601],
        [$tenant,$actor,$site,$now-601],
        [$tenant,$actor,$site,'1790000000'],
        [$tenant,$actor,';DROP TABLE portal_gsc_property_grants;',$now],
    ] as [$t,$a,$property,$clock]){
        $check($guard->inspect($t,$a,$property,$clock)===null);
    }
    $update=$pdo->prepare('UPDATE portal_gsc_property_grants
        SET verified_at=:verified WHERE property_fingerprint=:hash');
    $update->execute(['verified'=>$now-901,'hash'=>$hash]);
    $check($guard->inspect($tenant,$actor,$site,$now)===null);
    $update->execute(['verified'=>$now-60,'hash'=>$hash]);

    $pdo->exec("UPDATE portal_gsc_property_grants SET
        grant_status='revoked', revoked_at=1790000000");
    $check($guard->inspect($tenant,$actor,$site,$now)===null);
    $pdo->exec("UPDATE portal_gsc_property_grants SET
        grant_status='active', revoked_at=NULL");
    $check($guard->inspect($tenant,$actor,$site,$now)!==null);

    try{
        $pdo->exec("UPDATE portal_gsc_property_grants SET
            scope='https://www.googleapis.com/auth/webmasters'");
        throw new RuntimeException();
    }catch(PDOException){
        // Database refuses write-capable scopes.
    }
    $check($guard->inspect($tenant,$actor,$site,$now)!==null);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice O: recorded property grant contract refused\n");exit(1);
}finally{
    if(isset($pdo)&&$pdo instanceof PDO){
        try{$pdo->exec('DROP TABLE IF EXISTS portal_gsc_property_grants');}
        catch(Throwable){}
    }
}
echo "GSC Slice O: scoped readonly property record accepted; no customer authorization\n";
