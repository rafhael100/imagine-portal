<?php
declare(strict_types=1);

/**
 * F109: real PostgreSQL18 two-connection row-lock contention.
 * Executes ONLY AFTER F108 seed/migration test in disposable Actions job.
 */
use App\Domain\SeoReview\GoogleSearchConsoleTenantGrantGateway;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;

require_once __DIR__.'/../../vendor/autoload.php';

function must(bool $condition, string $message):void {
    if(!$condition)throw new RuntimeException('F109 disposable CI: '.$message);
}
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('CI')!=='true' ||
    getenv('PORTAL_SEO_F108_PG_CI')!=='1' ||
    getenv('PORTAL_SEO_F109_PG_CI')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
    getenv('F108_ROLE_PASSWORD')!=='f108-ci-role-only' ||
    getenv('POSTGRES_PASSWORD')!=='f108-ci-postgres-only')
    throw new RuntimeException('F109 requires isolated PostgreSQL CI fixture');

$fixturePassword=getenv('F108_ROLE_PASSWORD');
function connect(string $user,string $password):PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
}
$locker=connect('portal_f108_service',$fixturePassword);
$contender=connect('portal_f108_service',$fixturePassword);
$admin=connect('postgres',getenv('POSTGRES_PASSWORD'));
$now=1800000000;
$property='sc-domain:other.example';
$fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint($property);
must(is_string($fingerprint),'invalid synthetic property');
$actorContext=['tenantRef'=>'other-tenant','actorRef'=>'portal-user-a'];

function state(PDO $db,string $fingerprint):array {
    $query=$db->prepare('SELECT grant_status,revoked_at FROM portal_gsc_property_grants
        WHERE tenant_ref=:tenant AND actor_ref=:actor AND property_fingerprint=:property');
    $query->execute([
        'tenant'=>'other-tenant',
        'actor'=>'portal-user-a',
        'property'=>$fingerprint
    ]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    must(is_array($row),'synthetic grant missing');
    return $row;
}
function events(PDO $db,string $fingerprint):int {
    $stmt=$db->prepare('SELECT count(*) FROM portal_gsc_security_events
        WHERE tenant_ref=:tenant AND actor_ref=:actor
        AND property_fingerprint=:property
        AND event_code=:event');
    $stmt->execute([
        'tenant'=>'other-tenant','actor'=>'portal-user-a',
        'property'=>$fingerprint,'event'=>'property_grant_revoked'
    ]);
    return (int)$stmt->fetchColumn();
}
must(state($admin,$fingerprint)['grant_status']==='active',
    'F108 prerequisite grant already revoked');
must(events($admin,$fingerprint)===0,'F108 prerequisite audit already exists');

// Connection A locks one row for tenant B inside its RLS transaction.
// Connection B tries the actual audited revocation and must time out
// without an UPDATE or audit commit.
$locker->beginTransaction();
try{
    $set=$locker->prepare("SELECT set_config('app.tenant_ref',:tenant,true)");
    $set->execute(['tenant'=>'other-tenant']);
    must($set->fetchColumn()==='other-tenant','lock context unavailable');

    $lock=$locker->prepare('SELECT property_fingerprint
        FROM portal_gsc_property_grants
        WHERE tenant_ref=:tenant AND actor_ref=:actor
          AND property_fingerprint=:property AND grant_status=:active
        FOR UPDATE');
    $lock->execute([
        'tenant'=>'other-tenant','actor'=>'portal-user-a',
        'property'=>$fingerprint,'active'=>'active'
    ]);
    must($lock->fetchColumn()===$fingerprint,'could not lock target grant');

    $contender->exec("SET lock_timeout TO '500ms'");
    $gateway=new GoogleSearchConsoleTenantGrantGateway($contender);
    $first=$gateway->revokeRecordedGrantWithAudit($actorContext,$property,$now);
    must($first===false,'contended revocation unexpectedly committed');
    must(!$contender->inTransaction(),'contender transaction not rolled back');
    must(state($admin,$fingerprint)['grant_status']==='active',
        'grant changed while locked revocation was denied');
    must(events($admin,$fingerprint)===0,
        'audit inserted for denied contended revocation');

    $locker->commit();
}catch(Throwable $e){
    if($locker->inTransaction())$locker->rollBack();
    throw $e;
}
must(!$locker->inTransaction(),'locker transaction not committed');
$contender->exec("SET lock_timeout TO '2s'");

// Once A releases the row lock, the same real gateway must be able to
// atomically revoke + audit exactly once.
$gateway=new GoogleSearchConsoleTenantGrantGateway($contender);
must($gateway->revokeRecordedGrantWithAudit($actorContext,$property,$now),
    'unlocked audited revocation failed');
must(!$gateway->revokeRecordedGrantWithAudit($actorContext,$property,$now),
    'second revocation accepted after unlocked commit');
must(state($admin,$fingerprint)['grant_status']==='revoked',
    'target grant not revoked on successful commit');
must(events($admin,$fingerprint)===1,'expected one audit event after revocation');

// Other tenant and other property in the same tenant remain untouched.
$otherTenant=$admin->prepare('SELECT grant_status FROM portal_gsc_property_grants
    WHERE tenant_ref=:tenant AND property_fingerprint=:property');
$otherTenant->execute([
    'tenant'=>'demo-tenant',
    'property'=>GoogleSearchConsolePropertyIdentifier::fingerprint(
        'sc-domain:example.com')
]);
must($otherTenant->fetchColumn()==='revoked',
    'F108 revoked tenant A unexpectedly changed after F109 contention');
$otherProperty=$admin->prepare('SELECT grant_status FROM portal_gsc_property_grants
    WHERE tenant_ref=:tenant AND property_fingerprint=:property');
$otherProperty->execute([
    'tenant'=>'other-tenant',
    'property'=>GoogleSearchConsolePropertyIdentifier::fingerprint(
        'sc-domain:example.com')
]);
must($otherProperty->fetchColumn()==='active',
    'unrelated property changed by contended revocation');

// Transaction-local tenant setting is cleared on both DB connections.
must((int)$locker->query('SELECT count(*) FROM portal_gsc_property_grants')
    ->fetchColumn()===0,'locker tenant scope leaked after commit');
must((int)$contender->query('SELECT count(*) FROM portal_gsc_property_grants')
    ->fetchColumn()===0,'contender tenant scope leaked after rollback/commit');

echo "F109 PG18: row lock contention denied with rollback;\n".
     "post-lock audited revocation committed once, other property preserved;\n".
     "both transaction-local RLS contexts reset; no production authority\n";
