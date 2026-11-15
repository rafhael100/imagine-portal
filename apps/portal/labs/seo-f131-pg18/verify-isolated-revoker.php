<?php
declare(strict_types=1);

/**
 * F131: real PostgreSQL18 proof that an operator role with EXECUTE only,
 * but no key UPDATE or audit INSERT, can revoke through a narrowly-owned
 * SQL function while F130 enforces tenant-RLS audit integrity.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\PortalSeoPgIsolatedRevokerLab as Revoke;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as Scope;

function prove131(bool $condition,string $why):void {
    if(!$condition)throw new RuntimeException('F131 PG18 CI: '.$why);
}
if(PHP_SAPI!=='cli' ||
   getenv('CI')!=='true' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_SEO_F131_PG_CI')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('F131_ROLE_PASSWORD')!=='f131-ci-role-only')
    throw new RuntimeException('F131 only on disposable PG18 CI');

function connect131(string $user,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]
    );
}
const FN131='public.portal_seo_f131_revoke_signer(text,text,text,text,bigint,text)';
$admin=connect131('postgres',getenv('POSTGRES_PASSWORD'));
$sql=file_get_contents(__DIR__.'/install-isolated-revoker.sql');
prove131(is_string($sql)&&strlen($sql)>200&&strlen($sql)<16000,
    'CI-only SQL unavailable');
$admin->exec($sql);

$admin->exec("CREATE ROLE portal_f131_caller LOGIN INHERIT
    NOSUPERUSER NOBYPASSRLS PASSWORD 'f131-ci-role-only'");
$admin->exec("CREATE ROLE portal_f131_noexecute LOGIN INHERIT
    NOSUPERUSER NOBYPASSRLS PASSWORD 'f131-ci-role-only'");
$admin->exec('GRANT portal_seo_f131_operator TO portal_f131_caller');
foreach(['portal_f131_caller','portal_f131_noexecute'] as $role){
    $admin->exec('GRANT USAGE ON SCHEMA public TO '.$role);
    $admin->exec('GRANT SELECT ON public.portal_seo_build_signing_keys TO '.$role);
    $admin->exec('GRANT SELECT ON public.portal_seo_build_signer_events TO '.$role);
}
$caller=connect131('portal_f131_caller',getenv('F131_ROLE_PASSWORD'));
$nonCaller=connect131('portal_f131_noexecute',getenv('F131_ROLE_PASSWORD'));
$owner=$admin->query(
    "SELECT rolname,rolsuper,rolbypassrls,rolcanlogin
     FROM pg_roles WHERE rolname='portal_seo_f131_function_owner'"
)->fetch(PDO::FETCH_ASSOC);
prove131(is_array($owner)&&
    in_array($owner['rolsuper'],[false,'f','0',0],true)&&
    in_array($owner['rolbypassrls'],[false,'f','0',0],true)&&
    in_array($owner['rolcanlogin'],[false,'f','0',0],true),
    'function owner can login or bypass row isolation');

$function=$admin->query(
    "SELECT prosecdef, pg_get_userbyid(proowner) AS owner
     FROM pg_proc WHERE oid='".FN131."'::regprocedure"
)->fetch(PDO::FETCH_ASSOC);
prove131(is_array($function)&&
    in_array($function['prosecdef'],[true,'t'],true)&&
    $function['owner']==='portal_seo_f131_function_owner',
    'function is not owned by isolated security-definer role');
$privs=$admin->query(
    "SELECT
      has_function_privilege('portal_f131_caller','".FN131."','EXECUTE')
        AS caller_execute,
      has_function_privilege('portal_f131_noexecute','".FN131."','EXECUTE')
        AS other_execute,
      has_table_privilege('portal_f131_caller',
        'portal_seo_build_signing_keys','UPDATE') AS can_update_key,
      has_table_privilege('portal_f131_caller',
        'portal_seo_build_signer_events','INSERT') AS can_insert_audit"
)->fetch(PDO::FETCH_ASSOC);
prove131(is_array($privs)&&
    in_array($privs['caller_execute'],[true,'t'],true)&&
    in_array($privs['other_execute'],[false,'f'],true)&&
    in_array($privs['can_update_key'],[false,'f'],true)&&
    in_array($privs['can_insert_audit'],[false,'f'],true),
    'operator permissions too broad or function exposed publicly');

$tenant=['tenantRef'=>'isolated-tenant','actorRef'=>'portal-f131-operator'];
$other=['tenantRef'=>'other-tenant','actorRef'=>'portal-f131-operator'];
$now=time();
$keys=[
    hash('sha256',str_repeat('N',32)),
    hash('sha256',str_repeat('O',32)),
];
$seed=$admin->prepare(
    "INSERT INTO portal_seo_build_signing_keys
    (tenant_ref,signer_ref,public_key_fingerprint,public_key_base64,
     key_status,not_before,expires_at,revoked_at)
    VALUES (:tenant,'seo-engine-ci-lab',:fp,:key,'active',:start,:expires,NULL)"
);
foreach($keys as $i=>$fingerprint){
    $seed->execute([
        'tenant'=>'isolated-tenant','fp'=>$fingerprint,
        'key'=>base64_encode(str_repeat(chr(78+$i),32)),
        'start'=>$now-60,'expires'=>$now+3600
    ]);
}
$seed->execute([
    'tenant'=>'other-tenant','fp'=>hash('sha256',str_repeat('P',32)),
    'key'=>base64_encode(str_repeat('P',32)),
    'start'=>$now-60,'expires'=>$now+3600
]);
function keyState131(PDO $db,string $tenant,string $key):string {
    $s=$db->prepare('SELECT key_status FROM portal_seo_build_signing_keys
        WHERE tenant_ref=:tenant AND public_key_fingerprint=:fp');
    $s->execute(['tenant'=>$tenant,'fp'=>$key]);
    $result=$s->fetchColumn();
    prove131(is_string($result),'expected signing key not found');
    return $result;
}
function eventCount131(PDO $db,string $tenant):int {
    $s=$db->prepare('SELECT COUNT(*) FROM portal_seo_build_signer_events
         WHERE tenant_ref=:tenant');
    $s->execute(['tenant'=>$tenant]);
    return (int)$s->fetchColumn();
}
$scope=new Scope($caller);
$nonScope=new Scope($nonCaller);
// Direct DML is forbidden by SQL GRANT regardless of F130 triggers.
$noDirectKey=$scope->run($tenant,static function(PDO $db)use($keys,$now):bool {
    $s=$db->prepare("UPDATE portal_seo_build_signing_keys
        SET key_status='revoked',revoked_at=:ts WHERE
        tenant_ref='isolated-tenant' AND public_key_fingerprint=:fp");
    $s->execute(['ts'=>$now,'fp'=>$keys[0]]);
    return true;
});
prove131($noDirectKey===null,
    'operator performed direct key UPDATE');

$noDirectAudit=$scope->run($tenant,static function(PDO $db)use($now,$keys):bool {
    $s=$db->prepare("INSERT INTO portal_seo_build_signer_events
       (tenant_ref,event_digest,signer_ref,public_key_fingerprint,
        actor_ref,event_code,occurred_at)
       VALUES ('isolated-tenant',:digest,'seo-engine-ci-lab',:fp,
        'portal-f131-operator','signer_key_revoked',:ts)");
    $s->execute([
        'digest'=>hash('sha256','forged-audit'),'fp'=>$keys[0],
        'ts'=>$now
    ]);
    return true;
});
prove131($noDirectAudit===null,
    'operator inserted audit directly');

$noExecute=(new Revoke($nonCaller))->revoke(
    $tenant,'seo-engine-ci-lab',$keys[0],$now
);
prove131($noExecute===null,'unapproved role executed revocation function');
prove131(keyState131($admin,'isolated-tenant',$keys[0])==='active'&&
    eventCount131($admin,'isolated-tenant')===0,
    'unauthorized calls wrote key or audit');

$contextSwap=$scope->run($tenant,static function(PDO $db)use(
    $now,$keys
):bool {
    $s=$db->prepare(
        "SELECT public.portal_seo_f131_revoke_signer(
          :tenant,'seo-engine-ci-lab',:fp,'portal-f131-operator',
          CAST(:ts AS bigint),:digest)"
    );
    $s->execute([
        'tenant'=>'other-tenant','fp'=>$keys[0],
        'ts'=>$now,'digest'=>hash('sha256','forged-caller-tenant')
    ]);
    return in_array($s->fetchColumn(),[true,'t',1,'1'],true);
});
prove131($contextSwap===false,
    'function accepted a tenant argument different from local RLS context');

$first=(new Revoke($caller))->revoke(
    $tenant,'seo-engine-ci-lab',$keys[0],$now
);
prove131(is_array($first)&&
    $first['revocationCommittedWithMandatoryAuditInPgLab']===true&&
    $first['operationalUserDirectTableDmlRequired']===false&&
    $first['functionOwnerNoLoginNoBypassRls']===true&&
    $first['realPortalAdminIdentityVerified']===false&&
    $first['publicationAuthorized']===false,
    'restricted function failed to commit key+event');
prove131(keyState131($admin,'isolated-tenant',$keys[0])==='revoked'&&
    eventCount131($admin,'isolated-tenant')===1,
    'security-definer key and audit commit mismatch');
prove131((new Revoke($caller))->revoke(
    $tenant,'seo-engine-ci-lab',$keys[0],$now
)===null&&eventCount131($admin,'isolated-tenant')===1,
    'replayed function revocation created extra audit');

// Mandatory audit failure (missing INSERT on function owner) MUST roll back
// key status even though the caller has EXECUTE on the function.
$admin->exec('REVOKE INSERT ON portal_seo_build_signer_events
    FROM portal_seo_f131_function_owner');
$noEvent=(new Revoke($caller))->revoke(
    $tenant,'seo-engine-ci-lab',$keys[1],$now
);
prove131($noEvent===null,'function completed without audit permission');
prove131(keyState131($admin,'isolated-tenant',$keys[1])==='active'&&
    eventCount131($admin,'isolated-tenant')===1,
    'missing audit INSERT did not roll back key UPDATE');
$admin->exec('GRANT INSERT ON portal_seo_build_signer_events
    TO portal_seo_f131_function_owner');
$second=(new Revoke($caller))->revoke(
    $tenant,'seo-engine-ci-lab',$keys[1],$now
);
prove131(is_array($second)&&
    keyState131($admin,'isolated-tenant',$keys[1])==='revoked'&&
    eventCount131($admin,'isolated-tenant')===2,
    'restoring owner audit privilege did not restore atomic operation');

$scopedCount=$scope->run($tenant,static fn(PDO $db)=>
    (int)$db->query('SELECT COUNT(*) FROM portal_seo_build_signer_events')
        ->fetchColumn()
);
$otherCount=$scope->run($other,static fn(PDO $db)=>
    (int)$db->query('SELECT COUNT(*) FROM portal_seo_build_signer_events')
        ->fetchColumn()
);
prove131($scopedCount===2&&$otherCount===0,
    'tenant RLS leaked audit events');
prove131((int)$caller->query(
    'SELECT COUNT(*) FROM portal_seo_build_signing_keys'
)->fetchColumn()===0,
    'tenant GUC leaked after function');
prove131(keyState131(
    $admin,'other-tenant',hash('sha256',str_repeat('P',32))
)==='active','foreign tenant key modified by isolated revoker');

putenv('APP_ENV=production');
prove131((new Revoke($caller))->revoke(
    $tenant,'seo-engine-ci-lab',$keys[0],$now
)===null,'production mode invoked synthetic DB function');
prove131(!Revoke::authorizesProductionRevocation()&&
    !Revoke::authorizesCustomerDisplay(),
    'synthetic SQL routine promoted production authority');

echo "F131 PG18: operator has EXECUTE only, NO key UPDATE or audit INSERT; ".
    "nonlogin NO-BYPASS-RLS function owner commits atomic revoke+audit; ".
    "unauthorized calls/tenant mismatch/audit failure roll back\n";
