<?php
declare(strict_types=1);

/**
 * Portal GSC F113: issue an actual *synthetic* business session through the
 * Portal's Argon2id/TOTP database adapter, then check recorded GSC link and
 * explicit seo.gsc.view under ONE real PostgreSQL18 RLS transaction.
 * NO customer session, Google OAuth, HTTP route or release authorization.
 */
if(PHP_SAPI!=='cli'||$argc!==3||
   getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_F113_MFA_LAB')!=='1'||
   getenv('PORTAL_GSC_F109_VIEW_LAB')!=='1'||
   getenv('PORTAL_GSC_F108_ATOMIC_LAB')!=='1'||
   getenv('PORTAL_OPENSEO_DAILY_LAB')!=='1'||
   getenv('PGHOST')!=='127.0.0.1'||
   getenv('PGDATABASE')!=='portal_gsc_f113_lab'||
   basename($argv[2])!=='OfflinePortalGscMetricsGrantReview.php'){
    fwrite(STDERR,"F113 private PostgreSQL18 proof only\n");exit(2);
}
require_once dirname(__DIR__,2).'/vendor/autoload.php';
$root=dirname($argv[2]);
foreach([
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleTenantScopedTransaction.php',
    'OfflineOpenSeoDailyPreview.php',
    'OfflinePortalGscMetricsGrantReview.php',
] as $f)require_once $root.'/'.$f;

use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication as Browser;
use App\Infrastructure\Identity\PostgresPortalPermissionPreflight as PermissionPreflight;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\SeoReview\OfflinePortalGscMetricsGrantReview as Gate;

