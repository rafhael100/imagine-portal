<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleSecurityEventAudit.php';
use App\Domain\SeoReview\GoogleSearchConsoleSecurityEventAudit as Audit;

if (PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_AUDIT_LAB')!=='1'||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test') {
    fwrite(STDERR,"GSC Slice Q: disposable audit-only PostgreSQL\n");exit(2);
}
$check=static function(bool $good):void{
    if(!$good)throw new RuntimeException();
};
try {
    $pdo=new PDO(getenv('PORTAL_GSC_LEDGER_DSN'),
        getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_security_events');
    $pdo->exec((string)file_get_contents(__DIR__.'/test-audit-schema.sql'));
    $audit=new Audit($pdo);
    $now=1790000000;
    $tenant='imagine-demo';
    $actor='operator-123';
    $property=str_repeat('a',64);
    $check(!$audit::authorizesCustomerDisplay() &&
        !$audit::authorizesTokenPersistence() &&
        !$audit::authorizesGrantProvisioning());

    $check($audit->record('oauth_pkce_prepared',$tenant,$actor,null,$now));
    $check($audit->record('property_grant_revoked',
        $tenant,$actor,$property,$now-10*86400));
    $check($audit->record('oauth_state_consumed',
        $tenant,$actor,$property,$now-8*86400));
    $check($audit->record('oauth_callback_denied',
        $tenant,$actor,null,$now-2*86400));
    $check($pdo->query('SELECT COUNT(*) FROM portal_gsc_security_events')
        ->fetchColumn()==4);

    // Only enumerated event codes and fixed, pseudonymous metadata fields
    // exist in the table. No raw code/token/query/body/url can be stored.
    $cols=$pdo->query("SELECT column_name FROM information_schema.columns
        WHERE table_name='portal_gsc_security_events'
        ORDER BY column_name")->fetchAll(PDO::FETCH_COLUMN);
    sort($cols);
    $check($cols===[
        'actor_ref','event_at','event_code','event_id',
        'property_fingerprint','tenant_ref'
    ]);
    foreach([
        ['unknown_event',$tenant,$actor,null,$now],
        ['oauth_pkce_prepared','bad tenant',$actor,null,$now],
        ['oauth_pkce_prepared',$tenant,'actor@example.com',null,$now],
        ['oauth_callback_denied',$tenant,$actor,'SECRET-TOKEN',$now],
        ['oauth_callback_denied',$tenant,$actor,str_repeat('F',64),$now],
        ['oauth_callback_denied',$tenant,$actor,$property,'1790000000'],
        ['oauth_callback_denied',$tenant,$actor,$property,2200000001],
        ['oauth_callback_denied;DROP TABLE portal_gsc_security_events',
            $tenant,$actor,null,$now]
    ] as [$event,$t,$a,$fingerprint,$when]){
        $check(!$audit->record($event,$t,$a,$fingerprint,$when));
    }

    foreach([
        [$now,6,250],[$now,366,250],[$now,7,0],
        [$now,7,501],[$now,7,'1'],
        [$now,'30',250],[null,30,250]
    ] as [$when,$days,$limit]){
        $check($audit->purge($when,$days,$limit)===null);
    }
    $check($audit->purge($now,7,1)===1);
    $check($audit->purge($now,7,1)===1);
    $check($audit->purge($now,7,1)===0);
    $rows=$pdo->query('SELECT event_code FROM portal_gsc_security_events
        ORDER BY event_at')->fetchAll(PDO::FETCH_COLUMN);
    $check($rows===['oauth_callback_denied','oauth_pkce_prepared']);
    $check($audit->purge($now,365,500)===0);
    $check($audit->purge($now+31*86400,30,500)===2);
    $check($pdo->query('SELECT COUNT(*) FROM portal_gsc_security_events')
        ->fetchColumn()==0);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice Q: metadata-only audit contract refused\n");
    exit(1);
}finally{
    if(isset($pdo)&&$pdo instanceof PDO){
        try{$pdo->exec('DROP TABLE IF EXISTS portal_gsc_security_events');}
        catch(Throwable){}
    }
}
echo "GSC Slice Q: bounded metadata events and privacy retention passed\n";
