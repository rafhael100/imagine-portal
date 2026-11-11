<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalInvitationSecret;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'
    ||getenv('PORTAL_F119_PG_LAB')!=='1'
    ||getenv('PGHOST')!=='127.0.0.1'
    ||getenv('PGPORT')!=='5432'
    ||getenv('PGDATABASE')!=='portal_identity_lab'){
    throw new RuntimeException('F119 only runs in disposable PG18 CI.');
}
$adminPassword=getenv('F66_PG_ADMIN_PASSWORD');
$probePassword=getenv('F118_PG_PROBE_PASSWORD');
$businessPassword=getenv('F76_PG_READER_PASSWORD');
foreach([$adminPassword,$probePassword,$businessPassword] as $p){
    if(!is_string($p)||strlen($p)<12){
        throw new RuntimeException('F119 needs ephemeral CI-only passwords.');
    }
}
$connect=static fn(string $user,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db=$connect('postgres',$adminPassword);
$writer=$connect('postgres',$adminPassword);
$acceptor=$connect('portal_f118_retirement_probe',$probePassword);
$business=$connect('portal_f76_reader',$businessPassword);
$n=0;
$ok=static function(bool $value,string $why)use(&$n):void{
    if(!$value)throw new RuntimeException('PORTAL-F119 FAILED '.$why);
    ++$n;
};
$isTrue=static fn(mixed $value):bool=>in_array($value,[true,1,'1','t'],true);
$isFalse=static fn(mixed $value):bool=>in_array($value,[false,0,'0','f'],true);
$deny=static function(callable $call,string $expected,string $why)use($ok):void{
    try{$call();}catch(PDOException $e){
        $ok($e->getCode()===$expected,$why.' SQLSTATE '.$e->getCode());
        return;
    }
    $ok(false,$why.' unexpectedly permitted');
};
$role=$db->query(
    "SELECT rolcanlogin,rolsuper,rolbypassrls,rolcreaterole
     FROM pg_roles WHERE rolname='portal_f119_admission_owner'"
)->fetch();
$ok(is_array($role)&&$isFalse($role['rolcanlogin'])
    &&$isFalse($role['rolsuper'])&&$isFalse($role['rolbypassrls'])
    &&$isFalse($role['rolcreaterole']),
    'session admission trigger owner is NOLOGIN/NOSUPERUSER/NOBYPASSRLS');
$rules=$db->query(
    "SELECT
       has_table_privilege('portal_f119_admission_owner',
        'public.portal_auth_sessions','INSERT') AS session_issue,
       has_table_privilege('portal_f119_admission_owner',
        'public.portal_auth_sessions','UPDATE') AS session_update,
       has_column_privilege('portal_f119_admission_owner',
        'public.portal_identities','password_hash','SELECT') AS password_read,
       has_column_privilege('portal_f119_admission_owner',
        'public.portal_identities','mfa_secret_ciphertext','SELECT') AS mfa_read"
)->fetch();
$ok($isFalse($rules['session_issue'])&&$isFalse($rules['session_update'])
    &&$isFalse($rules['password_read'])&&$isFalse($rules['mfa_read']),
    'trigger owner cannot issue sessions or read password and MFA ciphertext');
$trigger=$db->query(
    "SELECT tgenabled FROM pg_trigger
     WHERE tgname='portal_f119_session_admission_guard'"
)->fetchColumn();
$ok($trigger==='O','trigger is enabled, not merely defined');

$id='51111111-1111-4111-8111-111111111111';
$org='52222222-2222-4222-8222-222222222222';
$membership='53333333-3333-4333-8333-333333333333';
$primaryId='54444444-4444-4444-8444-444444444444';
$siblingId='56666666-6666-4666-8666-666666666666';
$inviteId='55555555-5555-4555-8555-555555555555';
$businessId='57777777-7777-4777-8777-777777777777';
$bearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$primaryDigest=hash('sha256',$bearer);
$secret=PortalInvitationSecret::mint();
$invitation=$secret->takeDeliverySecretOnce();
if(!is_string($invitation))throw new RuntimeException('Missing fake invite');
$inviteDigest=$secret->digest();

$db->prepare(
    "INSERT INTO portal_identities(
       id,email_normalized,status,email_verified_at,mfa_confirmed_at,
       security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 hour',
        now()-interval '1 hour',1,now(),now())"
)->execute([$id,'f119-local@example.invalid']);
$db->prepare(
    "INSERT INTO portal_organizations(
       id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,'f119-org','F119 synthetic tenant','active',1,now(),now())"
)->execute([$org]);
$db->prepare(
    "INSERT INTO portal_memberships(
      id,identity_id,organization_id,role,status,membership_revision,
      created_at,updated_at)
     VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$membership,$id,$org]);

