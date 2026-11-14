<?php
declare(strict_types=1);

/**
 * F112 cross-stack rehearsal: REAL Portal Laravel identity table/migration,
 * pinned GSC F108/F109 source, real PostgreSQL18 FORCE RLS.
 *
 * All rows and references are fictional, no HTTP, actual OAuth or customer
 * authorization. Requires a disposable CI database with exact name.
 */
if (PHP_SAPI!=='cli' || $argc!==3 ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_F112_REAL_SCHEMA_LAB')!=='1' ||
    getenv('PORTAL_GSC_F109_VIEW_LAB')!=='1' ||
    getenv('PORTAL_GSC_F108_ATOMIC_LAB')!=='1' ||
    getenv('PORTAL_OPENSEO_DAILY_LAB')!=='1' ||
    getenv('PGHOST')!=='127.0.0.1' ||
    getenv('PGDATABASE')!=='portal_gsc_f112_lab' ||
    basename($argv[2])!=='OfflinePortalGscMetricsGrantReview.php'){
    fwrite(STDERR,"F112 requires disposable PostgreSQL18 and pinned F109 source\n");
    exit(2);
}

$reviewDir=dirname($argv[2]);
foreach([
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleTenantScopedTransaction.php',
    'OfflineOpenSeoDailyPreview.php',
    'OfflinePortalGscMetricsGrantReview.php',
] as $f) require_once $reviewDir.'/'.$f;

use App\Domain\SeoReview\OfflinePortalGscMetricsGrantReview as Gate;

