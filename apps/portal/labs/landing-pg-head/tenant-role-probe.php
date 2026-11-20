<?php
declare(strict_types=1);

// Disposable PostgreSQL 18 fixture. All roles, passwords and actors are
// synthetic CI values; no operational Portal issuer, S2S or authorization.
require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';
use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate as PgHead;

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=postgres';
$admin=new PDO($dsn,'postgres','ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec(file_get_contents(__DIR__.'/schema.sql'));
foreach (['a','b','unbound'] as $role) {
    $admin->exec("CREATE ROLE portal_landing_ci_$role LOGIN PASSWORD 'ci-only-synthetic-$role'");
    $admin->exec("GRANT USAGE ON SCHEMA portal_landing_candidate TO portal_landing_ci_$role");
    $admin->exec("GRANT SELECT ON ALL TABLES IN SCHEMA portal_landing_candidate TO portal_landing_ci_$role");
}
foreach (['brief_revisions','approval_events'] as $table) {
    foreach (['a'=>'tenant-A','b'=>'tenant-B'] as $role=>$tenant) {
        $admin->exec("CREATE POLICY ci_{$role}_select ON portal_landing_candidate.$table
            FOR SELECT TO portal_landing_ci_$role USING (tenant_ref = '$tenant')");
    }
}
$connect=static function(string $role)use($dsn):PDO{
    return new PDO($dsn,"portal_landing_ci_$role","ci-only-synthetic-$role",
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
};
$roleA=$connect('a');$roleB=$connect('b');$unbound=$connect('unbound');
$readerA=new PgHead($roleA);$readerB=new PgHead($roleB);$readerUnbound=new PgHead($unbound);
$site='11111111-1111-4111-8111-111111111111';
$body='{"headline":"synthetic tenant-scoped fixture"}';
$digest=hash('sha256',$body);
$revision=$admin->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.brief_revisions
(tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
 source_sha256,source_bytes,state,approval_ref,approval_epoch,approved_actor_ref)
VALUES (:tenant,'order-A',:site,'contract-A',7,:version,:digest,:body,
 'approved',:approval,:version,:actor)
SQL);
$event=$admin->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events
(event_ref,tenant_ref,order_ref,site_ref,source_version,approval_epoch,
 approval_ref,actor_ref,event_type)
VALUES (:ref,:tenant,'order-A',:site,:version,:version,:approval,:actor,:kind)
SQL);
$seed=static function(string $tenant,int $version)use($revision,$event,$site,$body,$digest):void{
    $approval="approval-$tenant-$version";$actor="actor-$tenant";
    $revision->execute(['tenant'=>$tenant,'site'=>$site,'version'=>$version,
        'digest'=>$digest,'body'=>$body,'approval'=>$approval,'actor'=>$actor]);
    $event->execute(['ref'=>"$tenant-$version-approved",'tenant'=>$tenant,
        'site'=>$site,'version'=>$version,'approval'=>$approval,
        'actor'=>$actor,'kind'=>'approved']);
};
$checks=0;
$ok=static function(bool $condition,string $label)use(&$checks):void{
    if(!$condition)throw new RuntimeException('PORTAL LANDING PG18 RLS ROLE FAILURE: '.$label);
    ++$checks;
};
$denySql=static function(callable $action):bool{
    try{$action();}catch(PDOException){return true;}
    return false;
};
$ok($readerUnbound->readCurrent('tenant-A','order-A',$site)===null,
    'SELECT grant alone never bypasses FORCE RLS without policy');
$ok($roleA->query('SELECT current_user')->fetchColumn()==='portal_landing_ci_a',
    'reader A uses real non-owner connection');
$ok($roleB->query('SELECT current_user')->fetchColumn()==='portal_landing_ci_b',
    'reader B uses different real non-owner connection');
foreach ([$roleA,$roleB] as $role) {
    $bypass=$role->query('SELECT rolbypassrls FROM pg_roles WHERE rolname=current_user')->fetchColumn();
    $ok(in_array($bypass,[false,'f','0'],true),'nonowner role has no BYPASSRLS');
}
$seed('tenant-A',1);$seed('tenant-B',1);
$a=$readerA->readCurrent('tenant-A','order-A',$site);
$b=$readerB->readCurrent('tenant-B','order-A',$site);
$ok($a?->sourceVersion===1 && $a->sourceBytes()===$body,'A can read approved A head');
$ok($b?->sourceVersion===1 && $b->sourceBytes()===$body,'B can read approved B head');
$ok($readerA->readCurrent('tenant-B','order-A',$site)===null,'A selector cannot request B');
$ok($readerB->readCurrent('tenant-A','order-A',$site)===null,'B selector cannot request A');
$ok($readerUnbound->readCurrent('tenant-B','order-A',$site)===null,
    'unbound role remains denied after rows exist');
$ok($readerA->readCurrent('tenant-A','order-B',$site)===null,'order mismatch denied');
$ok($readerA->readCurrent('tenant-A','order-A',
    '22222222-2222-4222-8222-222222222222')===null,'site mismatch denied');
$ok($denySql(static fn()=>$roleA->exec('SET ROLE portal_landing_ci_b')),
    'A cannot assume B database role');
$ok($denySql(static fn()=>$roleA->exec(
    "UPDATE portal_landing_candidate.brief_revisions SET state='revoked'")),
    'reader cannot update briefing');
$ok($denySql(static fn()=>$roleB->exec(
    "INSERT INTO portal_landing_candidate.approval_events(event_ref) VALUES ('forged')")),
    'reader cannot forge approval event');
$event->execute(['ref'=>'tenant-A-1-revoked','tenant'=>'tenant-A','site'=>$site,
    'version'=>1,'approval'=>'approval-tenant-A-1','actor'=>'actor-tenant-A',
    'kind'=>'revoked']);
$ok($readerA->readCurrent('tenant-A','order-A',$site)===null,
    'revocation blocks head for scoped tenant');
$ok($readerB->readCurrent('tenant-B','order-A',$site)?->sourceVersion===1,
    'A revocation does not touch B head');
$seed('tenant-A',2);
$ok($readerA->readCurrent('tenant-A','order-A',$site)?->sourceVersion===2,
    'new distinct approval works');
$ok($readerB->readCurrent('tenant-A','order-A',$site)===null,
    'new A approval never leaks to B');
$ok(!$roleA->inTransaction() && !$roleB->inTransaction() &&
    !$unbound->inTransaction(),'read-only transactions all closed');
$ok((int)$admin->query('SELECT count(*) FROM portal_landing_candidate.approval_events')
    ->fetchColumn()===4,'reader did not create audit events');
$admin->exec('DROP SCHEMA portal_landing_candidate CASCADE');
echo "PORTAL LANDING PG18 RLS ROLE PASS: $checks checks; disposable PG18 with synthetic policies only.\n";
