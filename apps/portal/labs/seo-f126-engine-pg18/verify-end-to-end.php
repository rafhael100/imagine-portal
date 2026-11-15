<?php
declare(strict_types=1);

/**
 * F126: run ACTUAL Engine-owned F121 signatures through the Portal's
 * Ed25519 verification + PostgreSQL18 tenant-RLS single-use nonce ledger.
 * Synthetic disposable GitHub runner only. No real signer or customer auth.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgSignedEvidenceReplayLab as Ledger;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

const ENGINE_F126_SHA='3c75b8e7b64f4173a17f4daab45cea1b8bdc5554';
const F126_ROLE='portal_f126_service';
const F126_RO_PASS='f126-ci-role-only';

function check126(bool $ok,string $what):void {
    if(!$ok)throw new RuntimeException('F126 PG18 CI: '.$what);
}
if(PHP_SAPI!=='cli' || getenv('CI')!=='true' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_SEO_F118_LAB')!=='1' ||
   getenv('PORTAL_SEO_F122_LAB')!=='1' ||
   getenv('PORTAL_SEO_F123_PG_CI')!=='1' ||
   getenv('PORTAL_SEO_F126_PG_CI')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('F126_ROLE_PASSWORD')!==F126_RO_PASS)
    throw new RuntimeException('F126: disposable PG18 CI only');

function connection126(string $user,string $pass):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$pass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]
    );
}
function evidence126(string $base,string $kind):array {
    check126(in_array($kind,[
        'valid','wrong-locale','private-preview','tampered',
        'wrong-key','other-tenant','expired'
    ],true),'unexpected scenario');
    $file=$base.'/'.$kind.'-envelope.json';
    $pem=$base.'/'.$kind.'-public-key';
    $data=file_get_contents($file);
    $key=file_get_contents($pem);
    check126(is_string($data)&&strlen($data)>100&&strlen($data)<160000 &&
        is_string($key)&&strlen($key)<=100,'bounded inputs');
    $envelope=json_decode($data,true,64,JSON_THROW_ON_ERROR);
    $raw=base64_decode(trim($key),true);
    check126(is_array($envelope)&&is_string($raw)&&strlen($raw)===32,
        'invalid synthetic envelope or public key');
    return [$envelope,$raw];
}
function consume126(PDO $db,string $base,string $kind,array $ctx):?array {
    [$envelope,$publicKey]=evidence126($base,$kind);
    return (new Ledger($db))->consume(
        $envelope,$publicKey,$ctx,'github-actions-f122',
        ENGINE_F126_SHA,time()
    );
}
function rows126(PDO $db):int {
    return (int)$db->query(
        'SELECT count(*) FROM portal_seo_build_evidence_nonces'
    )->fetchColumn();
}
$tenant=['tenantRef'=>'demo-tenant','actorRef'=>'portal-f126-ci'];
$other=['tenantRef'=>'other-tenant','actorRef'=>'portal-f126-other'];

if(($argv[1]??'')==='--worker'){
    check126(count($argv)===3,'worker arguments');
    $db=connection126(F126_ROLE,getenv('F126_ROLE_PASSWORD'));
    $out=consume126($db,$argv[2],'private-preview',$tenant);
    echo $out===null?'DENY':'ACCEPT';
    exit(0);
}
check126(count($argv)===2,'expected one private fixture directory');
$base=$argv[1];
check126(is_dir($base),'private fixture directory required');
$admin=connection126('postgres',getenv('POSTGRES_PASSWORD'));
$admin->exec("CREATE ROLE ".F126_ROLE." LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD '".F126_RO_PASS."'");
$admin->exec("CREATE ROLE portal_f126_readonly LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD '".F126_RO_PASS."'");
foreach([F126_ROLE,'portal_f126_readonly'] as $role) {
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON portal_seo_build_evidence_nonces TO '.$role);
}
$admin->exec('GRANT INSERT ON portal_seo_build_evidence_nonces TO '.F126_ROLE);
$service=connection126(F126_ROLE,getenv('F126_ROLE_PASSWORD'));
$reader=connection126('portal_f126_readonly',getenv('F126_ROLE_PASSWORD'));
$rls=$admin->query("SELECT relrowsecurity,relforcerowsecurity
    FROM pg_class WHERE oid='public.portal_seo_build_evidence_nonces'::regclass")
    ->fetch(PDO::FETCH_ASSOC);
check126(is_array($rls) &&
    in_array($rls['relrowsecurity'],[true,'t'],true) &&
    in_array($rls['relforcerowsecurity'],[true,'t'],true),
    'FORCE RLS absent');
foreach([F126_ROLE,'portal_f126_readonly'] as $role){
    $stmt=$admin->prepare('SELECT rolsuper,rolbypassrls
        FROM pg_roles WHERE rolname=:role');
    $stmt->execute(['role'=>$role]);
    $x=$stmt->fetch(PDO::FETCH_ASSOC);
    check126(is_array($x) &&
        in_array($x['rolsuper'],[false,'f','0',0],true) &&
        in_array($x['rolbypassrls'],[false,'f','0',0],true),
        'role can bypass RLS');
}
check126(rows126($admin)===0,'unexpected pre-existing evidence');

$accepted=consume126($service,$base,'valid',$tenant);
check126(is_array($accepted),'genuine Engine signature rejected');
check126($accepted['signatureVerifiedInLab']===true &&
    $accepted['nonceConsumedAtomicallyInPgLab']===true &&
    $accepted['sanitizedBuildReview']['reviewStatus']==='HUMAN_REVIEW_REQUIRED',
    'signed clean build was elevated to a release');
foreach(['realServiceSignerVerified','realPortalTenantVerified',
    'realReplayLedgerDeployed','customerDisplayAuthorized',
    'publicationAuthorized','networkPerformed'] as $flag)
    check126($accepted[$flag]===false,'false real-world authority '.$flag);
check126(consume126($service,$base,'valid',$tenant)===null,
    'reused source-owned Ed25519 envelope accepted');
check126(rows126($admin)===1,'replay produced duplicate records');

foreach(['tampered','wrong-key','expired'] as $invalid)
    check126(consume126($service,$base,$invalid,$tenant)===null,
        'invalid signed source evidence accepted '.$invalid);
check126(rows126($admin)===1,'invalid signatures consumed nonces');

check126(consume126($reader,$base,'wrong-locale',$tenant)===null,
    'read-only DB role consumed signed wrong-language report');
$locale=consume126($service,$base,'wrong-locale',$tenant);
check126(is_array($locale) &&
    $locale['sanitizedBuildReview']['reviewStatus']==='REVIEW_BLOCKED' &&
    $locale['sanitizedBuildReview']['counts']['wrongLanguagePages']===1 &&
    $locale['publicationAuthorized']===false,
    'wrong-language signed report was not blocked for publication');
check126(rows126($admin)===2,'read-only role inserted nonce');

check126(consume126($service,$base,'other-tenant',$tenant)===null,
    'cross-tenant signature accepted for demo-tenant');
$otherEvidence=consume126($service,$base,'other-tenant',$other);
check126(is_array($otherEvidence),'other-tenant own signed report refused');
check126(rows126($admin)===3,'cross-tenant acceptance persisted wrong count');

$scope=new Scope($service);
check126(rows126($service)===0,
    'tenant local GUC leaked out of transaction');
$forDemo=$scope->run($tenant,static fn(PDO $db)=>rows126($db));
$forOther=$scope->run($other,static fn(PDO $db)=>rows126($db));
check126($forDemo===2 && $forOther===1,
    'tenant isolation of real signed evidence failed');

// Two processes simultaneously consume the SAME Engine-issued envelope.
function launch126(string $base):array {
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).
        ' --worker '.escapeshellarg($base);
    $p=proc_open($command,[
        0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
    ],$pipes);
    check126(is_resource($p),'cannot start concurrent verifier');
    fclose($pipes[0]);
    return [$p,$pipes];
}
function outcome126(array $worker):string {
    [$process,$pipes]=$worker;
    $out=stream_get_contents($pipes[1]);
    $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    check126(proc_close($process)===0,'concurrent worker failed');
    check126(in_array($out,['ACCEPT','DENY'],true),
        'concurrent worker response invalid');
    return $out;
}
$a=launch126($base);
$b=launch126($base);
$results=[outcome126($a),outcome126($b)];
sort($results);
check126($results===['ACCEPT','DENY'],
    'same source signature used by two workers');
check126(rows126($admin)===4,
    'concurrent source-owned signature consumed more than once');
check126(consume126($service,$base,'private-preview',$tenant)===null,
    'replayed signed blocked build accepted');
check126(rows126($service)===0,'RLS scope leaked after concurrency');

putenv('APP_ENV=production');
check126(consume126($service,$base,'valid',$tenant)===null,
    'production mode bypassed lab guard');
check126(rows126($admin)===4,'production guard wrote a nonce');

echo "F126 PG18: Engine-signed clean and blocked reports -> Portal Ed25519".
     " -> tenant RLS -> first-use only; bad signatures denied; concurrent".
     " duplicate denied; no customer or publication authority\n";