$insert=$db->prepare(
    "INSERT INTO portal_auth_sessions(
       id,session_sha256,identity_id,organization_id,status,session_purpose,
       identity_revision,membership_revision,organization_revision,
       mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?,?, ?,1,1,1,?,
       clock_timestamp(),clock_timestamp()+interval '20 minutes',now(),now())"
);
$issue=static function(
    string $sid,string $digest,string $status,string $purpose,
    ?string $mfa=null
)use($insert,$id,$org):void{
    $insert->execute([$sid,$digest,$id,$org,$status,$purpose,$mfa]);
};
$deny(static fn()=> $issue(
    $businessId,hash('sha256','pre-issued-business'), 'pending_mfa','business'
),'23514','no business bearer can be minted while member invited');
$deny(static fn()=> $issue(
    $primaryId,hash('sha256','forged-already-active'), 'active','invitation_only',
    date('Y-m-d H:i:sP')
),'23514','new session cannot skip MFA phase');
$issue($primaryId,$primaryDigest,'pending_mfa','invitation_only');
$issue($siblingId,hash('sha256','f119-pending-sibling'),'pending_mfa','invitation_only');

$complete=$db->prepare(
    "UPDATE portal_auth_sessions SET status='active',
       mfa_verified_at=clock_timestamp(),updated_at=clock_timestamp()
     WHERE id=?"
);
$complete->execute([$primaryId]);
$ok($complete->rowCount()===1,
    'pending invited session becomes active only with new MFA timestamp');
$deny(static fn()=> $db->prepare(
    "UPDATE portal_auth_sessions SET session_sha256=? WHERE id=?"
)->execute([hash('sha256','tampered-token'),$primaryId]),
'23514','issued bearer digest cannot be rewritten');
$deny(static fn()=> $db->prepare(
    "UPDATE portal_auth_sessions SET expires_at=expires_at+interval '1 minute'
     WHERE id=?"
)->execute([$primaryId]),
'23514','session expiry cannot be extended after issuance');

$db->prepare(
    "INSERT INTO portal_access_invitations(
       id,token_sha256,identity_id,organization_id,
       identity_revision,membership_revision,organization_revision,
       status,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?,1,1,1,'pending',now()-interval '1 minute',
       now()+interval '1 day',now(),now())"
)->execute([$inviteId,$inviteDigest,$id,$org]);
$claim=static function(PDO $conn)use($primaryDigest,$inviteDigest,$org,$isTrue):bool{
    $q=$conn->prepare(
        'SELECT public.portal_f118_accept_and_retire_all('
        .':session,:invite,CAST(:org AS uuid))'
    );
    $q->execute([
        'session'=>$primaryDigest,'invite'=>$inviteDigest,'org'=>$org,
    ]);
    return $isTrue($q->fetchColumn());
};
$writer->exec("SET lock_timeout='250ms'");
$acceptor->beginTransaction();
$ok($claim($acceptor),'F118 accepts locked invited membership and retires both sessions');
// This writer does not know about F118; the DB trigger itself MUST wait
// on the membership lock before it can issue another credential.
$attempt=$writer->prepare(
    "INSERT INTO portal_auth_sessions(
      id,session_sha256,identity_id,organization_id,status,session_purpose,
      identity_revision,membership_revision,organization_revision,
      issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'pending_mfa','invitation_only',1,1,1,
       clock_timestamp(),clock_timestamp()+interval '20 minutes',now(),now())"
);
$deny(static fn()=> $attempt->execute([
    '58888888-8888-4888-8888-888888888888',
    hash('sha256','racy-invitation-credential'),$id,$org
]),'55P03','concurrent issuer cannot bypass F118 membership row lock');
$acceptor->commit();
$writer->exec('RESET lock_timeout');
$deny(static fn()=> $attempt->execute([
    '58888888-8888-4888-8888-888888888888',
    hash('sha256','racy-invitation-credential'),$id,$org
]),'23514','post-activation membership cannot issue invitation-only credential');
$sessionRows=$db->query(
    "SELECT status FROM portal_auth_sessions
     WHERE identity_id='$id' AND organization_id='$org' ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN);