function assertF112(bool $ok):void {
    if(!$ok)throw new RuntimeException('F112 cross-stack review condition refused');
}
$now=time();
$org='22222222-2222-4222-8222-222222222220';
$user='11111111-1111-4111-8111-111111111110';
$member='33333333-3333-4333-8333-333333333330';
$session='88888888-8888-4888-8888-888888888880';
$view='55555555-5555-4555-8555-555555555550';
$connect='44444444-4444-4444-8444-444444444440';
$ref=bin2hex(random_bytes(32));
$tenant='imagine-demo';$actor='review-operator';
$site='sc-domain:example.com';
$fp=hash('sha256',$site);
$owner=null;$reader=null;$revoker=null;
$denied=0;
try{
    $bytes=file_get_contents($argv[1]);
    assertF112(is_string($bytes)&&strlen($bytes)<65536);
    $dto=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
    assertF112(is_array($dto));
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f112_lab';
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $owner=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),$opts);
    assertF112((int)$owner->query(
        "SELECT current_setting('server_version_num')::integer/10000"
    )->fetchColumn()===18);
    $reader=new PDO($dsn,'portal_gsc_f108_reader',
        'f108_ci_synthetic_only',$opts);
    $revoker=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),$opts);

    foreach(['portal_auth_sessions','portal_identities','portal_memberships',
        'portal_membership_grants','portal_gsc_identity_scope_links',
        'portal_gsc_property_grants'] as $table){
        $access=$owner->prepare("SELECT
            has_table_privilege('portal_gsc_f108_reader',:table,'SELECT'),
            has_table_privilege('portal_gsc_f108_reader',:table,'UPDATE')");
        $access->execute(['table'=>'public.'.$table]);
        foreach($access->fetch(PDO::FETCH_NUM) as $v)
            assertF112(in_array($v,[false,'f',0,'0'],true));
        $rls=$owner->prepare("SELECT relrowsecurity,relforcerowsecurity
            FROM pg_class WHERE oid=to_regclass(:table)");
        $rls->execute(['table'=>'public.'.$table]);
        foreach($rls->fetch(PDO::FETCH_NUM) as $v)
            assertF112(in_array($v,[true,'t',1,'1'],true));
    }
    assertF112(!Gate::canQueryGoogle()&&!Gate::canAuthorizeCustomer()&&
        !Gate::canPublish());

    $owner->prepare("INSERT INTO portal_identities
        (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
         security_revision,created_at,updated_at)
        VALUES (?,'f112@example.invalid','active',now()-interval '1 hour',
         now()-interval '1 hour',1,now(),now())")
        ->execute([$user]);
    $owner->prepare("INSERT INTO portal_organizations
        (id,reference,display_name,status,security_revision,created_at,updated_at)
        VALUES (?,'f112-organization','Synthetic F112','active',5,now(),now())")
        ->execute([$org]);
    $owner->prepare("INSERT INTO portal_memberships
        (id,identity_id,organization_id,role,status,membership_revision,
         created_at,updated_at)
        VALUES (?,?,?,'owner','active',7,now(),now())")
        ->execute([$member,$user,$org]);
    $owner->prepare("INSERT INTO portal_auth_sessions
        (id,session_sha256,identity_id,organization_id,status,session_purpose,
         identity_revision,membership_revision,organization_revision,
         issued_at,expires_at,mfa_verified_at,created_at,updated_at)
        VALUES (?,?,?,?,'active','business',1,7,5,now()-interval '2 minutes',
         now()+interval '30 minutes',now()-interval '1 minute',now(),now())")
        ->execute([$session,hash('sha256',$ref),$user,$org]);
    $owner->prepare("INSERT INTO portal_gsc_identity_scope_links
        (portal_organization_id,portal_identity_id,tenant_ref,actor_ref,
         organization_revision,membership_revision,issued_at,expires_at,
         link_status,revoked_at)
        VALUES (?,?,?,?,5,7,?,?,'active',NULL)")
        ->execute([$org,$user,$tenant,$actor,$now-90,$now+900]);
    $owner->prepare("INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
         verified_at,expires_at)
        VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly',
         'active',?,?)")
        ->execute([$tenant,$actor,$fp,$now-30,$now+600]);

    $add=$owner->prepare("INSERT INTO portal_membership_grants
        (id,membership_id,permission_code,created_at,updated_at)
        VALUES (?,?,?,now(),now())");
    $add->execute([$connect,$member,'seo.gsc.connect']);

    $gate=new Gate($reader);
    $inspect=static fn(?Closure $callback=null) =>
        $gate->inspect($ref,$org,$site,time(),$dto,$callback);
    assertF112($inspect()===null);$denied++;
    $add->execute([$view,$member,'seo.gsc.view']);
    $ok=$inspect();
    assertF112(is_array($ok)&&
        $ok['contractVersion']==='portal-gsc-f109-view-grant-review-lab-v1'&&
        $ok['explicitSeoGscViewRowObserved']===true &&
        $ok['connectOnlyGrantsInsufficient']===true &&
        $ok['summary']['clicks']===$dto['summary']['clicks']);
    foreach(['sessionAuthenticatedForProduction',
        'seoViewPermissionAuthorizedForProduction','googleOAuthVerifiedNow',
        'sourceOriginVerified','customerDisplayAuthorized',
        'productionReadAuthorized','publicationAuthorized','networkPerformed']
        as $flag)assertF112($ok[$flag]===false);
    $encoded=json_encode($ok,JSON_THROW_ON_ERROR);
    foreach([$ref,$org,$user,$member,$site,$actor] as $s)
        assertF112(!str_contains($encoded,$s));

    // A competing connection must NOT delete a view grant while the
    // first-party session and GSC rows are being read under locked scope.
    $contender=static function()use($revoker,$view):bool{
        $revoker->beginTransaction();
        try{
            $revoker->exec("SET LOCAL lock_timeout='150ms'");
            $q=$revoker->prepare("DELETE FROM portal_membership_grants WHERE id=?");
            $q->execute([$view]);
            $revoker->rollBack();
            return false;
        }catch(PDOException $e){
            if($revoker->inTransaction())$revoker->rollBack();
            return $e->getCode()==='55P03';
        }
    };
    assertF112(is_array($inspect($contender)));
    assertF112(!$reader->inTransaction());

    // After COMMIT, committed deletion denies the next independent read.
    $revoker->prepare("DELETE FROM portal_membership_grants WHERE id=?")
        ->execute([$view]);
    assertF112($inspect()===null);$denied++;
    $add->execute([$view,$member,'seo.gsc.view']);
    assertF112(is_array($inspect()));

    foreach([
        [str_repeat('0',64),$org,$site,$dto],
        [$ref,'197d34aa-5750-4efe-bb11-d35bd8d23122',$site,$dto],
        [$ref,$org,'sc-domain:example.net',$dto],
        [$ref,$org,$site,array_merge($dto,['tenantRef'=>'other-tenant'])],
        [$ref,$org,$site,array_merge($dto,['customerDisplayAuthorized'=>true])],
    ] as [$sess,$organization,$property,$metrics]){
        assertF112($gate->inspect($sess,$organization,$property,time(),$metrics)===null);
        $denied++;
    }
    $owner->prepare("UPDATE portal_memberships SET membership_revision=8
        WHERE id=?")->execute([$member]);
    assertF112($inspect()===null);$denied++;
    $owner->prepare("UPDATE portal_auth_sessions SET status='revoked',
        revoked_at=now() WHERE id=?")->execute([$session]);
    assertF112($inspect()===null);$denied++;
    $owner->prepare("UPDATE portal_organizations SET status='suspended'
        WHERE id=?")->execute([$org]);
    assertF112($inspect()===null);$denied++;
    putenv('PORTAL_GSC_F109_VIEW_LAB=0');
    assertF112($inspect()===null);$denied++;
    putenv('PORTAL_GSC_F109_VIEW_LAB=1');
    putenv('APP_ENV=production');
    assertF112($inspect()===null);$denied++;
    putenv('APP_ENV=testing');
    assertF112((new Gate($owner))->inspect($ref,$org,$site,time(),$dto)===null);
    $denied++;
    assertF112(!$reader->inTransaction()&&!$revoker->inTransaction());

    echo "F112: real Portal identity schema + GSC F108/F109 locked view ".
        "grant on PG18; concurrent delete blocked, {$denied} denials; ".
        "all customer access flags false\n";
}catch(Throwable){
    fwrite(STDERR,"F112 integrated real-schema rehearsal failed\n");
    exit(1);
}finally{
    $reader=null;$revoker=null;
    if($owner instanceof PDO){
        try{
            $owner->prepare('DELETE FROM portal_membership_grants
                WHERE membership_id=?')->execute([$member]);
            $owner->prepare('DELETE FROM portal_auth_sessions
                WHERE id=?')->execute([$session]);
            $owner->prepare('DELETE FROM portal_memberships
                WHERE id=?')->execute([$member]);
            $owner->prepare('DELETE FROM portal_identities
                WHERE id=?')->execute([$user]);
            $owner->prepare('DELETE FROM portal_organizations
                WHERE id=?')->execute([$org]);
            $owner->prepare('DELETE FROM portal_gsc_identity_scope_links
                WHERE tenant_ref=?')->execute([$tenant]);
            $owner->prepare('DELETE FROM portal_gsc_property_grants
                WHERE tenant_ref=?')->execute([$tenant]);
        }catch(Throwable){}
    }
}
