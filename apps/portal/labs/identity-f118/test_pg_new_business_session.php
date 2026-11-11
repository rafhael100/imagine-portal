<?php

declare(strict_types=1);

require_once __DIR__.'/PortalF118BusinessSessionIssuer.php';
require_once dirname(__DIR__).'/identity-f112/PortalF112OfflinePgSessionIdentitySource.php';

use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\Organizations\OrganizationId;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'
    ||getenv('PORTAL_F118_PG_LAB')!=='1'
    ||getenv('PORTAL_F112_PG_LAB')!=='1'
    ||getenv('PGHOST')!=='127.0.0.1'
    ||getenv('PGPORT')!=='5432'
    ||getenv('PGDATABASE')!=='portal_identity_lab'){
    throw new RuntimeException('F118 disposable PostgreSQL 18 only.');
}
$adminPass=getenv('F66_PG_ADMIN_PASSWORD');
$throttlePass=getenv('F77_PG_THROTTLE_PASSWORD');
$businessPass=getenv('F76_PG_READER_PASSWORD');
$invitePass=getenv('F112_PG_READER_PASSWORD');
foreach([$adminPass,$throttlePass,$businessPass,$invitePass] as $secret){
    if(!is_string($secret)||strlen($secret)<12){
        throw new RuntimeException('F118 needs CI-only ephemeral PostgreSQL roles.');
    }
}
$connect=static fn(string $role,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db=$connect('postgres',$adminPass);
$throttle=$connect('portal_f77_throttler',$throttlePass);
$business=$connect('portal_f76_reader',$businessPass);
$preinvite=$connect('portal_f112_reader',$invitePass);
$issuer=new PortalF118BusinessSessionIssuer($db,$throttle);
$invitationEvidence=new PortalF112OfflinePgSessionIdentitySource($preinvite);
$n=0;
$ok=static function(bool $condition,string $label)use(&$n):void{
    if(!$condition)throw new RuntimeException('PORTAL-F118 FAILED: '.$label);
    $n++;
};
$yes=static fn(mixed $v):bool=>in_array($v,[true,1,'t','1'],true);
$codeAt=static function(string $secret,int $epoch):string{
    $counter=intdiv($epoch,30);
    $hmac=hash_hmac('sha1',
        pack('N2',intdiv($counter,4294967296),$counter%4294967296),
        $secret,true
    );
    $offset=ord($hmac[19])&15;
    $n=((ord($hmac[$offset])&127)<<24)
        |(ord($hmac[$offset+1])<<16)
        |(ord($hmac[$offset+2])<<8)
        |ord($hmac[$offset+3]);
    return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);
};
$now=static fn():int=>(int)$db->query(
    'SELECT floor(extract(epoch from clock_timestamp()))::bigint'
)->fetchColumn();

$identity='71111111-1111-4111-8111-111111111111';
$org='72222222-2222-4222-8222-222222222222';
$membership='73333333-3333-4333-8333-333333333333';
$formerSessionId='74444444-4444-4444-8444-444444444444';
$email='f117-71111111@example.invalid';
$password='F118-Fresh-Business-Password-42!';
$secretBase32='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$secretRaw='12345678901234567890';
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$pepper=random_bytes(32);
$ip='192.0.2.18';
$otherIp='198.51.100.58';
$tenant=new OrganizationId($org);

// Exercise a real F117-activated membership fixture, NOT a newly created
// active member. F117 already revoked its original invitation_only session.
$before=$db->prepare(
    'SELECT status,membership_revision FROM portal_memberships WHERE id=?'
);
$before->execute([$membership]);
$membershipRecord=$before->fetch();
$ok(is_array($membershipRecord)
    &&$membershipRecord['status']==='active'
    &&(int)$membershipRecord['membership_revision']===2,
    'F117 acceptance activated the business membership and increased revision');

$old=$db->prepare(
    'SELECT session_sha256,status,session_purpose,revoked_at
      FROM portal_auth_sessions WHERE id=?'
);
$old->execute([$formerSessionId]);
$oldRecord=$old->fetch();
$ok(is_array($oldRecord)
    &&$oldRecord['status']==='revoked'
    &&$oldRecord['session_purpose']==='invitation_only'
    &&$oldRecord['revoked_at']!==null,
    'F117 original invitation-only credential was revoked, not upgraded');

