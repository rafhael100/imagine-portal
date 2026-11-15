<?php
declare(strict_types=1);

/**
 * SEO-F108 — disposable PostgreSQL18 integration lab.
 *
 * Runs only under explicit CI/test gates, seeded SYNTHETIC tenants and the
 * existing Laravel migration schemas. Does not touch production or VPS.
 * A non-bypass SQL role is not a proof of a trusted Portal session issuer.
 */
use App\Domain\SeoReview\GoogleSearchConsoleTenantGrantGateway;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use App\Domain\SeoReview\PgsqlRecordedGscGrantReviewSource;

require_once __DIR__.'/../../vendor/autoload.php';

function fail(string $message): never { throw new RuntimeException('F108 CI: '.$message); }
function verify(bool $ok, string $message): void { if (!$ok) fail($message); }
function db(string $user, string $password): PDO
{
    $pdo=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    return $pdo;
}
function inspect(PDO $pdo,string $tenant,string $actor,string $site,int $now): ?array
{
    return (new GoogleSearchConsoleTenantGrantGateway($pdo))
        ->inspectRecordedGrant(['tenantRef'=>$tenant,'actorRef'=>$actor],$site,$now);
}
function countRows(PDO $db,string $table): int
{
    $allowed=['portal_gsc_property_grants','portal_gsc_security_events'];
    verify(in_array($table,$allowed,true),'unsafe test table');
    return (int)$db->query('SELECT count(*) FROM '.$table)->fetchColumn();
}
function scoped(PDO $pdo,string $tenant,callable $check): void
{
    $pdo->beginTransaction();
    try{
        $statement=$pdo->prepare("SELECT set_config('app.tenant_ref',:tenant,true)");
        $statement->execute(['tenant'=>$tenant]);
        verify($statement->fetchColumn()===$tenant,'transaction-local scope refused');
        $check($pdo);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_SEO_F108_PG_CI')!=='1' ||
    getenv('CI')!=='true' ||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
    getenv('DB_USERNAME')!=='postgres' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('POSTGRES_PASSWORD')!=='f108-ci-postgres-only' ||
    getenv('F108_ROLE_PASSWORD')!=='f108-ci-role-only') {
    fail('disposable PostgreSQL CI required');
}

$now=1800000000; // synthetic date
$site='sc-domain:example.com';
$otherSite='sc-domain:other.example';
$fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint($site);
verify(is_string($fingerprint),'synthetic fingerprint unavailable');
$admin=db('postgres',getenv('POSTGRES_PASSWORD'));

// These roles exist only inside the disposable GitHub Actions service.
// Provision fixture credentials solely for localhost and only in this CI.
foreach (['portal_f108_service','portal_f108_noaudit'] as $role){
    $admin->exec('CREATE ROLE '.$role.' LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD '.
        $admin->quote(getenv('F108_ROLE_PASSWORD')));
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT, UPDATE ON public.portal_gsc_property_grants TO '.$role);
    $admin->exec('GRANT SELECT ON public.portal_gsc_security_events TO '.$role);
}
$admin->exec('GRANT INSERT ON public.portal_gsc_security_events TO portal_f108_service');

foreach (['portal_gsc_property_grants','portal_gsc_security_events'] as $table){
    $row=$admin->query("SELECT c.relrowsecurity, c.relforcerowsecurity
        FROM pg_class c WHERE c.oid='public.".$table."'::regclass")->fetch();
    verify($row!==false && in_array($row['relrowsecurity'],[true,'t'],true)
        && in_array($row['relforcerowsecurity'],[true,'t'],true),
        'FORCE RLS missing on '.$table);
}
function seedGrant(PDO $admin,string $tenant,string $actor,string $fingerprint,int $now):void{
    $stmt=$admin->prepare('INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
         verified_at,expires_at,revoked_at)
        VALUES (:tenant,:actor,:fingerprint,:scope,:status,:verified,:expiry,NULL)');
    $stmt->execute([
        'tenant'=>$tenant,'actor'=>$actor,'fingerprint'=>$fingerprint,
        'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
        'status'=>'active','verified'=>$now-50,'expiry'=>$now+600
    ]);
}
seedGrant($admin,'demo-tenant','portal-user-a',$fingerprint,$now);
seedGrant($admin,'other-tenant','portal-user-a',$fingerprint,$now);
seedGrant($admin,'other-tenant','portal-user-a',
    GoogleSearchConsolePropertyIdentifier::fingerprint($otherSite),$now);

$service=db('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$separate=db('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$noaudit=db('portal_f108_noaudit',getenv('F108_ROLE_PASSWORD'));
verify($service->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql','wrong DB driver');
$serverVersion=$admin->query('SHOW server_version_num')->fetchColumn();
verify((int)$serverVersion>=180000 && (int)$serverVersion<190000,'requires PostgreSQL 18');
foreach ([$service,$separate,$noaudit] as $pdo){
    $r=$pdo->query("SELECT rolsuper,rolbypassrls FROM pg_roles
        WHERE rolname=current_user")->fetch();
    verify($r!==false && in_array($r['rolsuper'],[false,'f'],true) &&
        in_array($r['rolbypassrls'],[false,'f'],true),
        'must be non-superuser with no BYPASSRLS');
}

// No tenant set outside a trusted transaction: no rows visible.
verify(countRows($service,'portal_gsc_property_grants')===0,
    'tenant unset leaked records');
verify(countRows($service,'portal_gsc_security_events')===0,
    'audit ledger visible without tenant');

// Two simultaneous connections: each transaction sees its own tenant only.
$service->beginTransaction();
$separate->beginTransaction();
try{
    $setA=$service->prepare("SELECT set_config('app.tenant_ref',:tenant,true)");
    $setB=$separate->prepare("SELECT set_config('app.tenant_ref',:tenant,true)");
    $setA->execute(['tenant'=>'demo-tenant']);
    $setB->execute(['tenant'=>'other-tenant']);
    verify(countRows($service,'portal_gsc_property_grants')===1,
        'tenant A saw another tenant');
    verify(countRows($separate,'portal_gsc_property_grants')===2,
        'tenant B saw another tenant');
    verify(countRows($service,'portal_gsc_security_events')===0,
        'tenant A audit leak');
    $service->commit();
    verify(countRows($service,'portal_gsc_property_grants')===0,
        'tenant GUC persisted after commit');
    verify(countRows($separate,'portal_gsc_property_grants')===2,
        'tenant B scope lost during A commit');
    $separate->commit();
}catch(Throwable $e){
    foreach([$service,$separate] as $conn){
        if($conn->inTransaction())$conn->rollBack();
    }
    throw $e;
}
verify(countRows($separate,'portal_gsc_property_grants')===0,
    'tenant B GUC persisted after commit');

// Gateway scope must not allow a cross-tenant query even with identical
// actor and fingerprint; exact property identity still matters.
$grantA=inspect($service,'demo-tenant','portal-user-a',$site,$now);
verify(is_array($grantA) && $grantA['recordedGrantFresh']===true,
    'real PostgreSQL grant lookup refused');
verify($grantA['customerDisplayAuthorized']===false &&
    $grantA['propertyAuthorizationVerified']===false &&
    $grantA['googleApiCheckedNow']===false,
    'stored grant falsely claimed Google authorization');
verify(inspect($service,'demo-tenant','portal-user-a',$otherSite,$now)===null,
    'wrong property accepted');
verify(inspect($service,'demo-tenant','other-actor',$site,$now)===null,
    'wrong actor accepted');
verify(inspect($service,'foreign-tenant','portal-user-a',$site,$now)===null,
    'nonexistent tenant accepted');
verify(inspect($service,'demo-tenant','portal-user-a','https://example.com/',$now)===null,
    'URL prefix confused with domain property');
verify(inspect($admin,'demo-tenant','portal-user-a',$site,$now)===null,
    'superuser bypassed explicit gateway guard');

// Already-open transactions are refused, not implicitly reused.
$service->beginTransaction();
try{
    verify(inspect($service,'demo-tenant','portal-user-a',$site,$now)===null,
        'existing transaction accepted by gateway');
}finally{$service->rollBack();}

// Revocation must roll back when audit INSERT is not permitted.
// The no-audit role can UPDATE but cannot write an audit entry.
$noAuditGateway=new GoogleSearchConsoleTenantGrantGateway($noaudit);
verify($noAuditGateway->revokeRecordedGrantWithAudit(
    ['tenantRef'=>'demo-tenant','actorRef'=>'portal-user-a'],$site,$now)===false,
    'unaudited revocation should fail');
verify(is_array(inspect($service,'demo-tenant','portal-user-a',$site,$now)),
    'unaudited revocation was not rolled back');
verify(countRows($service,'portal_gsc_property_grants')===0,
    'transaction-local tenant leaked following failed revocation');

// Wrong-tenant/actor revocation must fail and preserve both grants.
$gateway=new GoogleSearchConsoleTenantGrantGateway($service);
verify($gateway->revokeRecordedGrantWithAudit(
    ['tenantRef'=>'other-tenant','actorRef'=>'other-actor'],$site,$now)===false,
    'wrong actor revoked grant');
verify(is_array(inspect($service,'demo-tenant','portal-user-a',$site,$now)),
    'wrong tenant revocation changed tenant A');

// Successful audited revocation is atomic and one-use.
verify($gateway->revokeRecordedGrantWithAudit(
    ['tenantRef'=>'demo-tenant','actorRef'=>'portal-user-a'],$site,$now)===true,
    'audited revocation failed');
verify(inspect($service,'demo-tenant','portal-user-a',$site,$now)===null,
    'revoked property still readable');
verify($gateway->revokeRecordedGrantWithAudit(
    ['tenantRef'=>'demo-tenant','actorRef'=>'portal-user-a'],$site,$now)===false,
    'replayed revocation accepted');
verify(is_array(inspect($service,'other-tenant','portal-user-a',$site,$now)),
    'tenant B grant changed during tenant A revocation');
verify(countRows($service,'portal_gsc_security_events')===0,
    'audit scope persists between calls');
scoped($service,'demo-tenant',function(PDO $pdo):void{
    verify(countRows($pdo,'portal_gsc_security_events')===1,
        'audited event was not inserted once');
    verify(countRows($pdo,'portal_gsc_property_grants')===1,
        'revocation removed grant instead of marking it');
});
scoped($separate,'other-tenant',function(PDO $pdo):void{
    verify(countRows($pdo,'portal_gsc_security_events')===0,
        'tenant B can read tenant A audit events');
    verify(countRows($pdo,'portal_gsc_property_grants')===2,
        'tenant B property rows unexpectedly changed');
});
verify(countRows($service,'portal_gsc_security_events')===0,
    'scope persisted after last committed audit read');
verify(GoogleSearchConsoleTenantGrantGateway::authorizesGoogleHttp()===false &&
    GoogleSearchConsoleTenantGrantGateway::authorizesCustomerDisplay()===false,
    'gateway wrongly claims production authorization');

echo "F108 PostgreSQL18: migrations/RLS, two isolated sessions, transaction reset,\n".
     "fresh grant lookup, wrong scope, audit rollback and one-use revocation PASS;\n".
     "no provider calls, no credentials printed, no production authorization\n";
