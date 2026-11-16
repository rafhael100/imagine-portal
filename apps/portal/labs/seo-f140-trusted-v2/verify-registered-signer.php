<?php
declare(strict_types=1);

/** F140 — Engine F126 real signature → enrolled PG18 public key → signed
 * v2 verify → nonce consumption, with cooperative F128/F129 revocation. */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgTrustedMultilingualV2Lab as Trusted;
use App\Domain\SeoReview\PortalSeoPgSigningKeyRevocationLab as Revoker;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

const F140_ENGINE='38b80fe77bd74ea6e19b8751014038ac3b9f8400';
const F140_SIGNER='seo-engine-v2-ci-lab';
const F140_PASS='f140-ci-role-only';

function check140(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('F140 PG18: '.$reason);
}
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||
   getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_SEO_F138_LAB')!=='1'||
   getenv('PORTAL_SEO_F140_PG_CI')!=='1'||
   getenv('PORTAL_SEO_F128_PG_CI')!=='1'||
   getenv('DB_CONNECTION')!=='pgsql'||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
   getenv('DB_HOST')!=='127.0.0.1'||
   getenv('F140_ROLE_PASSWORD')!==F140_PASS||
   count($argv)!==2||!is_dir($argv[1]))
    throw new RuntimeException('F140 requires disposable PG18 CI');
