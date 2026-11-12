<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsolePropertyIdentifier.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleRecordedPropertyGrant.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsolePropertyGrantRevocation.php';

use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier as Property;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Preflight;
use App\Domain\SeoReview\GoogleSearchConsoleRecordedPropertyGrant as Grant;
use App\Domain\SeoReview\GoogleSearchConsolePropertyGrantRevocation as Revocation;

if (PHP_SAPI!=='cli' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_REVOKE_LAB')!=='1' ||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test') {
    fwrite(STDERR,"GSC Slice P: synthetic PostgreSQL only\n"); exit(2);
}
$check=static function(bool $condition):void{
    if(!$condition)throw new RuntimeException();
};
try {
    $pdo=new PDO(getenv('PORTAL_GSC_LEDGER_DSN'),
        getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_property_grants');
    $pdo->exec((string)file_get_contents(
        dirname(__DIR__).'/gsc-slice-o/test-grants-schema.sql'
    ));
    $now=1790000000;
    $tenant='imagine-demo';
    $actor='operator-123';
    $other='other-actor';
    $otherTenant='second-tenant';
    $siteA='sc-domain:example.com';
    $siteB='sc-domain:example.org';
    $siteC='sc-domain:example.net';
    $siteD='sc-domain:other.example';
    $newRecord=static function(string $t,string $a,string $site,int $verified)
        use ($pdo,$now,$check):void {
        $hash=Property::fingerprint($site);
        $check(is_string($hash));
        $stmt=$pdo->prepare('INSERT INTO portal_gsc_property_grants
            (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
             verified_at,expires_at)
            VALUES (:tenant,:actor,:fingerprint,:scope,:status,:verified,:expires)');
        $stmt->execute([
            'tenant'=>$t,'actor'=>$a,'fingerprint'=>$hash,
            'scope'=>Preflight::READONLY_SCOPE,
            'status'=>'active','verified'=>$verified,'expires'=>$now+600
        ]);
    };
    $newRecord($tenant,$actor,$siteA,$now-30);
    $newRecord($tenant,$actor,$siteB,$now-30);
    $newRecord($tenant,$actor,$siteC,$now-30);
    $newRecord($tenant,$other,$siteD,$now-30);
    $newRecord($otherTenant,$actor,$siteD,$now-30);
    $view=new Grant($pdo);
    $revocation=new Revocation($pdo);

    $check(!Revocation::authorizesGoogleHttp() &&
        !Revocation::authorizesCustomerDisplay() &&
        !Revocation::authorizesGrantProvisioning());

    $check($view->inspect($tenant,$actor,$siteA,$now)!==null);
    $check(!$revocation->revokeExact('bad tenant',$actor,$siteA,$now));
    $check(!$revocation->revokeExact($tenant,'other-actor',$siteA,$now));
    $check(!$revocation->revokeExact($otherTenant,$actor,$siteA,$now));
    $check(!$revocation->revokeExact($tenant,$actor,'https://example.com/',$now));
    $check(!$revocation->revokeExact($tenant,$actor,$siteA,$now-601));
    $check(!$revocation->revokeExact($tenant,$actor,$siteA,'1790000000'));

    $check($revocation->revokeExact($tenant,$actor,$siteA,$now));
    $check(!$revocation->revokeExact($tenant,$actor,$siteA,$now));
    $check($view->inspect($tenant,$actor,$siteA,$now)===null);
    $check($view->inspect($tenant,$actor,$siteB,$now)!==null);
    $check($view->inspect($tenant,$other,$siteD,$now)!==null);
    $check($view->inspect($otherTenant,$actor,$siteD,$now)!==null);

    foreach([0,-1,251,'2',null,1.5] as $limit) {
        $check($revocation->revokeActorBatch($tenant,$actor,$now,$limit)===null);
    }
    $check($revocation->revokeActorBatch($tenant,$actor,$now,1)===1);
    $check($revocation->revokeActorBatch($tenant,$actor,$now,1)===1);
    $check($revocation->revokeActorBatch($tenant,$actor,$now,1)===0);
    $check($view->inspect($tenant,$actor,$siteB,$now)===null);
    $check($view->inspect($tenant,$actor,$siteC,$now)===null);
    $check($view->inspect($tenant,$other,$siteD,$now)!==null);
    $check($view->inspect($otherTenant,$actor,$siteD,$now)!==null);
    $check($revocation->revokeActorBatch($tenant,$other,$now,250)===1);
    $check($view->inspect($tenant,$other,$siteD,$now)===null);
    $check($view->inspect($otherTenant,$actor,$siteD,$now)!==null);

    // Independently verified future-looking record cannot be revoked with
    // an earlier clock that violates the table's timestamp constraint.
    $future='sc-domain:future.example';
    $newRecord($otherTenant,$actor,$future,$now+30);
    $check(!$revocation->revokeExact($otherTenant,$actor,$future,$now));
    $check($revocation->revokeExact($otherTenant,$actor,$future,$now+31));
    $check($revocation->revokeActorBatch($otherTenant,$actor,$now+31,250)===1);
    $check($pdo->query("SELECT COUNT(*) FROM portal_gsc_property_grants
        WHERE grant_status='active'")->fetchColumn()==0);
} catch(Throwable) {
    fwrite(STDERR,"GSC Slice P: revocation contract refused\n");exit(1);
} finally {
    if (isset($pdo)&&$pdo instanceof PDO) {
        try {$pdo->exec('DROP TABLE IF EXISTS portal_gsc_property_grants');}
        catch(Throwable) {}
    }
}
echo "GSC Slice P: exact and bounded grant revocation; tenant isolation passed\n";