$ok(count($sessionRows)===2&&$sessionRows===['revoked','revoked'],
    'F118 terminated both sessions before a new one could be issued');
$member=$db->query(
    "SELECT status,membership_revision FROM portal_memberships WHERE id='$membership'"
)->fetch();
$ok($member['status']==='active'&&(int)$member['membership_revision']===2,
    'member activation committed exactly once');
$ok(!$claim($acceptor),'old session cannot be replayed after activation');

// New business credential must be a fresh pending-MFA session, after the
// membership updated_at. It still requires explicit service grants to work.
// Shared runners can move the PostgreSQL clock backwards momentarily after
// F118 stored the membership activation timestamp. Real issuers MUST deny
// future-dated sessions; the CI fixture waits until time has progressed.
$ready=false;
$clock=$db->prepare(
    "SELECT updated_at <= clock_timestamp() - interval '2 seconds'
       FROM portal_memberships WHERE id=?"
);
for($i=0;$i<70;$i++){
    $clock->execute([$membership]);
    if($isTrue($clock->fetchColumn())){$ready=true;break;}
    usleep(100000);
}
$ok($ready,'database clock is safely after committed activation timestamp');
$businessDigest=hash('sha256','f119-new-business-after-activation');
$issueBusiness=$db->prepare(
    "INSERT INTO portal_auth_sessions(
      id,session_sha256,identity_id,organization_id,status,session_purpose,
      identity_revision,membership_revision,organization_revision,
      issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?,'pending_mfa','business',1,2,1,
       clock_timestamp(),clock_timestamp()+interval '20 minutes',now(),now())"
);
$issueBusiness->execute([$businessId,$businessDigest,$id,$org]);
$ok(!$isTrue($business->query(
    "SELECT public.portal_f113_business_permission_check(
     '$businessDigest','$org'::uuid,'services.view')"
)->fetchColumn()),'pending business session cannot grant permissions');
$complete->execute([$businessId]);
$ok($complete->rowCount()===1,'new business session completes fresh MFA');
$ok(!$isTrue($business->query(
    "SELECT public.portal_f113_business_permission_check(
     '$businessDigest','$org'::uuid,'services.view')"
)->fetchColumn()),'active business session does not imply a service grant');
$db->prepare(
    "INSERT INTO portal_membership_grants(
       id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute(['59999999-9999-4999-8999-999999999999',$membership]);
$ok($isTrue($business->query(
    "SELECT public.portal_f113_business_permission_check(
     '$businessDigest','$org'::uuid,'services.view')"
)->fetchColumn()),'only explicit grant authorizes a newly issued business session');
$deny(static fn()=> $db->prepare(
    "UPDATE portal_auth_sessions SET status='active',revoked_at=NULL
     WHERE id=?"
)->execute([$primaryId]),'23514','revoked temporary session cannot be resurrected');
$deny(static fn()=> $db->prepare(
    "UPDATE portal_auth_sessions SET session_purpose='invitation_only'
     WHERE id=?"
)->execute([$businessId]),'23514','business session cannot be converted to an invitation');
$deny(static fn()=> $db->prepare(
    "DELETE FROM portal_auth_sessions WHERE id=?"
)->execute([$businessId]),'23514','issued credential cannot be erased without audited retirement');

$db->beginTransaction();
try{
    $db->exec('SET LOCAL ROLE portal_f119_admission_owner');
    $db->prepare(
        "UPDATE portal_memberships SET status='revoked' WHERE id=?"
    )->execute([$membership]);
    $ok(false,'guard owner must not modify tenant membership');
}catch(PDOException $e){
    $ok($e->getCode()==='42501','guard owner cannot UPDATE membership under FORCE RLS');
}finally{
    if($db->inTransaction())$db->rollBack();
}

echo "PASS PORTAL-F119 {$n} serialized issuer, purpose, MFA, tenant and immutable bearer checks\n";
