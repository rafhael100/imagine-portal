<?php
declare(strict_types=1);

/**
 * F127: confirm a signed Engine report is accepted only after its Ed25519
 * public key was enrolled by the trusted CI DB admin for the right tenant
 * and signer. DB public keys are data; private keys never enter the DB.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgTrustedBuildSignerLab as Trusted;
use App\Domain\SeoReview\PortalSeoPgSigningKeyRevocationLab as Revoker;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

const ENGINE_F127='3c75b8e7b64f4173a17f4daab45cea1b8bdc5554';
const SIGNER_F127='seo-engine-ci-lab';

function gate127(bool $value,string $reason):void {
    if(!$value)throw new RuntimeException('F127 PG18: '.$reason);
}
if(PHP_SAPI!=='cli' ||
   getenv('CI')!=='true' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_SEO_F118_LAB')!=='1' ||
   getenv('PORTAL_SEO_F122_LAB')!=='1' ||
   getenv('PORTAL_SEO_F123_PG_CI')!=='1' ||
   getenv('PORTAL_SEO_F127_PG_CI')!=='1' ||
   getenv('PORTAL_SEO_F128_PG_CI')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
   getenv('F127_ROLE_PASSWORD')!=='f127-ci-role-only' ||
   count($argv)!==2 || !is_dir($argv[1]))
    throw new RuntimeException('F127 requires disposable PG18 CI');

function db127(string $user,string $password):PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
function fixed127(string $directory,string $mode):array {
    gate127(in_array($mode,[
        'valid','wrong-locale','private-preview','other-tenant',
        'tampered','wrong-key','expired'
    ],true),'unrecognized mode');
    $j=file_get_contents($directory.'/'.$mode.'-envelope.json');
    $p=file_get_contents($directory.'/'.$mode.'-public-key');
    gate127(is_string($j)&&strlen($j)<160000&&
        is_string($p)&&strlen($p)<100,'invalid input bounds');
    $envelope=json_decode($j,true,64,JSON_THROW_ON_ERROR);
    $pub=base64_decode(trim($p),true);
    gate127(is_array($envelope)&&is_string($pub)&&strlen($pub)===32,
        'invalid fixture');
    return [$envelope,$pub];
}
function count127(PDO $db,string $table):int {
    gate127(in_array($table,[
        'portal_seo_build_signing_keys',
        'portal_seo_build_evidence_nonces'
    ],true),'unsupported table');
    return (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}
function enroll127(
    PDO $admin,string $tenant,string $signer,string $publicKey,
    int $now
):void {
    $s=$admin->prepare(
        "INSERT INTO portal_seo_build_signing_keys
        (tenant_ref,signer_ref,public_key_fingerprint,public_key_base64,
         key_status,not_before,expires_at,revoked_at)
        VALUES (:tenant,:signer,:fingerprint,:encoded,'active',
            :nb,:expires,NULL)"
    );
    $s->execute([
        'tenant'=>$tenant,'signer'=>$signer,
        'fingerprint'=>hash('sha256',$publicKey),
        'encoded'=>base64_encode($publicKey),
        'nb'=>$now-60,'expires'=>$now+3600,
    ]);
}
function use127(
    PDO $db,array $envelope,array $ctx,int $now,
    string $signer=SIGNER_F127
):?array {
    return (new Trusted($db))->accept(
        $envelope,$ctx,$signer,'github-actions-f122',ENGINE_F127,$now
    );
}
$admin=db127('postgres',getenv('POSTGRES_PASSWORD'));
$admin->exec("CREATE ROLE portal_f127_service LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f127-ci-role-only'");
$admin->exec("CREATE ROLE portal_f127_readonly LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f127-ci-role-only'");
$admin->exec("CREATE ROLE portal_f127_revoker LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f127-ci-role-only'");
foreach(['portal_f127_service','portal_f127_readonly','portal_f127_revoker'] as $role){
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON portal_seo_build_signing_keys TO '.$role);
    $admin->exec('GRANT SELECT ON portal_seo_build_evidence_nonces TO '.$role);
}
$admin->exec('GRANT INSERT ON portal_seo_build_evidence_nonces TO portal_f127_service');
// F130 requires revocation and matching audit in one transaction.
$admin->exec('GRANT UPDATE ON portal_seo_build_signing_keys TO portal_f127_revoker');
$admin->exec('GRANT SELECT,INSERT ON portal_seo_build_signer_events TO portal_f127_revoker');
$service=db127('portal_f127_service',getenv('F127_ROLE_PASSWORD'));
$reader=db127('portal_f127_readonly',getenv('F127_ROLE_PASSWORD'));
$revoker=db127('portal_f127_revoker',getenv('F127_ROLE_PASSWORD'));
foreach(['portal_f127_service','portal_f127_readonly','portal_f127_revoker'] as $name){
    $q=$admin->prepare(
        'SELECT rolsuper,rolbypassrls FROM pg_roles WHERE rolname=:name'
    );
    $q->execute(['name'=>$name]);
    $r=$q->fetch(PDO::FETCH_ASSOC);
    gate127(is_array($r)&&
        in_array($r['rolsuper'],[false,'f','0',0],true)&&
        in_array($r['rolbypassrls'],[false,'f','0',0],true),
        'service role can bypass row isolation');
}
foreach(['portal_seo_build_signing_keys','portal_seo_build_evidence_nonces'] as $table){
    $r=$admin->query("SELECT relrowsecurity,relforcerowsecurity
        FROM pg_class WHERE oid='public.".$table."'::regclass")
        ->fetch(PDO::FETCH_ASSOC);
    gate127(is_array($r)&&
        in_array($r['relrowsecurity'],[true,'t'],true)&&
        in_array($r['relforcerowsecurity'],[true,'t'],true),
        'FORCE tenant RLS missing');
}
$now=time();
$demo=['tenantRef'=>'demo-tenant','actorRef'=>'portal-f127-ci'];
$other=['tenantRef'=>'other-tenant','actorRef'=>'portal-f127-ci'];
[$valid,$keyA]=fixed127($argv[1],'valid');
[$wrongLocale,$keyB]=fixed127($argv[1],'wrong-locale');
[$privatePreview,$keyC]=fixed127($argv[1],'private-preview');
[$otherEvidence,$otherKey]=fixed127($argv[1],'other-tenant');
[$tampered,$tamperedKey]=fixed127($argv[1],'tampered');
[$untrusted]=fixed127($argv[1],'wrong-key');
[$expired,$expiredKey]=fixed127($argv[1],'expired');

// A signed, otherwise valid envelope is not accepted if no trustworthy key
// was enrolled separately by the server-side database authority.
gate127(use127($service,$valid,$demo,$now)===null,
    'arbitrary non-enrolled key accepted');
gate127(count127($admin,'portal_seo_build_evidence_nonces')===0,
    'untrusted key consumed a nonce');

enroll127($admin,'demo-tenant',SIGNER_F127,$keyA,$now);
gate127(use127($service,$valid,$demo,$now,'wrong-signer')===null,
    'signerRef supplied by a different service was allowed');
gate127(use127($service,$valid,$other,$now)===null,
    'tenant confusion accepted');
gate127(use127($reader,$valid,$demo,$now)===null,
    'read-only DB role wrote an anti-replay event');
$accepted=use127($service,$valid,$demo,$now);
gate127(is_array($accepted)&&
    $accepted['signatureVerifiedAgainstDbKeyInLab']===true&&
    $accepted['nonceConsumedInSameTenantTransaction']===true&&
    $accepted['sanitizedBuildReview']['reviewStatus']==='HUMAN_REVIEW_REQUIRED',
    'trusted Engine key did not validate actual signed local build');
gate127(use127($service,$valid,$demo,$now)===null,
    'same trusted key bypassed replay ledger');
foreach(['realSignerAuthorityVerified','realPortalIdentityVerified',
    'realKmsOrKeyDistributionVerified','keyRevocationRaceProven',
    'customerDisplayAuthorized','publicationAuthorized'] as $flag)
    gate127($accepted[$flag]===false,'lab granted production authority '.$flag);
gate127(count127($admin,'portal_seo_build_evidence_nonces')===1,
    'signed one-use trust outcome incorrect');

// Key B is a future overlapping rotation key. A key recorded for another
// tenant cannot authenticate even if the bytes of an envelope are valid.
enroll127($admin,'demo-tenant',SIGNER_F127,$keyB,$now);
gate127(use127($service,$tampered,$demo,$now)===null,
    'tampered report accepted');
gate127(use127($service,$untrusted,$demo,$now)===null,
    'untrusted public key accepted');
gate127(use127($service,$expired,$demo,$now)===null,
    'expired source signature accepted');
$blocked=use127($service,$wrongLocale,$demo,$now);
gate127(is_array($blocked)&&
    $blocked['sanitizedBuildReview']['reviewStatus']==='REVIEW_BLOCKED'&&
    $blocked['sanitizedBuildReview']['counts']['wrongLanguagePages']===1&&
    $blocked['publicationAuthorized']===false,
    'signature elevated wrong-language build to publication');

enroll127($admin,'demo-tenant',SIGNER_F127,$keyC,$now);
// F130 now correctly refuses a bare SQL UPDATE without audit.
// Revoke through the real cooperative F128/F129 transaction, enforcing
// tenant RLS, advisory lock and mandatory signed-key audit.
$revoke=(new Revoker($revoker))->revoke(
    $demo,SIGNER_F127,hash('sha256',$keyC),$now
);
gate127(is_array($revoke) &&
    $revoke['oneSignerKeyRevokedInPgLab']===true &&
    $revoke['revocationAuditPersistedInPgLab']===true,
    'F127 key revocation must use the audited F128/F129 path');
gate127(use127($service,$privatePreview,$demo,$now)===null,
    'revoked but otherwise valid signer accepted');

enroll127($admin,'other-tenant',SIGNER_F127,$otherKey,$now);
gate127(use127($service,$otherEvidence,$demo,$now)===null,
    'other tenant public key authenticated demo tenant');
$otherResult=use127($service,$otherEvidence,$other,$now);
gate127(is_array($otherResult),'other tenant own trusted key rejected');

gate127(count127($admin,'portal_seo_build_evidence_nonces')===3,
    'untrusted/revoked evidence consumed a nonce');
$scoped=new Scope($service);
gate127(count127($service,'portal_seo_build_evidence_nonces')===0 &&
    count127($service,'portal_seo_build_signing_keys')===0,
    'tenant GUC persisted outside local transaction');
$demoSigned=$scoped->run($demo,static fn(PDO $db)=>
    count127($db,'portal_seo_build_signing_keys'));
$otherSigned=$scoped->run($other,static fn(PDO $db)=>
    count127($db,'portal_seo_build_signing_keys'));
$demoNonces=$scoped->run($demo,static fn(PDO $db)=>
    count127($db,'portal_seo_build_evidence_nonces'));
$otherNonces=$scoped->run($other,static fn(PDO $db)=>
    count127($db,'portal_seo_build_evidence_nonces'));
gate127($demoSigned===3&&$otherSigned===1&&
    $demoNonces===2&&$otherNonces===1,
    'tenant row visibility failed');
putenv('APP_ENV=production');
gate127(use127($service,$valid,$demo,$now)===null,
    'production mode bypassed no-go');
gate127(count127($admin,'portal_seo_build_evidence_nonces')===3,
    'production mode mutated ledger');
gate127(!Trusted::authorizesCustomerDisplay()&&
    !Trusted::authorizesProductionPublish(),
    'lab unexpectedly authorizes customers');

echo "F127 PG18: only tenant-enrolled Ed25519 public keys authorize a ".
     "synthetic signed report; revoked, wrong signer/tenant/key and ".
     "read-only role denied; nonce consumed once under FORCE RLS\n";
