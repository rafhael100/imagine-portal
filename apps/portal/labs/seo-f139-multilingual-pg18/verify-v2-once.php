<?php
declare(strict_types=1);

/**
 * SEO F139: actual Engine F126 signatures -> Portal F138 -> real PG18
 * RLS nonce ledger in a disposable GitHub Actions database.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgMultilingualEvidenceOnceLab as Ledger;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

const ENGINE_F139='38b80fe77bd74ea6e19b8751014038ac3b9f8400';
const F139_ROLE='portal_f139_service';
const F139_PASS='f139-ci-role-only';
const F139_TABLE='portal_seo_build_evidence_nonces';

function check139(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('SEO F139 PG18: '.$why);
}
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||
   getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_SEO_F138_LAB')!=='1'||
   getenv('PORTAL_SEO_F139_PG_CI')!=='1'||
   getenv('DB_CONNECTION')!=='pgsql'||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
   getenv('DB_HOST')!=='127.0.0.1'||
   getenv('F139_ROLE_PASSWORD')!==F139_PASS)
    throw new RuntimeException('F139 requires disposable PG18 CI only');

function connect139(string $user,string $pass):PDO {
    return new PDO(
      'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
      $user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false
      ]
    );
}
function evidence139(string $folder,string $scenario):array {
    $file=$folder.'/'.$scenario.'-envelope.json';
    $keyFile=$folder.'/'.$scenario.'-public.key';
    $bytes=file_get_contents($file);
    $keyBytes=file_get_contents($keyFile);
    check139(is_string($bytes)&&strlen($bytes)>100&&strlen($bytes)<35000,
        'bounded signed report source');
    check139(is_string($keyBytes)&&strlen($keyBytes)<100,'public key source');
    $env=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
    $key=base64_decode(trim($keyBytes),true);
    check139(is_array($env)&&is_string($key)&&strlen($key)===32,
        'valid signed source file');
    return [$env,$key];
}
function consume139(PDO $db,string $folder,string $case,array $context):?array {
    [$e,$key]=evidence139($folder,$case);
    return (new Ledger($db))->consume(
        $e,$key,$context,'github-actions-f138',ENGINE_F139,time()
    );
}
function rows139(PDO $db):int {
    return (int)$db->query(
        'SELECT count(*) FROM '.F139_TABLE
    )->fetchColumn();
}
$tenant=['tenantRef'=>'demo-tenant','actorRef'=>'portal-f139-ci'];
$other=['tenantRef'=>'another-tenant','actorRef'=>'portal-f139-other'];

if(($argv[1]??'')==='--worker'){
    check139(count($argv)===3,'worker arguments');
    $service=connect139(F139_ROLE,getenv('F139_ROLE_PASSWORD'));
    echo consume139($service,$argv[2],'concurrent',$tenant)===null?'DENY':'ACCEPT';
    exit(0);
}
check139(count($argv)===2,'fixture directory argument');
$folder=$argv[1];
check139(is_dir($folder),'fixture directory');
$admin=connect139('postgres',getenv('POSTGRES_PASSWORD'));
$admin->exec("CREATE ROLE ".F139_ROLE." LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD '".F139_PASS."'");
$admin->exec("CREATE ROLE portal_f139_readonly LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD '".F139_PASS."'");
foreach([F139_ROLE,'portal_f139_readonly'] as $role){
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON '.F139_TABLE.' TO '.$role);
}
$admin->exec('GRANT INSERT ON '.F139_TABLE.' TO '.F139_ROLE);
$service=connect139(F139_ROLE,getenv('F139_ROLE_PASSWORD'));
$reader=connect139('portal_f139_readonly',getenv('F139_ROLE_PASSWORD'));
$rls=$admin->query("SELECT relrowsecurity,relforcerowsecurity
    FROM pg_class WHERE oid='public.".F139_TABLE."'::regclass")
    ->fetch(PDO::FETCH_ASSOC);
check139(is_array($rls)&&
    in_array($rls['relrowsecurity'],[true,'t'],true)&&
    in_array($rls['relforcerowsecurity'],[true,'t'],true),
    'FORCE tenant RLS required');
foreach([F139_ROLE,'portal_f139_readonly'] as $role){
    $s=$admin->prepare('SELECT rolsuper,rolbypassrls FROM pg_roles WHERE rolname=:name');
    $s->execute(['name'=>$role]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    check139(is_array($r)&&
        in_array($r['rolsuper'],[false,'f','0',0],true)&&
        in_array($r['rolbypassrls'],[false,'f','0',0],true),
        'non-bypass role required');
}
check139(rows139($admin)===0,'nonempty disposable database');
$ok=consume139($service,$folder,'valid',$tenant);
check139(is_array($ok)&&$ok['reviewStatus']==='human_independent_review_required',
    'actual source audit signature rejected');
check139($ok['signatureVerifiedInLab']===true&&
    $ok['nonceConsumedAtomicallyInPgLab']===true&&
    $ok['tenantRlsScopeUsedInLab']===true,'PG18 evidence not verified');
foreach(['productionSignerCustodyVerified','realPortalTenantAuthenticated',
    'productionReplayLedgerDeployed','editorialPublicationAuthorized',
    'indexingAuthorized','customerDisplayAuthorized'] as $flag)
    check139($ok[$flag]===false,'forbidden release flag '.$flag);
check139(consume139($service,$folder,'valid',$tenant)===null,
    'same v2 signed envelope replay accepted');
check139(rows139($admin)===1,'replay wrote a row');

foreach(['tampered','wrong-key','expired','bad-flags','wrong-contract'] as $case)
    check139(consume139($service,$folder,$case,$tenant)===null,
        'invalid evidence accepted '.$case);
check139(rows139($admin)===1,'invalid evidence wrote a row');

check139(consume139($reader,$folder,'blocked',$tenant)===null,
    'readonly role inserted nonce');
$blocked=consume139($service,$folder,'blocked',$tenant);
check139(is_array($blocked)&&$blocked['reviewStatus']==='blocked'&&
    $blocked['summary']['missingHreflangLinks']===9&&
    $blocked['editorialPublicationAuthorized']===false,
    'signed blocked report escalated to publication');
check139(rows139($admin)===2,'incorrect blocked report ledger state');

check139(consume139($service,$folder,'other-tenant',$tenant)===null,
    'cross-tenant v2 evidence accepted');
$otherAccepted=consume139($service,$folder,'other-tenant',$other);
check139(is_array($otherAccepted),'other tenant own signed report rejected');
check139(rows139($admin)===3,'cross-tenant row count');

$scope=new Scope($service);
check139(rows139($service)===0,'transaction local tenant leaked');
check139($scope->run($tenant,fn(PDO $db)=>rows139($db))===2,
    'demo tenant should see only its own nonces');
check139($scope->run($other,fn(PDO $db)=>rows139($db))===1,
    'another tenant should see only its own nonce');

function worker139(string $folder):array {
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).
        ' --worker '.escapeshellarg($folder);
    $p=proc_open($command,[
       0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
    ],$pipes);
    check139(is_resource($p),'spawn');
    fclose($pipes[0]);
    return [$p,$pipes];
}
function finish139(array $w):string {
    [$proc,$pipes]=$w;
    $out=stream_get_contents($pipes[1]);
    $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    check139(proc_close($proc)===0,'worker error (details suppressed)');
    check139(in_array($out,['ACCEPT','DENY'],true),'worker response');
    return $out;
}
$a=worker139($folder);
$b=worker139($folder);
$statuses=[finish139($a),finish139($b)];
sort($statuses);
check139($statuses===['ACCEPT','DENY'],'concurrent duplicate accepted or both failed');
check139(rows139($admin)===4,'concurrent insert was not atomic');
check139(consume139($service,$folder,'concurrent',$tenant)===null,
    'post-race replay accepted');
check139(rows139($service)===0,'tenant scope leaked after concurrent consumption');

check139(!Ledger::allowsProductionPublication()&&!Ledger::allowsCustomerDisplay(),
    'lab accidentally publishes or serves customers');
putenv('APP_ENV=production');
check139(consume139($service,$folder,'valid',$tenant)===null,
    'production guard missing');
check139(rows139($admin)===4,'production guard wrote to database');

echo "F139 PG18: signed multilingual HTML consumed once; RLS, read-only, ".
     "cross-tenant, replay and concurrent adversaries denied\n";
