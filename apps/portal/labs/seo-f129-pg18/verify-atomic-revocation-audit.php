<?php
declare(strict_types=1);

/**
 * F129: demonstrate atomic signing-key revocation + mandatory tenant RLS
 * security event in PostgreSQL 18. Runs after F128 in disposable CI only.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgSigningKeyRevocationLab as Revoke;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

function check129(bool $condition,string $reason):void {
    if(!$condition)throw new RuntimeException('F129 PG18: '.$reason);
}
if(PHP_SAPI!=='cli'||
   getenv('CI')!=='true'||
   getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_SEO_F128_PG_CI')!=='1'||
   getenv('PORTAL_SEO_F129_PG_CI')!=='1'||
   getenv('DB_CONNECTION')!=='pgsql'||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
   getenv('DB_HOST')!=='127.0.0.1'||
   getenv('F129_ROLE_PASSWORD')!=='f129-ci-role-only')
    throw new RuntimeException('F129 requires disposable PG18 CI');

function pg129(string $user,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]
    );
}
function count129(PDO $db,string $table):int {
    check129(in_array($table,[
        'portal_seo_build_signing_keys',
        'portal_seo_build_signer_events'
    ],true),'unrecognized table');
    return (int)$db->query('SELECT count(*) FROM '.$table)->fetchColumn();
}
$admin=pg129('postgres',getenv('POSTGRES_PASSWORD'));
$roles=[
    'portal_f129_valid',
    'portal_f129_noaudit',
    'portal_f129_nochange'
];
foreach($roles as $role){
    $admin->exec("CREATE ROLE ".$role." LOGIN
        NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f129-ci-role-only'");
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON portal_seo_build_signing_keys TO '.$role);
    $admin->exec('GRANT SELECT ON portal_seo_build_signer_events TO '.$role);
}
$admin->exec('GRANT UPDATE ON portal_seo_build_signing_keys
    TO portal_f129_valid,portal_f129_noaudit');
$admin->exec('GRANT INSERT ON portal_seo_build_signer_events
    TO portal_f129_valid,portal_f129_nochange');
$valid=pg129('portal_f129_valid',getenv('F129_ROLE_PASSWORD'));
$noAudit=pg129('portal_f129_noaudit',getenv('F129_ROLE_PASSWORD'));
$noChange=pg129('portal_f129_nochange',getenv('F129_ROLE_PASSWORD'));

foreach($roles as $role){
    $p=$admin->prepare(
        'SELECT rolsuper,rolbypassrls FROM pg_roles WHERE rolname=:name'
    );
    $p->execute(['name'=>$role]);
    $s=$p->fetch(PDO::FETCH_ASSOC);
    check129(is_array($s)&&
        in_array($s['rolsuper'],[false,'f','0',0],true)&&
        in_array($s['rolbypassrls'],[false,'f','0',0],true),
        'role bypasses tenant RLS');
}
foreach(['portal_seo_build_signing_keys',
         'portal_seo_build_signer_events'] as $table){
    $r=$admin->query("SELECT relrowsecurity,relforcerowsecurity
        FROM pg_class WHERE oid='public.".$table."'::regclass")
        ->fetch(PDO::FETCH_ASSOC);
    check129(is_array($r)&&
        in_array($r['relrowsecurity'],[true,'t'],true)&&
        in_array($r['relforcerowsecurity'],[true,'t'],true),
        'missing FORCE row level security');
}
$now=time();
$tenant='audit-tenant';
$signer='seo-engine-ci-lab';
$actor='portal-f129-audit';
$ctx=['tenantRef'=>$tenant,'actorRef'=>$actor];
$other=['tenantRef'=>'other-tenant','actorRef'=>$actor];
$keys=[
    hash('sha256',str_repeat('A',32)),
    hash('sha256',str_repeat('B',32))
];
$insert=$admin->prepare(
    "INSERT INTO portal_seo_build_signing_keys
    (tenant_ref,signer_ref,public_key_fingerprint,public_key_base64,
     key_status,not_before,expires_at,revoked_at)
    VALUES (:tenant,:signer,:fp,:pk,'active',:before,:expires,NULL)"
);
foreach($keys as $i=>$fp){
    $insert->execute([
        'tenant'=>$tenant,'signer'=>$signer,'fp'=>$fp,
        'pk'=>base64_encode(str_repeat($i===0?'A':'B',32)),
        'before'=>$now-60,'expires'=>$now+3600
    ]);
}
function status129(PDO $db,string $tenant,string $fp):array {
    $s=$db->prepare(
        'SELECT key_status,revoked_at FROM portal_seo_build_signing_keys
        WHERE tenant_ref=:tenant AND public_key_fingerprint=:fp'
    );
    $s->execute(['tenant'=>$tenant,'fp'=>$fp]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    check129(is_array($row),'test signing key not found');
    return $row;
}
function events129(PDO $db,string $tenant):int {
    $p=$db->prepare(
        'SELECT COUNT(*) FROM portal_seo_build_signer_events
        WHERE tenant_ref=:tenant AND event_code=:event'
    );
    $p->execute(['tenant'=>$tenant,'event'=>'signer_key_revoked']);
    return (int)$p->fetchColumn();
}
check129(events129($admin,$tenant)===0,
    'unexpected pre-existing audit-tenant events');

// Any missing mandatory INSERT privilege MUST roll back the preceding UPDATE.
check129((new Revoke($noAudit))->revoke($ctx,$signer,$keys[0],$now)===null,
    'revocation succeeded without audit INSERT permission');
check129(status129($admin,$tenant,$keys[0])['key_status']==='active'&&
    events129($admin,$tenant)===0,
    'audit INSERT failure did not undo key status change');

// A role with audit INSERT but without key UPDATE also cannot forge a
// successful revocation event through the guarded service.
check129((new Revoke($noChange))->revoke($ctx,$signer,$keys[0],$now)===null,
    'revocation succeeded without key UPDATE permission');
check129(status129($admin,$tenant,$keys[0])['key_status']==='active'&&
    events129($admin,$tenant)===0,
    'partial audit event persisted despite failed key UPDATE');

check129((new Revoke($valid))->revoke(
    $other,$signer,$keys[0],$now
)===null,'cross-tenant revocation succeeded');
check129(events129($admin,$tenant)===0,
    'cross-tenant attempt wrote audit event');

$first=(new Revoke($valid))->revoke($ctx,$signer,$keys[0],$now);
check129(is_array($first)&&
    $first['oneSignerKeyRevokedInPgLab']===true&&
    $first['revocationAuditPersistedInPgLab']===true&&
    $first['tenantSignerTransactionLockUsed']===true&&
    $first['realAdministratorVerified']===false&&
    $first['customerDisplayAuthorized']===false&&
    $first['publicationAuthorized']===false,
    'atomic revocation+audit not confirmed');
check129(status129($admin,$tenant,$keys[0])['key_status']==='revoked'&&
    events129($admin,$tenant)===1,
    'key revocation and audit not committed together');
check129((new Revoke($valid))->revoke($ctx,$signer,$keys[0],$now)===null&&
    events129($admin,$tenant)===1,
    'idempotent revoke wrote duplicate audit event');

// Second revocation uses a different digest and creates one more event.
$second=(new Revoke($valid))->revoke($ctx,$signer,$keys[1],$now);
check129(is_array($second)&&events129($admin,$tenant)===2,
    'second revocation did not append one event');
$rows=$admin->query(
    "SELECT tenant_ref,signer_ref,public_key_fingerprint,actor_ref,
     event_code,occurred_at FROM portal_seo_build_signer_events
     WHERE tenant_ref='audit-tenant' ORDER BY public_key_fingerprint"
)->fetchAll(PDO::FETCH_ASSOC);
check129(count($rows)===2,'audit event count wrong');
foreach($rows as $row)
    check129($row['tenant_ref']===$tenant&&
        $row['signer_ref']===$signer&&
        $row['actor_ref']===$actor&&
        $row['event_code']==='signer_key_revoked'&&
        $row['occurred_at']!==null,
        'audit event missing tenant, issuer or actor context');

$scoped=new Scope($valid);
check129(count129($valid,'portal_seo_build_signer_events')===0,
    'audit tenant GUC leaked');
$own=$scoped->run($ctx,static fn(PDO $db)=>
    count129($db,'portal_seo_build_signer_events'));
$foreign=$scoped->run($other,static fn(PDO $db)=>
    count129($db,'portal_seo_build_signer_events'));
check129($own===2 && $foreign===0,
    'audit table leaked another tenant rows');

putenv('APP_ENV=production');
check129((new Revoke($valid))->revoke($ctx,$signer,$keys[0],$now)===null,
    'production mode invoked synthetic revoker');
check129(events129($admin,$tenant)===2,
    'production guard inserted an event');
echo "F129 PG18: key UPDATE and mandatory audit INSERT atomic; ".
     "noaudit/noupdate roles fully rolled back; tenant RLS and ".
     "idempotency preserved; no production authority\n";