$storedHash=PortalPasswordCredential::makeHash($password);
$sealed=PortalTotpSecretCipher::seal($secretBase32,$key);
$ok(is_string($storedHash)&&str_starts_with($storedHash,'$argon2id$')
    &&is_string($sealed)&&str_starts_with($sealed,'mfa-sb1:'),
    'synthetic active identity receives hashed password and encrypted TOTP');
$db->prepare(
    "UPDATE portal_identities
      SET password_hash=?,mfa_secret_ciphertext=?,mfa_last_used_counter=-1
      WHERE id=?"
)->execute([$storedHash,$sealed,$identity]);
$ok(!PortalF118BusinessSessionIssuer::mayIssueCustomerSession(),
    'laboratory class cannot issue customer logins');

$checkPermission=static function(string $digest,string $permission)
    use($business,$org,$yes):bool{
    $stmt=$business->prepare(
        'SELECT public.portal_f113_business_permission_check('
        .':digest,CAST(:org AS uuid),:permission)'
    );
    $stmt->execute([
        'digest'=>$digest,'org'=>$org,'permission'=>$permission,
    ]);
    return $yes($stmt->fetchColumn());
};
$ok(!$checkPermission($oldRecord['session_sha256'],'services.view'),
    'revoked invitation credential cannot reach business rights');

$ok($issuer->begin($email,'incorrect-'.$password,$org,$ip,$pepper)===null,
    'wrong password does not issue business session');
$ok($issuer->begin($email,$password,$org,$ip,'weak')===null,
    'weak rate-limit secret rejected');
$ok($issuer->begin($email,$password,
    '72222222-2222-4222-8222-999999999999',$ip,$pepper)===null,
    'correct password cannot create business session for another org');
$ok($issuer->begin($email,$password,$org,'spoofed-ip',$pepper)===null,
    'invalid source IP rejected');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions
      WHERE identity_id='$identity' AND session_purpose='business'"
)->fetchColumn()===0,'invalid attempts create no business sessions');

$bearer=$issuer->begin($email,$password,$org,$ip,$pepper);
$ok(is_string($bearer)&&strlen($bearer)===43
    &&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$bearer)===1,
    'fresh business credential has 256-bit canonical Base64URL token');
$digest=hash('sha256',(string)$bearer);
$ok($digest!==$oldRecord['session_sha256'],
    'business bearer is NEVER the original invitation-only bearer');

$session=$db->prepare(
    "SELECT status,session_purpose,session_sha256,
      floor(extract(epoch from issued_at))::bigint AS issued_epoch,
      expires_at-issued_at AS duration,
      identity_revision,membership_revision,organization_revision
      FROM portal_auth_sessions WHERE session_sha256=?"
);
$session->execute([$digest]);
$pending=$session->fetch();
$ok(is_array($pending)
    &&$pending['status']==='pending_mfa'
    &&$pending['session_purpose']==='business'
    &&$pending['session_sha256']===$digest
    &&(int)$pending['membership_revision']===2,
    'business purpose is immutable from initial pending MFA issuance');
$ok(!$checkPermission($digest,'services.view'),
    'password verification alone does not authorize any permission');
$ok($invitationEvidence->findFor($bearer,$tenant)===null,
    'business session cannot serve as invitation-only identity evidence');

$issued=(int)$pending['issued_epoch'];
$epoch=$now();
for($i=0;$epoch<$issued&&$i<30;$i++){
    usleep(100000);
    $epoch=$now();
}
$ok($epoch>=$issued,'CI PostgreSQL clock reaches new business issue time');
$code=$codeAt($secretRaw,$epoch);
$wrong=$code==='999999'?'888888':'999999';
$ok(!$issuer->complete($bearer,$key,$wrong,$ip,$pepper),
    'incorrect TOTP cannot activate business session');
$ok(!$issuer->complete($bearer,random_bytes(32),$code,$ip,$pepper),
    'wrong encryption key cannot activate business session');
$ok(!$issuer->complete('CRM-untrusted-session',$key,$code,$ip,$pepper),
    'foreign CRM bearer cannot finish Portal business MFA');
