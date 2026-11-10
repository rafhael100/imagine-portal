<?php

declare(strict_types=1);

require_once __DIR__.'/PortalF112OfflinePgSessionIdentitySource.php';

use App\Domain\Identity\Invitations\PortalInvitationPreflight;
use App\Domain\Identity\Invitations\PortalInvitationSecret;
use App\Domain\Organizations\OrganizationId;

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F112_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F112 requires disposable local PostgreSQL 18.');
}
$adminPass = getenv('F66_PG_ADMIN_PASSWORD');
$readerPass = getenv('F112_PG_READER_PASSWORD');
if (!is_string($adminPass) || strlen($adminPass) < 12
    || !is_string($readerPass) || strlen($readerPass) < 12) {
    throw new RuntimeException('F112 requires ephemeral CI credentials.');
}
$connect = static fn(string $role,string $password):PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$admin=$connect('postgres',$adminPass);
$reader=$connect('portal_f112_reader',$readerPass);
$source=new PortalF112OfflinePgSessionIdentitySource($reader);
$privilegedSource=new PortalF112OfflinePgSessionIdentitySource($admin);
$gate=new PortalInvitationPreflight($source);
$n=0;
$ok=static function(bool $v,string $label) use (&$n):void {
    if (!$v) throw new RuntimeException('PORTAL-F112 FAILED '.$label);
    ++$n;
};
$yes=static fn(mixed $v):bool=>in_array($v,[true,1,'t','1'],true);
$no=static fn(mixed $v):bool=>in_array($v,[false,0,'f','0'],true);
$role=$admin->query(
    "SELECT rolcanlogin,rolsuper,rolbypassrls,rolcreaterole,rolcreatedb
     FROM pg_roles WHERE rolname='portal_f112_owner'"
)->fetch();
$ok(is_array($role) && $no($role['rolcanlogin']) && $no($role['rolsuper'])
    && $no($role['rolbypassrls']) && $no($role['rolcreaterole'])
    && $no($role['rolcreatedb']),
    'read-only definer has no login, BYPASSRLS or admin privilege');
$ok(!PortalF112OfflinePgSessionIdentitySource::canAuthenticateCustomers()
    && !PortalF112OfflinePgSessionIdentitySource::canAuthorizeHttp(),
    'lab source cannot authenticate or authorize customers');

$tables=['portal_identities','portal_organizations','portal_memberships','portal_auth_sessions'];
foreach ($tables as $table) {
    $query=$admin->prepare(
        "SELECT has_table_privilege('portal_f112_reader',:table,'SELECT')"
    );
    $query->execute(['table'=>'public.'.$table]);
    $ok($no($query->fetchColumn()),'EXECUTE-only reader cannot SELECT '.$table);
}
$permission=$admin->query(
    "SELECT has_function_privilege(
       'portal_f112_reader',
       'public.portal_f112_verified_session_evidence(text,uuid)'::regprocedure,
       'EXECUTE')"
)->fetchColumn();
$ok($yes($permission),'reader has exactly callable evidence function');
$policies=$admin->query(
    "SELECT tablename,roles,cmd FROM pg_policies
     WHERE schemaname='public' AND policyname LIKE 'portal_f112_%'"
)->fetchAll();
$ok(count($policies)===4,'only four scoped laboratory policies created');
foreach ($policies as $policy) {
    $ok($policy['roles']==='{portal_f112_owner}'
        && $policy['cmd']==='SELECT'
        && in_array($policy['tablename'],$tables,true),
        'policy is SELECT only for NOLOGIN source role');
}
$noSecret=$admin->query(
    "SELECT has_column_privilege(
        'portal_f112_owner','public.portal_identities','password_hash','SELECT'
     ),has_column_privilege(
        'portal_f112_owner','public.portal_identities','mfa_secret_ciphertext','SELECT'
     )"
)->fetch(PDO::FETCH_NUM);
$ok(is_array($noSecret) && $no($noSecret[0]) && $no($noSecret[1]),
    'definer cannot access password hash or MFA ciphertext');

$identity='a1111111-1111-4111-8111-111111111111';
$org='a2222222-2222-4222-8222-222222222222';
$foreign='a3333333-3333-4333-8333-333333333333';
$membership='a4444444-4444-4444-8444-444444444444';
$session='a5555555-5555-4555-8555-555555555555';
$sessionBearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$invitationBearer=PortalInvitationSecret::mint()->takeDeliverySecretOnce();
if (!is_string($invitationBearer)) throw new RuntimeException('missing fake invite');
$orgValue=new OrganizationId($org);
$foreignValue=new OrganizationId($foreign);

