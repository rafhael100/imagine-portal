<?php
declare(strict_types=1);

/**
 * F124 — real PG18 retention proof on the disposable ledger seeded by F123.
 * Must run after verify-evidence-once.php in the SAME GitHub Actions job.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgEvidenceNonceRetentionLab as Retention;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

function expect124(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('F124 PG18 review: '.$reason);
}
if(PHP_SAPI!=='cli' ||
   getenv('CI')!=='true' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_SEO_F123_PG_CI')!=='1' ||
   getenv('PORTAL_SEO_F124_PG_CI')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
   getenv('F124_ROLE_PASSWORD')!=='f124-ci-role-only')
    throw new RuntimeException('F124 requires disposable PG18 CI');

function db124(string $user,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]
    );
}
$admin=db124('postgres',getenv('POSTGRES_PASSWORD'));
$known=(int)$admin->query('SELECT COUNT(*) FROM portal_seo_build_evidence_nonces')
    ->fetchColumn();
expect124($known===3,'F123 must insert exactly 3 recent nonces first');

$admin->exec("CREATE ROLE portal_f124_maintainer LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f124-ci-role-only'");
$admin->exec("CREATE ROLE portal_f124_readonly LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f124-ci-role-only'");
foreach(['portal_f124_maintainer','portal_f124_readonly'] as $role){
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON portal_seo_build_evidence_nonces TO '.$role);
}
$admin->exec('GRANT DELETE ON portal_seo_build_evidence_nonces TO portal_f124_maintainer');

$maintainer=db124('portal_f124_maintainer',getenv('F124_ROLE_PASSWORD'));
$readonly=db124('portal_f124_readonly',getenv('F124_ROLE_PASSWORD'));

$now=time();
$engine='7847c8c66d5be228a08a94282da562c790cdd440';
$key=str_repeat('a',64);
$insert=$admin->prepare(
    'INSERT INTO portal_seo_build_evidence_nonces
    (tenant_ref,nonce_digest,public_key_fingerprint,
     run_ref,engine_commit_sha,received_at,expires_at)
    VALUES (:tenant,:nonce,:key,:run,:engine,:received,:expires)'
);
function seed124(
    PDOStatement $insert,string $tenant,string $label,
    int $received,int $expires,string $key,string $engine
):void {
    $insert->execute([
        'tenant'=>$tenant,'nonce'=>hash('sha256',$label),
        'key'=>$key,'run'=>'f124-retention-ci',
        'engine'=>$engine,'received'=>$received,'expires'=>$expires
    ]);
}
// 502 old records exceed the 500-row safety batch.
$admin->beginTransaction();
for($i=0;$i<502;$i++)
    seed124($insert,'demo-tenant','expired-demo-'.$i,
        $now-5500,$now-5400,$key,$engine);
// A recently expired nonce is inside the one-hour grace window.
seed124($insert,'demo-tenant','recent-expired-demo',
    $now-3650,$now-3500,$key,$engine);
// An unexpired nonce is never eligible.
seed124($insert,'demo-tenant','active-demo',
    $now-120,$now+120,$key,$engine);
// Another tenant's old row must not disappear during demo-tenant cleanup.
seed124($insert,'other-tenant','expired-other-tenant',
    $now-5500,$now-5400,$key,$engine);
$admin->commit();
expect124((int)$admin->query(
    'SELECT COUNT(*) FROM portal_seo_build_evidence_nonces'
)->fetchColumn()===508,'seeded row count');

$a=['tenantRef'=>'demo-tenant','actorRef'=>'portal-f124-operator'];
$b=['tenantRef'=>'other-tenant','actorRef'=>'portal-f124-operator'];
$review=new Retention($maintainer);
expect124((int)$maintainer->query(
    'SELECT COUNT(*) FROM portal_seo_build_evidence_nonces'
)->fetchColumn()===0,'tenant GUC leaked before scope');

$withoutWrite=(new Retention($readonly))->purgeExpired($a,$now);
expect124($withoutWrite===null,'read-only role deleted expired evidence');
expect124((int)$admin->query(
    'SELECT COUNT(*) FROM portal_seo_build_evidence_nonces'
)->fetchColumn()===508,'read-only attempt changed ledger');

$one=$review->purgeExpired($a,$now);
expect124(is_array($one)&&$one['purgedRows']===500&&
    $one['maximumBatch']===500&&
    $one['gracePeriodSeconds']===3600,
    'batch one did not delete exactly 500');
$two=$review->purgeExpired($a,$now);
expect124(is_array($two)&&$two['purgedRows']===2,
    'batch two did not delete remaining two old records');
$three=$review->purgeExpired($a,$now);
expect124(is_array($three)&&$three['purgedRows']===0,
    'idempotent cleanup deleted unexpired or grace-window data');
foreach(['realTenantIdentityVerified','operationalSchedulerDeployed',
    'retentionPolicyApproved','customerDisplayAuthorized','publicationAuthorized']
    as $flag)expect124($one[$flag]===false,'false release gate '.$flag);

$adminCount=(int)$admin->query(
    'SELECT COUNT(*) FROM portal_seo_build_evidence_nonces'
)->fetchColumn();
expect124($adminCount===6,'unexpected data removed after own-tenant cleanup');

$scope=new Scope($maintainer);
$demoCount=$scope->run($a,static fn(PDO $db)=>
    (int)$db->query('SELECT COUNT(*) FROM portal_seo_build_evidence_nonces')
    ->fetchColumn()
);
$otherCount=$scope->run($b,static fn(PDO $db)=>
    (int)$db->query('SELECT COUNT(*) FROM portal_seo_build_evidence_nonces')
    ->fetchColumn()
);
expect124($demoCount===5 && $otherCount===1,
    'RLS did not isolate tenant-scoped retention');
expect124((int)$maintainer->query(
    'SELECT COUNT(*) FROM portal_seo_build_evidence_nonces'
)->fetchColumn()===0,'RLS tenant GUC persisted across transactions');

$other=$review->purgeExpired($b,$now);
expect124(is_array($other)&&$other['purgedRows']===1,
    'separate tenant cleanup not isolated');
expect124((int)$admin->query(
    'SELECT COUNT(*) FROM portal_seo_build_evidence_nonces'
)->fetchColumn()===5,'another tenant cleanup modified demo');

putenv('APP_ENV=production');
expect124($review->purgeExpired($a,$now)===null,
    'production mode executed laboratory retention');
expect124((int)$admin->query(
    'SELECT COUNT(*) FROM portal_seo_build_evidence_nonces'
)->fetchColumn()===5,'production guard deleted data');
expect124(!Retention::authorizesDeletionInProduction()&&
    !Retention::authorizesCustomerDisplay(),
    'retention class grants production or customer authority');
echo "F124 PG18: 500+2 bounded old rows deleted, grace/active protected, ".
     "other tenant isolated, readonly denied, no production authority\n";
