<?php
declare(strict_types=1);

// POSTGRES 18 DISPOSABLE LAB ONLY. All refs/bytes are synthetic.
// No app route, real Portal actor, migration, tenant RLS grant or deploy.
require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';

use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate as PgHead;

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=postgres';
$admin = new PDO($dsn, 'postgres', 'ci-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec(file_get_contents(__DIR__.'/schema.sql'));
$admin->exec("CREATE ROLE portal_landing_ci_reader LOGIN PASSWORD 'ci-reader-only'");
$admin->exec('GRANT USAGE ON SCHEMA portal_landing_candidate TO portal_landing_ci_reader');
$admin->exec('GRANT SELECT ON ALL TABLES IN SCHEMA portal_landing_candidate TO portal_landing_ci_reader');
$readOnly = new PDO($dsn,'portal_landing_ci_reader','ci-reader-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$reader = new PgHead($admin); // owner in CI bypasses FORCE RLS; NOT an operational role
$restricted = new PgHead($readOnly);
$checks=0;
$ok=static function(bool $condition,string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('PG18 LANDING LAB FAILURE: '.$label);
    ++$checks;
};
$site='11111111-1111-4111-8111-111111111111';
$bytes='{"headline":"somente fixture"}';
$sha=hash('sha256',$bytes);
$revisions=$admin->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.brief_revisions
(tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
 source_sha256,source_bytes,state,approval_ref,approval_epoch,approved_actor_ref)
VALUES (:tenant,:order_ref,:site,:contract,7,:version,:digest,:bytes,:state,:approval,:epoch,:actor)
SQL);
$insert=static function(string $tenant,int $version,string $state='approved',
    string $actor='actor-A',?string $body=null) use ($revisions,$site,$bytes): void {
    $source=$body ?? $bytes;
    $revisions->execute([
        'tenant'=>$tenant,'order_ref'=>'order-A','site'=>$site,'contract'=>'contract-A',
        'version'=>$version,'digest'=>hash('sha256',$source),'bytes'=>$source,
        'state'=>$state,'approval'=>"approval-$tenant-$version",'epoch'=>$version,'actor'=>$actor,
    ]);
};
$events=$admin->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events
(event_ref,tenant_ref,order_ref,site_ref,source_version,approval_epoch,approval_ref,actor_ref,event_type)
VALUES (:event_ref,:tenant,:order_ref,:site,:version,:epoch,:approval,:actor,:event_type)
SQL);
$event=static function(string $tenant,int $version,string $kind='approved',
    string $actor='actor-A') use ($events,$site): void {
    $events->execute([
        'event_ref'=>"$tenant-$version-$kind",'tenant'=>$tenant,
        'order_ref'=>'order-A','site'=>$site,'version'=>$version,'epoch'=>$version,
        'approval'=>"approval-$tenant-$version",'actor'=>$actor,'event_type'=>$kind,
    ]);
};
$read=static fn(string $tenant='tenant-A',string $order='order-A',?string $siteRef=null)
    =>$reader->readCurrent($tenant,$order,$siteRef ?? $site);
$ok($read()===null,'missing head denies');
$insert('tenant-A',1);$event('tenant-A',1);
$first=$read();
$ok($first!==null && $first->sourceVersion===1 && $first->approvalEpoch===1,'approved version 1 read');
$ok(hash_equals($sha,$first->sourceSha256)&&$first->sourceBytes()===$bytes,
    'SHA-256 and exact private source bytes');
$ok(!$admin->inTransaction(),'reader closes its PG snapshot');
$ok($read('tenant-B')===null,'cross-tenant denies');
$ok($read('tenant-A','order-B')===null,'cross-order denies');
$ok($read('tenant-A','order-A','22222222-2222-4222-8222-222222222222')===null,
    'cross-site denies');
$insert('tenant-B',1,'approved','actor-B','{"headline":"tenant B"}');$event('tenant-B',1,'approved','actor-B');
$ok($read('tenant-B')?->sourceBytes()==='{"headline":"tenant B"}','second tenant isolated');
$ok($read()?->sourceBytes()===$bytes,'second tenant did not mutate first tenant head');

$admin->exec("UPDATE portal_landing_candidate.brief_revisions SET source_sha256='".str_repeat('0',64)."'
 WHERE tenant_ref='tenant-A' AND source_version=1");
$redacted=false;
try{$read();}catch(RuntimeException $e){$redacted=$e->getMessage()==='PORTAL_LANDING_PG_HEAD_DENIED'
 && $e->getPrevious()===null;}
$ok($redacted,'corrupted stored hash denies with a redacted error');
$ok(!$admin->inTransaction(),'failure rolls back read transaction');
$admin->exec("UPDATE portal_landing_candidate.brief_revisions SET source_sha256='$sha'
 WHERE tenant_ref='tenant-A' AND source_version=1");
$ok($read()?->sourceVersion===1,'restored legitimate head works');

$insert('tenant-A',2,'draft');
$ok($read()===null,'newer draft blocks older approved version');
$admin->exec("UPDATE portal_landing_candidate.brief_revisions SET state='approved'
 WHERE tenant_ref='tenant-A' AND source_version=2");
$ok($read()===null,'approved flag without an audit event never authorizes');
$event('tenant-A',2);
$ok($read()?->sourceVersion===2,'latest version requires matching approval event');

$admin->exec("UPDATE portal_landing_candidate.brief_revisions SET approved_actor_ref='actor-forged'
 WHERE tenant_ref='tenant-A' AND source_version=2");
$ok($read()===null,'actor mismatch denies');
$admin->exec("UPDATE portal_landing_candidate.brief_revisions SET approved_actor_ref='actor-A'
 WHERE tenant_ref='tenant-A' AND source_version=2");
$admin->exec("UPDATE portal_landing_candidate.brief_revisions SET approval_epoch=3
 WHERE tenant_ref='tenant-A' AND source_version=2");
$ok($read()===null,'epoch replay mismatch denies');
$admin->exec("UPDATE portal_landing_candidate.brief_revisions SET approval_epoch=2
 WHERE tenant_ref='tenant-A' AND source_version=2");
$ok($read()?->sourceVersion===2,'restored actor and epoch read');
$event('tenant-A',2,'revoked');
$ok($read()===null,'append-only revocation event denies even if row says approved');
$ok($read('tenant-B')?->sourceVersion===1,'revocation cannot cross tenant');

$duplicatesDenied=false;
try{$event('tenant-A',2,'approved');}catch(PDOException){$duplicatesDenied=true;}
$ok($duplicatesDenied,'unique audit event prevents replay inserts');
$ok($restricted->readCurrent('tenant-A','order-A',$site)===null,
    'FORCE RLS without policy denies unprivileged SELECT despite grant');
$writeDenied=false;
try{$readOnly->exec("UPDATE portal_landing_candidate.brief_revisions SET state='revoked'");}
catch(PDOException){$writeDenied=true;}
$ok($writeDenied,'unprivileged reader cannot write');
$admin->beginTransaction();
$nestedDenied=false;
try{$read();}catch(RuntimeException $e){$nestedDenied=$e->getMessage()==='PORTAL_LANDING_PG_HEAD_DENIED';}
$ok($nestedDenied,'external transaction context rejected');
$admin->rollBack();
$ok($read('tenant-A\' OR 1=1 --')===null,'SQL injection selector has no effect');

echo 'PORTAL LANDING PG18 ATOMIC HEAD PASS: '.$checks.
 ' checks; disposable fixture with admin bypass only, NOT an operational actor/ledger.'."\n";
