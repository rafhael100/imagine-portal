<?php
declare(strict_types=1);

/**
 * F128 PG18: a cooperating key revoker and verifier hold the same advisory
 * transaction lock. The revoker grabs the lock first, pauses, then revokes;
 * a concurrent signed-report consumer must wait and then deny.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgSigningKeyLock as KeyLock;
use App\Domain\SeoReview\PortalSeoPgTrustedBuildSignerLab as Verify;
use App\Domain\SeoReview\PortalSeoPgSigningKeyRevocationLab as Revoke;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

const F128_ENGINE='3c75b8e7b64f4173a17f4daab45cea1b8bdc5554';
const F128_SIGNER='seo-engine-ci-lab';
const F128_PASS='f128-ci-role-only';

function must128(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('F128 PG18: '.$reason);
}
if(PHP_SAPI!=='cli' || getenv('CI')!=='true' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_SEO_F118_LAB')!=='1' ||
   getenv('PORTAL_SEO_F122_LAB')!=='1' ||
   getenv('PORTAL_SEO_F123_PG_CI')!=='1' ||
   getenv('PORTAL_SEO_F128_PG_CI')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
   getenv('F128_ROLE_PASSWORD')!==F128_PASS)
    throw new RuntimeException('F128: disposable PG18 only');

function db128(string $role,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $role,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]
    );
}
function key128(string $base,string $mode):array {
    must128(in_array($mode,['valid','wrong-locale','private-preview'],true),
        'unexpected mode');
    $envelope=file_get_contents($base.'/'.$mode.'-envelope.json');
    $key=file_get_contents($base.'/'.$mode.'-public-key');
    must128(is_string($envelope)&&strlen($envelope)<160000&&
        is_string($key)&&strlen($key)<100,'bounded fixture');
    $json=json_decode($envelope,true,64,JSON_THROW_ON_ERROR);
    $raw=base64_decode(trim($key),true);
    must128(is_array($json)&&is_string($raw)&&strlen($raw)===32,
        'Ed25519 test fixture malformed');
    return [$json,$raw,hash('sha256',$raw)];
}
function seed128(PDO $db,string $tenant,string $key,int $now):void {
    $st=$db->prepare(
        "INSERT INTO portal_seo_build_signing_keys
        (tenant_ref,signer_ref,public_key_fingerprint,public_key_base64,
         key_status,not_before,expires_at,revoked_at)
        VALUES (:tenant,:signer,:fp,:pk,'active',:nb,:expires,NULL)"
    );
    $st->execute([
        'tenant'=>$tenant,'signer'=>F128_SIGNER,
        'fp'=>hash('sha256',$key),'pk'=>base64_encode($key),
        'nb'=>$now-60,'expires'=>$now+3600
    ]);
}
function evidence128(PDO $db,array $envelope,array $ctx,int $now):?array {
    return (new Verify($db))->accept(
        $envelope,$ctx,F128_SIGNER,'github-actions-f122',
        F128_ENGINE,$now
    );
}
function count128(PDO $db,string $table):int {
    must128(in_array($table,[
        'portal_seo_build_signing_keys','portal_seo_build_evidence_nonces'
    ],true),'not a permitted table');
    return (int)$db->query('SELECT count(*) FROM '.$table)->fetchColumn();
}
$tenant=['tenantRef'=>'demo-tenant','actorRef'=>'portal-f128-test'];
$other=['tenantRef'=>'other-tenant','actorRef'=>'portal-f128-test'];
$now=time();

if(($argv[1]??'')==='--revoke-worker'){
    must128(count($argv)===3 &&
        preg_match('/\A[a-f0-9]{64}\z/D',$argv[2])===1,
        'worker expects one trusted fingerprint');
    $db=db128('portal_f128_admin',getenv('F128_ROLE_PASSWORD'));
    $scope=new Scope($db);
    $ok=$scope->run($tenant,static function(PDO $pdo)use($argv,$now):bool {
        if(!KeyLock::acquire($pdo,'demo-tenant',F128_SIGNER))
            return false;
        echo "LOCKED\n";
        fflush(STDOUT);
        // Temporary CI-only hold proves that the verifier waits for commit
        // rather than accepting from a stale snapshot.
        usleep(850000);
        $stmt=$pdo->prepare(
            "UPDATE portal_seo_build_signing_keys
             SET key_status='revoked',revoked_at=:now
             WHERE tenant_ref=:tenant AND signer_ref=:signer
               AND public_key_fingerprint=:fp AND key_status='active'
             RETURNING public_key_fingerprint"
        );
        $stmt->execute([
            'now'=>$now,'tenant'=>'demo-tenant','signer'=>F128_SIGNER,
            'fp'=>$argv[2]
        ]);
        return $stmt->fetchColumn()!==false;
    });
    must128($ok===true,'cooperative worker did not revoke');
    echo "REVOKED\n";
    exit(0);
}
must128(count($argv)===2&&is_dir($argv[1]),
    'private fixture directory expected');
$base=$argv[1];
[$valid,$keyA,$fpA]=key128($base,'valid');
[$wrongLanguage,$keyB,$fpB]=key128($base,'wrong-locale');
[$privatePreview,$keyC,$fpC]=key128($base,'private-preview');

$admin=db128('postgres',getenv('POSTGRES_PASSWORD'));
$admin->exec("CREATE ROLE portal_f128_service LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f128-ci-role-only'");
$admin->exec("CREATE ROLE portal_f128_admin LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f128-ci-role-only'");
$admin->exec("CREATE ROLE portal_f128_readonly LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f128-ci-role-only'");
foreach(['portal_f128_service','portal_f128_admin','portal_f128_readonly'] as $role){
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON portal_seo_build_signing_keys TO '.$role);
}
$admin->exec('GRANT SELECT,INSERT ON portal_seo_build_evidence_nonces
    TO portal_f128_service');
$admin->exec('GRANT UPDATE ON portal_seo_build_signing_keys
    TO portal_f128_admin');
$service=db128('portal_f128_service',F128_PASS);
$revoker=db128('portal_f128_admin',F128_PASS);
$readonly=db128('portal_f128_readonly',F128_PASS);
foreach(['portal_f128_service','portal_f128_admin','portal_f128_readonly'] as $role){
    $query=$admin->prepare('SELECT rolsuper,rolbypassrls
        FROM pg_roles WHERE rolname=:role');
    $query->execute(['role'=>$role]);
    $entry=$query->fetch(PDO::FETCH_ASSOC);
    must128(is_array($entry)&&
        in_array($entry['rolsuper'],[false,'f','0',0],true)&&
        in_array($entry['rolbypassrls'],[false,'f','0',0],true),
        'role bypasses row security');
}
seed128($admin,'demo-tenant',$keyA,$now);
seed128($admin,'demo-tenant',$keyB,$now);
seed128($admin,'demo-tenant',$keyC,$now);
seed128($admin,'other-tenant',$keyA,$now);
must128(count128($admin,'portal_seo_build_signing_keys')===4,
    'seed trust rows missing');

must128((new Revoke($readonly))->revoke($tenant,F128_SIGNER,$fpB,$now)===null,
    'read-only role revoked signer');
must128((new Revoke($revoker))->revoke($other,F128_SIGNER,$fpB,$now)===null,
    'revocation crossed tenant boundary');

$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).
    ' --revoke-worker '.escapeshellarg($fpA);
$worker=proc_open($cmd,[
    0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
],$pipes);
must128(is_resource($worker),'could not start concurrent revoker');
fclose($pipes[0]);
stream_set_timeout($pipes[1],5);
$signal=fgets($pipes[1]);
must128($signal==="LOCKED\n",'revoker failed to acquire lock first');

// F127 verifier must wait until worker transaction revokes key A.
$start=microtime(true);
$stale=evidence128($service,$valid,$tenant,$now);
$elapsed=microtime(true)-$start;
$remaining=stream_get_contents($pipes[1]);
$errorOutput=stream_get_contents($pipes[2]);
fclose($pipes[1]);fclose($pipes[2]);
$exit=proc_close($worker);
must128($exit===0&&str_contains($remaining,'REVOKED'),
    'cooperative revocation did not commit');
must128($stale===null && $elapsed>0.15,
    'revoked key was accepted during the lock wait');
must128(count128($admin,'portal_seo_build_evidence_nonces')===0,
    'failed key verification consumed a nonce');

$revocation=(new Revoke($revoker))->revoke(
    $tenant,F128_SIGNER,$fpB,$now
);
must128(is_array($revocation)&&
    $revocation['oneSignerKeyRevokedInPgLab']===true&&
    $revocation['tenantSignerTransactionLockUsed']===true&&
    $revocation['customerDisplayAuthorized']===false&&
    $revocation['publicationAuthorized']===false,
    'real lab revocation class failed');
must128((new Revoke($revoker))->revoke(
    $tenant,F128_SIGNER,$fpB,$now
)===null,'double revocation accepted');
must128(evidence128($service,$wrongLanguage,$tenant,$now)===null,
    'revoked rotated key still accepted a signed report');

// A different nonrevoked, server-enrolled key still works; its signed
// private-preview warning remains blocked for publication.
$good=evidence128($service,$privatePreview,$tenant,$now);
must128(is_array($good)&&
    $good['cooperativeSignerLockAppliedInLab']===true&&
    $good['keyRevocationRaceProven']===false&&
    $good['outOfBandAdminRevocationExcluded']===false&&
    $good['sanitizedBuildReview']['reviewStatus']==='REVIEW_BLOCKED'&&
    $good['sanitizedBuildReview']['counts']['privateDraftLeaks']===1&&
    $good['publicationAuthorized']===false,
    'remaining trusted key or blocked content behaved incorrectly');
must128(evidence128($service,$privatePreview,$tenant,$now)===null,
    'accepted signed report replayed');
must128(count128($admin,'portal_seo_build_evidence_nonces')===1,
    'revocation/replay wrote unexpected nonce records');

$scope=new Scope($service);
$ownCount=$scope->run($tenant,static fn(PDO $db)=>
    count128($db,'portal_seo_build_signing_keys'));
$otherCount=$scope->run($other,static fn(PDO $db)=>
    count128($db,'portal_seo_build_signing_keys'));
must128($ownCount===3&&$otherCount===1,
    'keys from another tenant became visible');
must128(count128($service,'portal_seo_build_signing_keys')===0 &&
    count128($service,'portal_seo_build_evidence_nonces')===0,
    'tenant transaction context leaked');

putenv('APP_ENV=production');
must128((new Revoke($revoker))->revoke($tenant,F128_SIGNER,$fpC,$now)===null,
    'production mode revoked synthetic key');
must128(evidence128($service,$privatePreview,$tenant,$now)===null,
    'production mode accepted synthetic signed report');
must128(!Revoke::authorizesProductionKeyRevocation() &&
    !Revoke::authorizesCustomerDisplay(),
    'lab revoker gained production authority');

echo "F128 PG18: revocation-first signer lock serialized with verification; ".
     "no nonce accepted after revoke; cooperative revocation/role/RLS ".
     "checks passed; active key retains publication hold\n";
