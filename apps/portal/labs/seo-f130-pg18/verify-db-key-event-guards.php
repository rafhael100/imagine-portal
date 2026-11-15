<?php
declare(strict_types=1);

/**
 * F130: actual PostgreSQL18 trigger-backed signer-key lifecycle and
 * append-only audit defense against unlogged/direct SQL mutation.
 * All test actors are synthetic, with disposable non-bypass roles.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgSigningKeyRevocationLab as Revoke;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

function check130(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('F130 PG18: '.$message);
}
if (PHP_SAPI!=='cli' ||
    getenv('CI')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_SEO_F128_PG_CI')!=='1' ||
    getenv('PORTAL_SEO_F130_PG_CI')!=='1' ||
    getenv('DB_CONNECTION')!=='pgsql' ||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('F130_ROLE_PASSWORD')!=='f130-ci-role-only')
    throw new RuntimeException('F130: disposable PostgreSQL CI required');

function db130(string $user,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]
    );
}
const KEY_TABLE_130='portal_seo_build_signing_keys';
const AUDIT_TABLE_130='portal_seo_build_signer_events';
const SIGNER_130='seo-engine-ci-lab';

$admin=db130('postgres',getenv('POSTGRES_PASSWORD'));
$admin->exec("CREATE ROLE portal_f130_mutator LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD 'f130-ci-role-only'");
$admin->exec("CREATE ROLE portal_f130_app LOGIN NOSUPERUSER
    NOBYPASSRLS NOINHERIT PASSWORD 'f130-ci-role-only'");
foreach(['portal_f130_mutator','portal_f130_app'] as $role){
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON '.KEY_TABLE_130.' TO '.$role);
    $admin->exec('GRANT SELECT ON '.AUDIT_TABLE_130.' TO '.$role);
    $admin->exec('GRANT UPDATE ON '.KEY_TABLE_130.' TO '.$role);
    $admin->exec('GRANT INSERT ON '.AUDIT_TABLE_130.' TO '.$role);
    $q=$admin->prepare(
        'SELECT rolsuper,rolbypassrls FROM pg_roles WHERE rolname=:name'
    );
    $q->execute(['name'=>$role]);
    $state=$q->fetch(PDO::FETCH_ASSOC);
    check130(is_array($state)&&
        in_array($state['rolsuper'],[false,'f','0',0],true)&&
        in_array($state['rolbypassrls'],[false,'f','0',0],true),
        'role is not subject to tenant RLS');
}
$admin->exec('GRANT DELETE ON '.KEY_TABLE_130.'
    TO portal_f130_mutator');
$admin->exec('GRANT UPDATE,DELETE ON '.AUDIT_TABLE_130.'
    TO portal_f130_mutator');
$mutator=db130('portal_f130_mutator',getenv('F130_ROLE_PASSWORD'));
$app=db130('portal_f130_app',getenv('F130_ROLE_PASSWORD'));
$scope=new Scope($mutator);
$appScope=new Scope($app);
$tenant=['tenantRef'=>'guard-tenant','actorRef'=>'portal-f130-operator'];
$other=['tenantRef'=>'other-tenant','actorRef'=>'portal-f130-operator'];
$now=time();
$keys=[
    hash('sha256',str_repeat('K',32)),
    hash('sha256',str_repeat('L',32)),
    hash('sha256',str_repeat('M',32))
];
$insert=$admin->prepare(
    "INSERT INTO ".KEY_TABLE_130."
    (tenant_ref,signer_ref,public_key_fingerprint,public_key_base64,
     key_status,not_before,expires_at,revoked_at)
    VALUES (:tenant,:signer,:fp,:pub,'active',:start,:end,NULL)"
);
foreach($keys as $i=>$key){
    $insert->execute([
        'tenant'=>'guard-tenant','signer'=>SIGNER_130,
        'fp'=>$key,'pub'=>base64_encode(str_repeat(chr(75+$i),32)),
        'start'=>$now-60,'end'=>$now+3600
    ]);
}
$insert->execute([
    'tenant'=>'other-tenant','signer'=>SIGNER_130,
    'fp'=>$keys[0],'pub'=>base64_encode(str_repeat('K',32)),
    'start'=>$now-60,'end'=>$now+3600
]);
function status130(PDO $db,string $tenant,string $fingerprint):array {
    $stmt=$db->prepare(
        'SELECT key_status,revoked_at,public_key_base64
         FROM '.KEY_TABLE_130.' WHERE tenant_ref=:tenant
         AND public_key_fingerprint=:fingerprint'
    );
    $stmt->execute(['tenant'=>$tenant,'fingerprint'=>$fingerprint]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    check130(is_array($row),'fixture key absent');
    return $row;
}
function events130(PDO $db,string $tenant):int {
    $s=$db->prepare('SELECT COUNT(*) FROM '.AUDIT_TABLE_130.
        ' WHERE tenant_ref=:tenant');
    $s->execute(['tenant'=>$tenant]);
    return (int)$s->fetchColumn();
}
foreach([KEY_TABLE_130,AUDIT_TABLE_130] as $table){
    $state=$admin->query(
        "SELECT relrowsecurity,relforcerowsecurity FROM pg_class
         WHERE oid='public.".$table."'::regclass"
    )->fetch(PDO::FETCH_ASSOC);
    check130(is_array($state)&&
        in_array($state['relrowsecurity'],['t',true],true)&&
        in_array($state['relforcerowsecurity'],['t',true],true),
        'FORCE RLS missing');
}
$triggers=$admin->query(
    "SELECT tgname,tgenabled FROM pg_trigger
     WHERE tgrelid IN (
        'public.portal_seo_build_signing_keys'::regclass,
        'public.portal_seo_build_signer_events'::regclass
     ) AND tgname LIKE 'seo_f130_%'"
)->fetchAll(PDO::FETCH_ASSOC);
check130(count($triggers)===4,'expected four database integrity triggers');
foreach($triggers as $trigger)
    check130($trigger['tgenabled']==='O',
        'a key or audit guard trigger is disabled');

// Direct SQL UPDATE, even under RLS, CANNOT commit a revocation without the
// corresponding audit INSERT. The DEFERRABLE trigger checks at COMMIT.
$unaudited=$scope->run($tenant,static function(PDO $db)use($now,$keys):bool {
    $stmt=$db->prepare("UPDATE ".KEY_TABLE_130."
        SET key_status='revoked',revoked_at=:at
        WHERE tenant_ref='guard-tenant' AND public_key_fingerprint=:fp");
    $stmt->execute(['at'=>$now,'fp'=>$keys[0]]);
    return $stmt->rowCount()===1;
});
check130($unaudited===null,
    'direct unaudited UPDATE unexpectedly committed');
check130(status130($admin,'guard-tenant',$keys[0])['key_status']==='active'&&
    events130($admin,'guard-tenant')===0,
    'deferred audit enforcement did not roll back key transition');

$changeMaterial=$scope->run($tenant,static function(PDO $db)use($keys):bool {
    $stmt=$db->prepare("UPDATE ".KEY_TABLE_130."
        SET public_key_base64=:pub WHERE tenant_ref='guard-tenant'
        AND public_key_fingerprint=:fp");
    $stmt->execute([
        'pub'=>base64_encode(str_repeat('X',32)),'fp'=>$keys[0]
    ]);
    return true;
});
check130($changeMaterial===null,
    'signer public key was modified after enrollment');

$deleteKey=$scope->run($tenant,static function(PDO $db)use($keys):bool {
    $stmt=$db->prepare('DELETE FROM '.KEY_TABLE_130.
        " WHERE tenant_ref='guard-tenant' AND public_key_fingerprint=:fp");
    $stmt->execute(['fp'=>$keys[0]]);
    return true;
});
check130($deleteKey===null,'direct enrolled signer key deletion allowed');

$eventDigest=hash('sha256','f130-adversarial-event');
$fakeAudit=$scope->run($tenant,static function(PDO $db)use(
    $keys,$now,$eventDigest
):bool {
    $s=$db->prepare("INSERT INTO ".AUDIT_TABLE_130."
      (tenant_ref,event_digest,signer_ref,public_key_fingerprint,
       actor_ref,event_code,occurred_at)
      VALUES ('guard-tenant',:digest,:signer,:fp,
              'portal-f130-operator','signer_key_revoked',:now)");
    $s->execute([
        'digest'=>$eventDigest,'signer'=>SIGNER_130,
        'fp'=>$keys[0],'now'=>$now
    ]);
    return true;
});
check130($fakeAudit===null,
    'direct audit INSERT for still-active key was permitted');
check130(events130($admin,'guard-tenant')===0,
    'forged audit on active key was recorded');

// Existing F129 legitimate revoke performs UPDATE and matching INSERT
// before COMMIT. Deferred trigger must still allow that workflow.
$good=(new Revoke($app))->revoke(
    $tenant,SIGNER_130,$keys[0],$now
);
check130(is_array($good)&&
    $good['revocationAuditPersistedInPgLab']===true &&
    $good['oneSignerKeyRevokedInPgLab']===true,
    'F129 legitimate atomic revoke was broken by F130 guards');
check130(status130($admin,'guard-tenant',$keys[0])['key_status']==='revoked'&&
    events130($admin,'guard-tenant')===1,
    'matching key transition+audit did not commit');

$eventUpdate=$scope->run($tenant,static function(PDO $db):bool {
    $s=$db->prepare("UPDATE ".AUDIT_TABLE_130."
       SET actor_ref='forged-actor' WHERE tenant_ref='guard-tenant'");
    $s->execute();
    return true;
});
check130($eventUpdate===null,'audit log UPDATE was allowed');

$eventDelete=$scope->run($tenant,static function(PDO $db):bool {
    $s=$db->prepare('DELETE FROM '.AUDIT_TABLE_130.
        " WHERE tenant_ref='guard-tenant'");
    $s->execute();
    return true;
});
check130($eventDelete===null,'audit log DELETE was allowed');

$eventDup=$scope->run($tenant,static function(PDO $db)use(
    $now,$keys
):bool {
    $stmt=$db->prepare("INSERT INTO ".AUDIT_TABLE_130."
      (tenant_ref,event_digest,signer_ref,public_key_fingerprint,
       actor_ref,event_code,occurred_at)
      VALUES ('guard-tenant',:digest,:signer,:fp,
              'another-actor','signer_key_revoked',:now)");
    $stmt->execute([
        'digest'=>hash('sha256','another-fake-event'),
        'signer'=>SIGNER_130,
        'fp'=>$keys[0],'now'=>$now
    ]);
    return true;
});
check130($eventDup===null,
    'duplicate key revocation event with different digest was accepted');

$reactivate=$scope->run($tenant,static function(PDO $db)use($keys):bool {
    $stmt=$db->prepare("UPDATE ".KEY_TABLE_130."
       SET key_status='active',revoked_at=NULL
       WHERE tenant_ref='guard-tenant' AND public_key_fingerprint=:fp");
    $stmt->execute(['fp'=>$keys[0]]);
    return true;
});
check130($reactivate===null,
    'previously revoked key was reactivated');

$crossTenant=$scope->run($tenant,static function(PDO $db)use(
    $keys,$now
):int {
    $s=$db->prepare("UPDATE ".KEY_TABLE_130."
        SET key_status='revoked',revoked_at=:at
        WHERE tenant_ref='other-tenant' AND public_key_fingerprint=:fp");
    $s->execute(['at'=>$now,'fp'=>$keys[0]]);
    return $s->rowCount();
});
check130($crossTenant===0,'tenant RLS permitted cross-tenant mutation');
check130(status130($admin,'other-tenant',$keys[0])['key_status']==='active',
    'another tenant key was changed');
check130(events130($admin,'guard-tenant')===1,
    'guard failure altered audit history');

check130((int)$mutator->query(
    'SELECT COUNT(*) FROM '.KEY_TABLE_130
)->fetchColumn()===0 &&
    (int)$mutator->query(
        'SELECT COUNT(*) FROM '.AUDIT_TABLE_130
    )->fetchColumn()===0,
    'tenant context leaked outside transaction');

putenv('APP_ENV=production');
check130((new Revoke($app))->revoke(
    $tenant,SIGNER_130,$keys[1],$now
)===null,'lab revoker executed in production mode');
check130(status130($admin,'guard-tenant',$keys[1])['key_status']==='active',
    'production guard mutated key');

echo "F130 PG18: unaudited key UPDATE rejected at COMMIT, enrolled key ".
    "material/deletion/reactivation denied, audit INSERT-only immutability ".
    "enforced, valid F129 atomic revoke preserved, tenant RLS isolated\n";
