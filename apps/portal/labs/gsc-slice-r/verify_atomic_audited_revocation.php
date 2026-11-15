<?php
declare(strict_types=1);

$root=dirname(__DIR__,2).'/app/Domain/SeoReview/';
foreach ([
    'GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsoleRecordedPropertyGrant.php',
    'GoogleSearchConsolePropertyGrantRevocation.php',
    'GoogleSearchConsoleSecurityEventAudit.php',
    'GoogleSearchConsoleAuditedGrantRevocation.php'
] as $file)require_once $root.$file;

use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier as Property;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleRecordedPropertyGrant as View;
use App\Domain\SeoReview\GoogleSearchConsoleAuditedGrantRevocation as Revoker;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_AUDITED_REVOKE_LAB')!=='1'||
   getenv('PORTAL_GSC_LEDGER_DSN')!==
      'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice R: disposable PostgreSQL only\n");exit(2);
}
$check=static function(bool $valid):void{
    if(!$valid)throw new RuntimeException();
};
try {
    $pdo=new PDO(getenv('PORTAL_GSC_LEDGER_DSN'),
        getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_property_grants');
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_security_events');
    $pdo->exec((string)file_get_contents(
        dirname(__DIR__).'/gsc-slice-o/test-grants-schema.sql'
    ));
    $schema=(string)file_get_contents(
        dirname(__DIR__).'/gsc-slice-q/test-audit-schema.sql'
    );
    $pdo->exec($schema);
    $t='imagine-demo';$a='operator-123';$t2='separate-demo';
    $now=1790000000;
    $put=static function(string $tenant,string $actor,string $site)
        use ($pdo,$now):void {
        $stmt=$pdo->prepare('INSERT INTO portal_gsc_property_grants
          (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
           verified_at,expires_at)
          VALUES (:tenant,:actor,:property,:scope,:status,:issued,:expires)');
        $stmt->execute([
            'tenant'=>$tenant,'actor'=>$actor,
            'property'=>Property::fingerprint($site),
            'scope'=>Pkce::READONLY_SCOPE,'status'=>'active',
            'issued'=>$now-20,'expires'=>$now+600
        ]);
    };
    $site1='sc-domain:example.com';
    $site2='sc-domain:example.net';
    $site3='sc-domain:example.org';
    $site4='sc-domain:other.example';
    $put($t,$a,$site1);$put($t,$a,$site2);
    $put($t,$a,$site3);$put($t2,$a,$site4);
    $view=new View($pdo);$service=new Revoker($pdo);
    $eventCount=static function()use($pdo):int{
        return (int)$pdo->query(
            'SELECT COUNT(*) FROM portal_gsc_security_events'
        )->fetchColumn();
    };

    $check(!$service::authorizesCustomerDisplay()&&
        !$service::authorizesGoogleHttp());
    $check(!$service->revokeExact($t2,$a,$site1,$now));
    $check(!$service->revokeExact($t,$a,'https://example.com/',$now));
    $check($eventCount()===0);
    $check($service->revokeExact($t,$a,$site1,$now));
    $check(!$service->revokeExact($t,$a,$site1,$now));
    $check($view->inspect($t,$a,$site1,$now)===null);
    $check($eventCount()===1);
    $stmt=$pdo->query('SELECT event_code,tenant_ref,actor_ref,
        property_fingerprint FROM portal_gsc_security_events');
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    $check(is_array($row)&&
        $row['event_code']==='property_grant_revoked'&&
        $row['tenant_ref']===$t&&$row['actor_ref']===$a&&
        $row['property_fingerprint']===Property::fingerprint($site1));

    $check($service->revokeActorBatch($t,$a,$now,1)===1);
    $check($service->revokeActorBatch($t,$a,$now,1)===1);
    $check($service->revokeActorBatch($t,$a,$now,1)===0);
    $check($eventCount()===3);
    $check($view->inspect($t,$a,$site2,$now)===null);
    $check($view->inspect($t,$a,$site3,$now)===null);
    $check($view->inspect($t2,$a,$site4,$now)!==null);
    $check($service->revokeActorBatch($t,$a,$now,0)===null);
    $check($eventCount()===3);

    // Audit failure MUST roll back the grant mutation in the SAME DB tx.
    $put($t,$a,'sc-domain:rollback.example');
    $pdo->exec('DROP TABLE portal_gsc_security_events');
    $check(!$service->revokeExact(
        $t,$a,'sc-domain:rollback.example',$now
    ));
    $check($view->inspect($t,$a,'sc-domain:rollback.example',$now)!==null);
    $pdo->exec($schema);
    $check($service->revokeExact(
        $t,$a,'sc-domain:rollback.example',$now
    ));
    $check($eventCount()===1);
    $check($view->inspect($t,$a,'sc-domain:rollback.example',$now)===null);

    $put($t,$a,'sc-domain:inflight.example');
    $pdo->beginTransaction();
    $check(!$service->revokeExact(
        $t,$a,'sc-domain:inflight.example',$now
    ));
    $check($service->revokeActorBatch($t,$a,$now,1)===null);
    $pdo->rollBack();
    $check($view->inspect($t,$a,'sc-domain:inflight.example',$now)!==null);
    $check($eventCount()===1);
} catch(Throwable) {
    fwrite(STDERR,"GSC Slice R: atomic audit/revoke contract refused\n");
    exit(1);
} finally {
    if(isset($pdo)&&$pdo instanceof PDO){
        try {
            if($pdo->inTransaction())$pdo->rollBack();
            $pdo->exec('DROP TABLE IF EXISTS portal_gsc_property_grants');
            $pdo->exec('DROP TABLE IF EXISTS portal_gsc_security_events');
        }catch(Throwable){}
    }
}
echo "GSC Slice R: grant mutation and metadata audit commit or rollback together\n";