$folder=$argv[1];
function db140(string $user,string $pass):PDO{
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$pass,[
          PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
function source140(string $folder,string $kind):array {
    $body=file_get_contents($folder.'/'.$kind.'-envelope.json');
    $key=file_get_contents($folder.'/'.$kind.'-public.key');
    check140(is_string($body)&&strlen($body)>100&&strlen($body)<35000,
        'bounded envelope');
    check140(is_string($key)&&strlen($key)<100,'public key data');
    $envelope=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    $raw=base64_decode(trim($key),true);
    check140(is_array($envelope)&&is_string($raw)&&strlen($raw)===32,
        'signed source');
    return [$envelope,$raw];
}
function enroll140(PDO $admin,string $tenant,string $signer,string $key,int $now):void {
    $stmt=$admin->prepare(
        "INSERT INTO portal_seo_build_signing_keys
        (tenant_ref,signer_ref,public_key_fingerprint,public_key_base64,
         key_status,not_before,expires_at,revoked_at)
        VALUES (:tenant,:signer,:fp,:encoded,'active',:before,:until,NULL)"
    );
    $stmt->execute([
        'tenant'=>$tenant,'signer'=>$signer,
        'fp'=>hash('sha256',$key),'encoded'=>base64_encode($key),
        'before'=>$now-60,'until'=>$now+3600
    ]);
}
function accept140(PDO $db,array $e,array $ctx,int $now,
                   string $signer=F140_SIGNER):?array {
    return (new Trusted($db))->accept(
        $e,$ctx,$signer,'github-actions-f138',F140_ENGINE,$now
    );
}
function count140(PDO $db,string $table):int {
    check140(in_array($table,[
       'portal_seo_build_signing_keys',
       'portal_seo_build_evidence_nonces',
       'portal_seo_build_signer_events'
    ],true),'unsupported table');
    return (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}
$admin=db140('postgres',getenv('POSTGRES_PASSWORD'));
$admin->exec("CREATE ROLE portal_f140_service LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD '".F140_PASS."'");
$admin->exec("CREATE ROLE portal_f140_readonly LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD '".F140_PASS."'");
$admin->exec("CREATE ROLE portal_f140_revoker LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD '".F140_PASS."'");
foreach(['portal_f140_service','portal_f140_readonly','portal_f140_revoker'] as $role){
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    foreach(['portal_seo_build_signing_keys',
             'portal_seo_build_evidence_nonces'] as $table)
        $admin->exec('GRANT SELECT ON '.$table.' TO '.$role);
}
$admin->exec('GRANT INSERT ON portal_seo_build_evidence_nonces TO portal_f140_service');
$admin->exec('GRANT UPDATE ON portal_seo_build_signing_keys TO portal_f140_revoker');
$admin->exec('GRANT SELECT,INSERT ON portal_seo_build_signer_events TO portal_f140_revoker');
$service=db140('portal_f140_service',getenv('F140_ROLE_PASSWORD'));
$reader=db140('portal_f140_readonly',getenv('F140_ROLE_PASSWORD'));
$revoker=db140('portal_f140_revoker',getenv('F140_ROLE_PASSWORD'));
foreach(['portal_seo_build_signing_keys','portal_seo_build_evidence_nonces',
         'portal_seo_build_signer_events'] as $table){
    $s=$admin->query("SELECT relrowsecurity,relforcerowsecurity
       FROM pg_class WHERE oid='public.".$table."'::regclass")
       ->fetch(PDO::FETCH_ASSOC);
    check140(is_array($s)&&
        in_array($s['relrowsecurity'],[true,'t'],true)&&
        in_array($s['relforcerowsecurity'],[true,'t'],true),
        'FORCE RLS expected for '.$table);
}
$now=time();
$demo=['tenantRef'=>'demo-tenant','actorRef'=>'portal-f140-ci'];
$other=['tenantRef'=>'another-tenant','actorRef'=>'portal-f140-other'];
[$valid,$keyA]=source140($folder,'valid');
[$blocked,$keyB]=source140($folder,'blocked');
[$revocable,$keyC]=source140($folder,'revocable');
[$external,$keyD]=source140($folder,'other-tenant');
[$tampered,$tamperKey]=source140($folder,'tampered');

// Arbitrary caller key is NOT passed to accept. The server-side DB controls it.
check140(accept140($service,$valid,$demo,$now)===null,
    'unenrolled signing key accepted');
check140(count140($admin,'portal_seo_build_evidence_nonces')===0,
    'unenrolled key consumed a nonce');
enroll140($admin,'demo-tenant',F140_SIGNER,$keyA,$now);
check140(accept140($service,$valid,$demo,$now,'another-signer')===null,
    'wrong signer accepted');
check140(accept140($service,$valid,$other,$now)===null,
    'wrong tenant accepted');
check140(accept140($reader,$valid,$demo,$now)===null,
    'read-only role consumed nonce');
$accepted=accept140($service,$valid,$demo,$now);
check140(is_array($accepted)&&
    $accepted['signatureVerifiedAgainstDbKeyInLab']===true&&
    $accepted['nonceConsumedInSameRlsTransaction']===true&&
    $accepted['cooperativeRevocationLockUsed']===true&&
    $accepted['reviewStatus']==='human_independent_review_required',
    'trusted original Engine report was rejected');
check140(accept140($service,$valid,$demo,$now)===null,'same nonce replay accepted');

enroll140($admin,'demo-tenant',F140_SIGNER,$keyB,$now);
$blockedResult=accept140($service,$blocked,$demo,$now);
check140(is_array($blockedResult)&&
    $blockedResult['reviewStatus']==='blocked'&&
    $blockedResult['summary']['missingHreflangLinks']===9&&
    $blockedResult['publicationAuthorized']===false,
    'valid signature authorized an invalid HTML build');

enroll140($admin,'demo-tenant',F140_SIGNER,$keyC,$now);
$revoked=(new Revoker($revoker))->revoke(
    $demo,F140_SIGNER,hash('sha256',$keyC),$now
);
check140(is_array($revoked)&&
    $revoked['oneSignerKeyRevokedInPgLab']===true&&
    $revoked['revocationAuditPersistedInPgLab']===true,
    'cooperative revocation and audit must be atomic');
check140(accept140($service,$revocable,$demo,$now)===null,
    'revoked key authenticated still-valid v2 evidence');
check140(count140($admin,'portal_seo_build_signer_events')===1,
    'missing mandatory revocation audit');
check140(count140($admin,'portal_seo_build_evidence_nonces')===2,
    'revoked signature inserted a nonce');

check140(accept140($service,$tampered,$demo,$now)===null,
    'bad signed bytes accepted');
enroll140($admin,'another-tenant',F140_SIGNER,$keyD,$now);
check140(accept140($service,$external,$demo,$now)===null,
    'another tenant enrolled key accepted here');
$otherAccepted=accept140($service,$external,$other,$now);
check140(is_array($otherAccepted),'other tenant own registered key refused');

$scoped=new Scope($service);
check140(count140($service,'portal_seo_build_evidence_nonces')===0,
    'RLS tenant scope leaked after transaction');
check140($scoped->run($demo,
    fn(PDO $db)=>count140($db,'portal_seo_build_evidence_nonces'))===2,
    'demo RLS nonce count');
check140($scoped->run($other,
    fn(PDO $db)=>count140($db,'portal_seo_build_evidence_nonces'))===1,
    'other RLS nonce count');
check140(count140($admin,'portal_seo_build_evidence_nonces')===3,
    'untrusted evidence changed ledger');
foreach(['realProductionSignerVerified','realTenantIdentityVerified',
    'realKmsOrKeyDistributionVerified','outOfBandSignerMutationExcluded',
    'productionReplayLedgerDeployed','publicationAuthorized',
    'indexingAuthorized','customerDisplayAuthorized'] as $flag)
    check140($accepted[$flag]===false,'false production flag: '.$flag);
putenv('APP_ENV=production');
check140(accept140($service,$valid,$demo,$now)===null,
    'production mode accepted synthetic report');
check140(!Trusted::allowsProductionPublish()&&
    !Trusted::allowsCustomerDisplay(),'customer or publication enabled');
echo "F140 PG18: only registered keys sign valid v2 evidence; revoked, ".
     "wrong tenant/signer, replay, readonly and tampered evidence denied\n";