$code=$codeAt($secretRaw,$now());
$ok($issuer->complete($bearer,$key,$code,$ip,$pepper),
    'fresh enrolled TOTP activates separately issued business session');
$ok(!$issuer->complete($bearer,$key,$code,$ip,$pepper),
    'replayed MFA cannot re-activate the same business session');

$session->execute([$digest]);
$active=$session->fetch();
$ok($active['status']==='active'&&$active['session_purpose']==='business',
    'business session becomes active while purpose remains immutable');
$ttl=$db->prepare(
    "SELECT floor(extract(epoch FROM (expires_at-issued_at)))::bigint AS ttl,
       mfa_verified_at IS NOT NULL AS mfa FROM portal_auth_sessions
       WHERE session_sha256=?"
);
$ttl->execute([$digest]);
$length=$ttl->fetch();
$ok((int)$length['ttl']===8*3600
    &&$yes($length['mfa']),
    'business MFA completion sets eight-hour bounded lifetime');
$ok(!$checkPermission($digest,'services.view'),
    'valid business session without explicit grant still denies service access');
$ok(!$checkPermission($oldRecord['session_sha256'],'services.view'),
    'old revoked invitation session remains denied after business login');

$db->prepare(
    "INSERT INTO portal_membership_grants (
      id,membership_id,permission_code,created_at,updated_at
    ) VALUES(?,?,'services.view',now(),now())"
)->execute(['e7777777-7777-4777-8777-777777777777',$membership]);
$ok($checkPermission($digest,'services.view'),
    'explicit tenant grant allows only matching fresh business session');
$ok(!$checkPermission($digest,'billing.view'),
    'business session does not imply unrelated billing grant');
$ok(!$checkPermission($oldRecord['session_sha256'],'services.view'),
    'old invitation-only credential cannot inherit newly granted permission');

$another=$issuer->begin($email,$password,$org,$otherIp,$pepper);
$ok(is_string($another)&&$another!==$bearer,
    'new password step creates a distinct pending business bearer');
$ok(!$issuer->complete($another,$key,$code,$otherIp,$pepper),
    'durable TOTP counter rejects replay across business sessions');

$db->exec(
    "UPDATE portal_organizations SET security_revision=2 WHERE id='$org'"
);
$ok(!$checkPermission($digest,'services.view'),
    'organization revocation revision invalidates business permission');
$db->exec(
    "UPDATE portal_organizations SET security_revision=1 WHERE id='$org'"
);
$db->exec(
    "UPDATE portal_memberships SET membership_revision=3 WHERE id='$membership'"
);
$ok(!$checkPermission($digest,'services.view'),
    'membership revision changes invalidate business permission');
$db->exec(
    "UPDATE portal_memberships SET membership_revision=2 WHERE id='$membership'"
);
$ok($checkPermission($digest,'services.view'),
    'only restored fixture revisions permit the original explicit grant');

// A revoked business session must stop authorizing even when grants remain.
$db->prepare(
    "UPDATE portal_auth_sessions SET status='revoked',revoked_at=now()
      WHERE session_sha256=?"
)->execute([$digest]);
$ok(!$checkPermission($digest,'services.view'),
    'explicit business session revocation immediately disables access');

// F77 throttles brute-force passwords before creating any new session.
for($i=0;$i<5;$i++){
    $ok($issuer->begin($email,'F118-incorrect',$org,'203.0.113.118',$pepper)===null,
        'incorrect credentials denied during active-member throttle');
}
$ok($issuer->begin($email,$password,$org,'203.0.113.118',$pepper)===null,
    'sixth attempt blocked even when password is correct');
putenv('PORTAL_F118_PG_LAB=0');
$ok($issuer->begin($email,$password,$org,$ip,$pepper)===null,
    'disabled laboratory flag refuses business issuer');
putenv('PORTAL_F118_PG_LAB=1');
putenv('APP_ENV=production');
$ok($issuer->begin($email,$password,$org,$ip,$pepper)===null,
    'production environment cannot execute lab issuer');

sodium_memzero($key);
sodium_memzero($pepper);
echo "PASS PORTAL-F118 {$n} new business credential, fresh MFA, tenant grants, revocation and separated invitation scope checks\n";