function requireF113(bool $b):void {
    if(!$b)throw new RuntimeException('F113 issued MFA to GSC contract failed');
}
$sessionRef=null;$pendingRef=null;
$org='22222222-2222-4222-8222-222222222220';
$user='11111111-1111-4111-8111-111111111110';
$member='33333333-3333-4333-8333-333333333330';
$connect='44444444-4444-4444-8444-444444444440';
$view='55555555-5555-4555-8555-555555555550';
$gscTenant='imagine-demo';$gscActor='review-operator';
$site='sc-domain:example.com';$now=time();
$owner=null;$browserPdo=null;$gscPdo=null;$revoker=null;
try{
    $payload=file_get_contents($argv[1]);
    requireF113(is_string($payload)&&strlen($payload)<65536);
    $dto=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
    requireF113(is_array($dto));
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f113_lab';
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false];
    $owner=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),$opts);
    requireF113((int)$owner->query(
        "SELECT current_setting('server_version_num')::integer/10000"
    )->fetchColumn()===18);
    $browserPdo=new PDO($dsn,'portal_browser_runtime',
        'f113_browser_runtime_only',$opts);
    $gscPdo=new PDO($dsn,'portal_gsc_f108_reader',
        'f108_ci_synthetic_only',$opts);
    $revoker=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),$opts);
    foreach(['portal_auth_sessions','portal_memberships',
        'portal_membership_grants','portal_gsc_property_grants'] as $t){
        $acl=$owner->prepare("SELECT
            has_table_privilege('portal_gsc_f108_reader',:table,'SELECT'),
            has_table_privilege('portal_browser_runtime',:table,'SELECT')");
        $acl->execute(['table'=>'public.'.$t]);
        foreach($acl->fetch(PDO::FETCH_NUM) as $permission)
            requireF113(in_array($permission,[false,'f',0,'0'],true));
    }
    $proof=bin2hex(random_bytes(32));
    $key=random_bytes(32);
    $pepper=bin2hex(random_bytes(32));
    $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $hash=PortalPasswordCredential::makeHash('synthetic-password-only');
    $sealed=PortalTotpSecretCipher::seal($secret,$key);
    requireF113(is_string($hash)&&str_starts_with($hash,'$argon2id$')&&
        is_string($sealed));
    $owner->prepare("INSERT INTO portal_browser_private.proof_key(secret)
        VALUES (?)")->execute([$proof]);
    $owner->prepare("INSERT INTO portal_identities
        (id,email_normalized,status,password_hash,mfa_secret_ciphertext,
         email_verified_at,mfa_confirmed_at,security_revision,created_at,updated_at)
        VALUES (?,'f113@example.invalid','active',?,?,
          now()-interval '1 hour',now()-interval '1 hour',1,now(),now())")
        ->execute([$user,$hash,$sealed]);
    $owner->prepare("INSERT INTO portal_organizations
        (id,reference,display_name,status,security_revision,created_at,updated_at)
        VALUES (?,'f113-demo','Synthetic F113','active',5,now(),now())")
        ->execute([$org]);
    $owner->prepare("INSERT INTO portal_memberships
        (id,identity_id,organization_id,role,status,membership_revision,
         created_at,updated_at)
        VALUES (?,?,?,'member','active',1,now(),now())")
        ->execute([$member,$user,$org]);
    $add=$owner->prepare("INSERT INTO portal_membership_grants
        (id,membership_id,permission_code,created_at,updated_at)
        VALUES (?,?,?,now(),now())");
    $add->execute([$connect,$member,'seo.gsc.connect']);
    $add->execute([$view,$member,'seo.gsc.view']);

    $revision=(int)$owner->query(
        "SELECT membership_revision FROM portal_memberships")->fetchColumn();
    // F154 triggers from the real identity stack rotate the membership
    // revision on each explicit grant insertion (1 -> 2 -> 3).
    requireF113($revision===3);
    $owner->prepare("INSERT INTO portal_gsc_identity_scope_links
        (portal_organization_id,portal_identity_id,tenant_ref,actor_ref,
        organization_revision,membership_revision,issued_at,expires_at,
        link_status,revoked_at)
        VALUES (?,?,?,?,5,?,?,?,'active',NULL)")
        ->execute([$org,$user,$gscTenant,$gscActor,$revision,$now-100,$now+900]);
    $owner->prepare("INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
        verified_at,expires_at)
        VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly',
        'active',?,?)")
        ->execute([$gscTenant,$gscActor,hash('sha256',$site),$now-30,$now+600]);

    $auth=new Browser($browserPdo,'portal_gsc_f113_lab',$proof,$key,$pepper);
    $preflight=new PermissionPreflight($browserPdo,'portal_gsc_f113_lab');
    $pendingRef=$auth->beginPassword('f113@example.invalid',
        'synthetic-password-only',$org,'192.0.2.113');
    requireF113(is_string($pendingRef)&&strlen($pendingRef)===64);
    requireF113(!$auth->isActiveBusinessSession($pendingRef));
    $counter=(int)$owner->query(
        "SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint"
    )->fetchColumn();
    $digest=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),
        $counter%4294967296),'12345678901234567890',true);
    $offset=ord($digest[19])&15;
    $code=str_pad((string)((unpack('N',substr($digest,$offset,4))[1]&0x7fffffff)
        %1000000),6,'0',STR_PAD_LEFT);
    $sessionRef=$auth->completeMfa($pendingRef,$code,'192.0.2.113');
    requireF113(is_string($sessionRef)&&strlen($sessionRef)===64);
    requireF113($sessionRef!==$pendingRef);
    requireF113($auth->isActiveBusinessSession($sessionRef));
    requireF113(!$preflight->hasCandidateGrant($pendingRef,$org,'seo.gsc.view'));
    requireF113($preflight->hasCandidateGrant($sessionRef,$org,'seo.gsc.view'));
    requireF113($preflight->hasCandidateGrant($sessionRef,$org,'seo.gsc.connect'));
    requireF113(!$preflight->hasCandidateGrant($sessionRef,$org,'seo.gsc.*'));

    $gate=new Gate($gscPdo);
    $inspect=static fn(?Closure $hook=null) =>
        $gate->inspect($sessionRef,$org,$site,time(),$dto,$hook);
    $review=$inspect();
    requireF113(is_array($review)&&
        $review['contractVersion']==='portal-gsc-f109-view-grant-review-lab-v1'&&
        $review['firstPartySessionAndGscRowsObservedAtomically']===true&&
        $review['explicitSeoGscViewRowObserved']===true&&
        $review['summary']['clicks']===$dto['summary']['clicks']);
    foreach([
        'sessionAuthenticatedForProduction',
        'seoViewPermissionAuthorizedForProduction',
        'googleOAuthVerifiedNow','sourceOriginVerified',
        'customerDisplayAuthorized','productionReadAuthorized',
        'publicationAuthorized','networkPerformed'
    ] as $flag)requireF113($review[$flag]===false);
    $json=json_encode($review,JSON_THROW_ON_ERROR);
    foreach([$pendingRef,$sessionRef,$user,$org,$member,$site,$gscActor]
        as $s)requireF113(!str_contains($json,$s));

    $locked=0;
    foreach([
        ["UPDATE portal_auth_sessions SET status='revoked',revoked_at=now()
          WHERE session_sha256=?",hash('sha256',$sessionRef)],
        ["DELETE FROM portal_membership_grants WHERE id=?",$view]
    ] as [$sql,$arg]){
        $onLocked=function()use($revoker,$sql,$arg,&$locked):bool{
            $revoker->beginTransaction();
            try{
                $revoker->exec("SET LOCAL lock_timeout='150ms'");
                $revoker->prepare($sql)->execute([$arg]);
                $revoker->rollBack();
                return false;
            }catch(PDOException $e){
                if($revoker->inTransaction())$revoker->rollBack();
                if($e->getCode()!=='55P03')return false;
                $locked++;
                return true;
            }
        };
        requireF113(is_array($inspect($onLocked)));
    }
    requireF113($locked===2&&!$gscPdo->inTransaction());

    requireF113($gate->inspect(str_repeat('0',64),
        $org,$site,time(),$dto)===null);
    requireF113($gate->inspect($sessionRef,
        '197d34aa-5750-4efe-bb11-d35bd8d23122',
        $site,time(),$dto)===null);
    requireF113($gate->inspect($pendingRef,
        $org,$site,time(),$dto)===null);
    // A real logout, not an admin SQL UPDATE, invalidates BOTH identity
    // preflight and the atomic GSC observation after commit.
    requireF113($auth->revoke($sessionRef));
    requireF113(!$auth->isActiveBusinessSession($sessionRef));
    requireF113(!$preflight->hasCandidateGrant($sessionRef,$org,'seo.gsc.view'));
    requireF113($inspect()===null);
    putenv('PORTAL_GSC_F109_VIEW_LAB=0');
    requireF113($inspect()===null);
    putenv('PORTAL_GSC_F109_VIEW_LAB=1');
    putenv('APP_ENV=production');
    requireF113($inspect()===null);
    putenv('APP_ENV=testing');

    echo "F113: real Portal Argon2id+TOTP issuance -> first-party preflight ".
        "-> atomic GSC read; 2 concurrent revocations fenced; actual logout ".
        "invalidates GSC; NO CUSTOMER AUTHORIZATION\n";
}catch(Throwable){
    fwrite(STDERR,"F113 synthetic issued-session GSC integration failed\n");
    exit(1);
}finally{
    $gscPdo=null;$browserPdo=null;$revoker=null;
    if($owner instanceof PDO){
        try{
            $owner->exec('DELETE FROM portal_auth_attempt_buckets');
            $owner->prepare('DELETE FROM portal_membership_grants
                WHERE membership_id=?')->execute([$member]);
            $owner->prepare('DELETE FROM portal_auth_sessions
                WHERE identity_id=?')->execute([$user]);
            $owner->prepare('DELETE FROM portal_memberships
                WHERE id=?')->execute([$member]);
            $owner->prepare('DELETE FROM portal_identities
                WHERE id=?')->execute([$user]);
            $owner->prepare('DELETE FROM portal_organizations
                WHERE id=?')->execute([$org]);
            $owner->prepare('DELETE FROM portal_gsc_identity_scope_links
                WHERE tenant_ref=?')->execute([$gscTenant]);
            $owner->prepare('DELETE FROM portal_gsc_property_grants
                WHERE tenant_ref=?')->execute([$gscTenant]);
            $owner->exec('DELETE FROM portal_browser_private.proof_key');
        }catch(Throwable){}
    }
}