$admin->prepare(
    "INSERT INTO portal_identities
     (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
      security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 hour',
       now()-interval '1 hour',1,now(),now())"
)->execute([$identity,'f112-synthetic@example.invalid']);
$insertOrg=$admin->prepare(
    "INSERT INTO portal_organizations
     (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$insertOrg->execute([$org,'f112-primary','F112 Synthetic Home']);
$insertOrg->execute([$foreign,'f112-other','F112 Synthetic Foreign']);
$admin->prepare(
    "INSERT INTO portal_memberships
     (id,identity_id,organization_id,role,status,membership_revision,
      created_at,updated_at)
     VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$membership,$identity,$org]);
$admin->prepare(
    "INSERT INTO portal_auth_sessions
     (id,session_sha256,identity_id,organization_id,status,
      identity_revision,membership_revision,organization_revision,
      mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '1 minute',
       now()-interval '10 minutes',now()+interval '20 minutes',now(),now())"
)->execute([$session,hash('sha256',$sessionBearer),$identity,$org]);

$check=static fn():bool=>$source->findFor($sessionBearer,$orgValue)!==null;
$preflight=static fn():bool=>$gate->inspect(
    $sessionBearer,$invitationBearer,$orgValue,time()
)->syntheticInputsPlausible;

$ok($check(),'first-party synthetic session row produces evidence');
$ok($preflight(),'F110 preflight accepts DB-derived synthetic evidence');
$ok(!$gate->inspect(
    $sessionBearer,$invitationBearer,$orgValue,time()
)->canAuthorizeHttp(),'positive DB-derived synthetic proof still cannot authorize HTTP');
$ok(!$gate->inspect(
    $sessionBearer,$invitationBearer,$orgValue,time()
)->canConsumeInvitation(),'positive proof still cannot redeem invitation');
$ok($source->findFor($sessionBearer,$foreignValue)===null,
    'selected organization cannot borrow another business session');
$ok($source->findFor(rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='),$orgValue)===null,
    'unknown random session is denied');
$ok($source->findFor('CRM-session-not-a-Portal-token',$orgValue)===null,
    'CRM cookie is not first-party Portal proof');
$ok($source->findFor($sessionBearer.'=',$orgValue)===null,
    'noncanonical padded bearer denied');
$ok($privilegedSource->findFor($sessionBearer,$orgValue)===null,
    'superuser PDO handle rejected despite matching fixture');

$admin->exec("UPDATE portal_auth_sessions
 SET mfa_verified_at=now()-interval '6 minutes' WHERE id='$session'");
$ok(!$check(),'MFA older than five minutes rejected');
$admin->exec("UPDATE portal_auth_sessions
 SET mfa_verified_at=now()+interval '1 minute' WHERE id='$session'");
$ok(!$check(),'MFA in future rejected');
$admin->exec("UPDATE portal_auth_sessions
 SET mfa_verified_at=now()-interval '1 minute' WHERE id='$session'");
$ok($check(),'restoring synthetic MFA timestamp permits candidate');

$admin->exec("UPDATE portal_identities SET email_verified_at=NULL WHERE id='$identity'");
$ok(!$check(),'missing email verification denied');
$admin->exec("UPDATE portal_identities
 SET email_verified_at=now()-interval '1 hour' WHERE id='$identity'");
$admin->exec("UPDATE portal_identities SET mfa_confirmed_at=NULL WHERE id='$identity'");
$ok(!$check(),'missing MFA confirmation denied');
$admin->exec("UPDATE portal_identities
 SET mfa_confirmed_at=now()-interval '1 hour' WHERE id='$identity'");
$ok($check(),'synthetic confirmed first-party data restored');

$admin->exec("UPDATE portal_memberships
 SET membership_revision=2 WHERE id='$membership'");
$ok(!$check(),'committed membership revision change invalidates evidence');
$admin->exec("UPDATE portal_memberships
 SET membership_revision=1 WHERE id='$membership'");
$admin->exec("UPDATE portal_identities
 SET security_revision=2 WHERE id='$identity'");
$ok(!$check(),'identity revision change revokes evidence');
$admin->exec("UPDATE portal_identities
 SET security_revision=1 WHERE id='$identity'");
$admin->exec("UPDATE portal_organizations
 SET security_revision=2 WHERE id='$org'");
$ok(!$check(),'organization revision change revokes evidence');
$admin->exec("UPDATE portal_organizations
 SET security_revision=1 WHERE id='$org'");
$admin->exec("UPDATE portal_memberships SET status='active' WHERE id='$membership'");
$ok(!$check(),'session for accepted membership is not invitation acceptance proof');
$admin->exec("UPDATE portal_memberships SET status='invited' WHERE id='$membership'");
$admin->exec("UPDATE portal_organizations SET status='suspended' WHERE id='$org'");
$ok(!$check(),'suspended tenant cannot produce Portal invitation evidence');
$admin->exec("UPDATE portal_organizations SET status='active' WHERE id='$org'");

$admin->exec("UPDATE portal_auth_sessions
 SET status='revoked',revoked_at=now() WHERE id='$session'");
$ok(!$check(),'revoked first-party session denied immediately after commit');
$admin->exec("UPDATE portal_auth_sessions
 SET status='active',revoked_at=NULL,expires_at=now()-interval '1 minute'
 WHERE id='$session'");
$ok(!$check(),'expired session denied');
$admin->exec("UPDATE portal_auth_sessions
 SET expires_at=now()+interval '20 minutes',organization_revision=NULL
 WHERE id='$session'");
$ok(!$check(),'legacy session without tenant security revision fails closed');
$admin->exec("UPDATE portal_auth_sessions
 SET organization_revision=1 WHERE id='$session'");
$ok($check(),'synthetic fixture reset restores candidate only');

$admin->exec(
    'GRANT SELECT (id) ON public.portal_auth_sessions TO portal_f112_reader'
);
$ok((int)$reader->query(
    'SELECT count(*) FROM public.portal_auth_sessions'
)->fetchColumn()===0,'FORCE RLS still hides session records even after a SELECT grant');
$admin->exec(
    'REVOKE SELECT (id) ON public.portal_auth_sessions FROM portal_f112_reader'
);
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$identity'"
)->fetchColumn()===1,'read-only evidence lookup did not mutate session');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_membership_grants WHERE membership_id='$membership'"
)->fetchColumn()===0,'evidence lookup cannot imply any business grant');

putenv('PORTAL_F112_PG_LAB=0');
$ok(!$check(),'disabled disposable lab flag denies');
putenv('PORTAL_F112_PG_LAB=1');
putenv('APP_ENV=production');
$ok(!$check(),'production setting denies offline-only source');

echo "PASS PORTAL-F112 {$n} DB-backed synthetic session, MFA/tenant/revocation and RLS checks\n";
