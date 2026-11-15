<?php
declare(strict_types=1);

/**
 * F123 — real PostgreSQL 18 RLS and one-use evidence consumption.
 * Synthetic CI-only identity and ephemeral keys. Never run on customer DB.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgSignedEvidenceReplayLab as Ledger;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

function check123(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('F123 PG18 review: '.$why);
}
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||
   getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_SEO_F118_LAB')!=='1'||
   getenv('PORTAL_SEO_F122_LAB')!=='1'||
   getenv('PORTAL_SEO_F123_PG_CI')!=='1'||
   getenv('DB_CONNECTION')!=='pgsql'||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
   getenv('DB_HOST')!=='127.0.0.1'||
   getenv('F123_ROLE_PASSWORD')!=='f123-ci-role-only')
    throw new RuntimeException('F123: requires disposable PG18 CI');

function pg123(string $username,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $username,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]
    );
}
function envelope123(string $file):array {
    $bytes=file_get_contents($file);
    check123(is_string($bytes)&&strlen($bytes)>100&&strlen($bytes)<160000,
        'bounded envelope');
    $value=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    check123(is_array($value),'envelope JSON');
    return $value;
}
function public123(string $file):string {
    $bytes=file_get_contents($file);
    check123(is_string($bytes)&&strlen($bytes)<=100,
        'bounded public key');
    $raw=base64_decode(trim($bytes),true);
    check123(is_string($raw)&&strlen($raw)===32,'Ed25519 public key');
    return $raw;
}
const ENGINE123='7847c8c66d5be228a08a94282da562c790cdd440';
$ctx=['tenantRef'=>'demo-tenant','actorRef'=>'portal-f123-ci'];

if(($argv[1]??'')==='--worker'){
    check123(count($argv)===4,'worker arguments');
    $db=pg123('portal_f123_service',getenv('F123_ROLE_PASSWORD'));
    $accepted=(new Ledger($db))->consume(
        envelope123($argv[2]),public123($argv[3]),$ctx,
        'github-actions-f122',ENGINE123,time(),
    );
    echo $accepted===null?'DENY':'ACCEPT';
    exit(0);
}
check123(count($argv)===9,'expected four envelope/public-key pairs');
$admin=pg123('postgres',getenv('POSTGRES_PASSWORD'));
$servicePass=getenv('F123_ROLE_PASSWORD');
$admin->exec("CREATE ROLE portal_f123_service LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f123-ci-role-only'");
$admin->exec("CREATE ROLE portal_f123_readonly LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f123-ci-role-only'");
foreach(['portal_f123_service','portal_f123_readonly'] as $role){
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON portal_seo_build_evidence_nonces TO '.$role);
}
$admin->exec('GRANT INSERT ON portal_seo_build_evidence_nonces TO portal_f123_service');
$service=pg123('portal_f123_service',$servicePass);
$readonly=pg123('portal_f123_readonly',$servicePass);
$table='portal_seo_build_evidence_nonces';
$row=$admin->query("SELECT relrowsecurity,relforcerowsecurity
    FROM pg_class WHERE oid='public.".$table."'::regclass")
    ->fetch(PDO::FETCH_ASSOC);
check123(is_array($row)&&
    in_array($row['relrowsecurity'],[true,'t'],true)&&
    in_array($row['relforcerowsecurity'],[true,'t'],true),
    'FORCE RLS policy missing');
foreach(['portal_f123_service','portal_f123_readonly'] as $role){
    $st=$admin->prepare('SELECT rolsuper,rolbypassrls
        FROM pg_roles WHERE rolname=:role');
    $st->execute(['role'=>$role]);
    $info=$st->fetch(PDO::FETCH_ASSOC);
    check123(is_array($info)&&
        in_array($info['rolsuper'],[false,'f','0',0],true)&&
        in_array($info['rolbypassrls'],[false,'f','0',0],true),
        'unprivileged role not enforced');
}
function rows123(PDO $db):int {
    return (int)$db->query(
        'SELECT count(*) FROM portal_seo_build_evidence_nonces'
    )->fetchColumn();
}
function consume123(PDO $db,string $envelope,string $public,array $ctx):?array {
    return (new Ledger($db))->consume(
        envelope123($envelope),public123($public),$ctx,
        'github-actions-f122',ENGINE123,time(),
    );
}

$first=consume123($service,$argv[1],$argv[2],$ctx);
check123(is_array($first),'first valid signed report rejected');
check123($first['nonceConsumedAtomicallyInPgLab']===true&&
    $first['tenantRlsScopeUsedInLab']===true,
    'PG18 nonce consumption proof absent');
foreach(['realServiceSignerVerified','realPortalTenantVerified',
    'realReplayLedgerDeployed','customerDisplayAuthorized',
    'publicationAuthorized','networkPerformed'] as $flag)
    check123($first[$flag]===false,'forbidden authority '.$flag);
check123(rows123($admin)===1,'first signed event not persisted exactly once');

check123(consume123($service,$argv[1],$argv[2],$ctx)===null,
    'replayed identical signature accepted');
check123(rows123($admin)===1,'replay inserted another ledger event');

$otherCtx=['tenantRef'=>'other-tenant','actorRef'=>'portal-f123-other'];
check123(consume123($service,$argv[1],$argv[2],$otherCtx)===null,
    'cross-tenant evidence accepted');
check123(rows123($admin)===1,'cross-tenant attempt changed ledger');

$scope=new Scope($service);
check123(rows123($service)===0,'tenant GUC leaked after transaction');
$otherRows=$scope->run($otherCtx,fn(PDO $db)=>rows123($db));
check123($otherRows===0,'tenant RLS exposed another tenant nonce');
$ownRows=$scope->run($ctx,fn(PDO $db)=>rows123($db));
check123($ownRows===1,'tenant-scoped own nonce not visible');

$stored=$admin->query('SELECT tenant_ref,nonce_digest,public_key_fingerprint
    FROM portal_seo_build_evidence_nonces')->fetchAll(PDO::FETCH_ASSOC);
check123(count($stored)===1&&$stored[0]['tenant_ref']==='demo-tenant',
    'ledger leaked/wrote different tenant');
check123((bool)preg_match('/^[a-f0-9]{64}$/',$stored[0]['nonce_digest']),
    'raw nonce stored rather than hash');

// An otherwise valid signed report must not be usable by a read-only role.
check123(consume123($readonly,$argv[3],$argv[4],$ctx)===null,
    'read-only DB role accepted a signed evidence report');
check123(rows123($admin)===1,'read-only role wrote a nonce');

// Same exact second envelope, two concurrent PostgreSQL connections:
// unique(tenant,nonce_digest) guarantees ONE accepted and ONE rejected.
function worker123(string $envelope,string $public):array {
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).
        ' --worker '.escapeshellarg($envelope).' '.escapeshellarg($public);
    $descriptors=[
        0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
    ];
    $process=proc_open($cmd,$descriptors,$pipes,null,null);
    check123(is_resource($process),'could not start child verifier');
    fclose($pipes[0]);
    return [$process,$pipes];
}
$one=worker123($argv[3],$argv[4]);
$two=worker123($argv[3],$argv[4]);
function finish123(array $worker):string {
    [$process,$pipes]=$worker;
    $stdout=stream_get_contents($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $code=proc_close($process);
    check123($code===0,'concurrent child error (details suppressed)');
    check123(in_array($stdout,['ACCEPT','DENY'],true),'child status invalid');
    return $stdout;
}
$statuses=[finish123($one),finish123($two)];
sort($statuses);
check123($statuses===['ACCEPT','DENY'],
    'PG18 concurrent replay accepted twice or blocked both');
check123(rows123($admin)===2,'concurrent consumption not atomic');

// Two distinct SIGNING KEYS over the same nonce cannot reset the ledger.
$rotationFirst=consume123($service,$argv[5],$argv[6],$ctx);
check123($rotationFirst!==null,'first key for nonce denied');
$rotationReplay=consume123($service,$argv[7],$argv[8],$ctx);
check123($rotationReplay===null,
    'key rotation permitted reuse of an already consumed nonce');
check123(rows123($admin)===3,'key rotation replay mutated ledger');

check123(rows123($service)===0,
    'transaction-local RLS tenant GUC leaked after final call');
check123(!Ledger::authorizesCustomerDisplay()&&
    !Ledger::authorizesProductionPublication(),
    'signed evidence has production authority');
putenv('APP_ENV=production');
check123(consume123($service,$argv[1],$argv[2],$ctx)===null,
    'production mode unexpectedly accepted synthetic signed evidence');
check123(rows123($admin)===3,'production guard changed the ledger');

echo "F123 PG18: first-use accepted; replay, cross-tenant, read-only role, ".
     "concurrent duplicate, key-rotation reuse denied; RLS unchanged\n";
