<?php

declare(strict_types=1);

require_once __DIR__.'/PortalF116EmailPasswordTotpEnrollment.php';
require_once dirname(__DIR__).'/identity-f114/PortalF114InvitedSessionIssuer.php';
require_once dirname(__DIR__).'/identity-f112/PortalF112OfflinePgSessionIdentitySource.php';

use App\Domain\Identity\Invitations\PortalEmailVerificationSecret;
use App\Domain\Identity\Invitations\PortalInvitationSecret;
use App\Domain\Identity\Invitations\PortalInvitationPreflight;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Organizations\OrganizationId;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F116_PG_LAB') !== '1'
    || getenv('PORTAL_F115_PG_LAB') !== '1'
    || getenv('PORTAL_F114_PG_LAB') !== '1'
    || getenv('PORTAL_F112_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F116 is a disposable local PostgreSQL 18 CI test only.');
}
$adminPass=getenv('F66_PG_ADMIN_PASSWORD');
$throttlePass=getenv('F77_PG_THROTTLE_PASSWORD');
$emailProbePass=getenv('F115_PG_PROBE_PASSWORD');
$evidencePass=getenv('F112_PG_READER_PASSWORD');
$businessPass=getenv('F76_PG_READER_PASSWORD');
foreach ([$adminPass,$throttlePass,$emailProbePass,$evidencePass,$businessPass] as $password) {
    if (!is_string($password)||strlen($password)<12) {
        throw new RuntimeException('F116 requires ephemeral CI credentials.');
    }
}
$pdo=static fn(string $role,string $pass):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db=$pdo('postgres',$adminPass);
$throttle=$pdo('portal_f77_throttler',$throttlePass);
$proofReader=$pdo('portal_f115_email_probe',$emailProbePass);
$sessionReader=$pdo('portal_f112_reader',$evidencePass);
$businessReader=$pdo('portal_f76_reader',$businessPass);
$enrollment=new PortalF116EmailPasswordTotpEnrollment($db,$throttle);
$issuer=new PortalF114InvitedSessionIssuer($db,$throttle);
$evidence=new PortalF112OfflinePgSessionIdentitySource($sessionReader);
$preflight=new PortalInvitationPreflight($evidence);
$n=0;
$ok=static function(bool $test,string $label)use(&$n):void{
    if(!$test)throw new RuntimeException('PORTAL-F116 FAILED: '.$label);
    $n++;
};
$yes=static fn(mixed $value):bool=>in_array($value,[true,1,'1','t'],true);
$rawFromBase32=static function(string $encoded):string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer=0;$bits=0;$raw='';
    foreach (str_split($encoded) as $char) {
        $value=strpos($alphabet,$char);
        if ($value===false)throw new RuntimeException('Invalid generated test secret');
        $buffer=($buffer<<5)|$value;
        $bits+=5;
        while($bits>=8){
            $bits-=8;
            $raw.=chr(($buffer>>$bits)&255);
            $buffer &= (1<<$bits)-1;
        }
    }
    return $raw;
};
$codeAt=static function(string $raw,int $epoch):string {
    $counter=intdiv($epoch,30);
    $binary=pack('N2',intdiv($counter,4294967296),$counter%4294967296);
    $mac=hash_hmac('sha1',$binary,$raw,true);
    $offset=ord($mac[19])&15;
    $number=((ord($mac[$offset])&127)<<24)
        |(ord($mac[$offset+1])<<16)
        |(ord($mac[$offset+2])<<8)
        |ord($mac[$offset+3]);
    return str_pad((string)($number%1000000),6,'0',STR_PAD_LEFT);
};
$dbEpoch=static fn():int=>(int)$db->query(
    'SELECT floor(extract(epoch FROM clock_timestamp()))::bigint'
)->fetchColumn();
$identity='b1111111-2222-4111-8111-111111111111';
$org='b2222222-3333-4222-8222-222222222222';
$member='b3333333-4444-4333-8333-333333333333';
$challenge='b4444444-5555-4444-8444-444444444444';
$email='f116-fresh@example.invalid';
$password='F116-Synthetic-Password-Enrollment-42!';
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$pepper=random_bytes(32);
$ip='198.51.100.111';
$tenant=new OrganizationId($org);

$db->prepare(
    "INSERT INTO portal_identities(
       id,email_normalized,status,security_revision,created_at,updated_at
     ) VALUES(?,?,'invited',1,now(),now())"
)->execute([$identity,$email]);
$db->prepare(
    "INSERT INTO portal_organizations(
       id,reference,display_name,status,security_revision,created_at,updated_at
     ) VALUES(?,'f116-home','F116 Synthetic Company','active',1,now(),now())"
)->execute([$org]);
$db->prepare(
    "INSERT INTO portal_memberships(
       id,identity_id,organization_id,role,status,membership_revision,
       created_at,updated_at
     ) VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$member,$identity,$org]);

$emailSecret=PortalEmailVerificationSecret::mint();
$emailBearer=$emailSecret->takeDeliverySecretOnce();
if(!is_string($emailBearer))throw new RuntimeException('Missing synthetic email token');
$db->prepare(
    "INSERT INTO portal_email_verification_challenges(
      id,token_sha256,identity_id,email_sha256,identity_revision,
      status,issued_at,expires_at,created_at,updated_at
    ) VALUES(?,?,?,?,1,'pending',clock_timestamp()-interval '1 minute',
      clock_timestamp()+interval '15 minutes',now(),now())"
)->execute([$challenge,$emailSecret->digest(),$identity,hash('sha256',$email)]);
$confirmEmail=static function(string $digest,string $identityId)use($proofReader,$yes):bool {
    $stmt=$proofReader->prepare(
        'SELECT public.portal_f115_confirm_email(:digest,CAST(:identity AS uuid))'
    );
    $stmt->execute(['digest'=>$digest,'identity'=>$identityId]);
    return $yes($stmt->fetchColumn());
};
$source=static fn():array=>$db->query(
    "SELECT status,security_revision,email_verified_at,password_hash,
      mfa_secret_ciphertext,mfa_confirmed_at,mfa_last_used_counter
      FROM portal_identities
      WHERE id='b1111111-2222-4111-8111-111111111111'"
)->fetch(PDO::FETCH_ASSOC);
$ok(!PortalF116EmailPasswordTotpEnrollment::mayEnrollRealCustomers(),
    'laboratory enrollment cannot enroll real customers');
$ok($enrollment->begin($emailBearer,$identity,$password,$key)===null,
    'pending email token cannot start password/TOTP enrollment');
$ok(!$confirmEmail(PortalInvitationSecret::digestCandidate($emailBearer),$identity),
    'an invitation token digest does not confirm an email');
$ok($confirmEmail($emailSecret->digest(),$identity),
    'previously delivered synthetic email proof consumed atomically');
$ok(!$confirmEmail($emailSecret->digest(),$identity),
    'consumed email proof cannot be replayed to verify again');
$before=$source();
$ok($before['email_verified_at']!==null && $before['status']==='invited'
    && $before['password_hash']===null
    && $before['mfa_secret_ciphertext']===null,
    'email proof alone leaves identity invited without password or MFA');

 // F115 and F116 intentionally reject a consumed_at timestamp in the
 // future. The shared CI clock can step back briefly after SQL commits:
 // wait for the database itself to show the receipt at least one second old,
 // never modify the production time guard or fabricate a second proof.
 $age=$db->prepare(
     "SELECT extract(epoch FROM (clock_timestamp()-consumed_at))
        FROM portal_email_verification_challenges WHERE id=?"
 );
 $age->execute([$challenge]);
 $ageSeconds=(float)$age->fetchColumn();
 for($retry=0;$ageSeconds<1.0&&$retry<50;$retry++){
     usleep(100000);
     $age->execute([$challenge]);
     $ageSeconds=(float)$age->fetchColumn();
 }
 $ok($ageSeconds>=1.0,
     'CI PostgreSQL clock has passed first-party email proof timestamp');


$ok($enrollment->begin($emailBearer,$identity,'short',$key)===null,
    'short password rejected');
$ok($enrollment->begin($emailBearer,$identity,$password,'short-key')===null,
    'invalid MFA encryption key denied');
$ok($enrollment->begin('CRM-cookie-invalid',$identity,$password,$key)===null,
    'foreign CRM bearer cannot initiate enrollment');
$ok($enrollment->begin($emailBearer,'b9999999-9999-4999-8999-999999999999',
    $password,$key)===null,'email proof bound to identity ID');
$ok($source()['password_hash']===null,
    'invalid attempts do not save a password hash');

$enrolledBase32=$enrollment->begin($emailBearer,$identity,$password,$key);
$ok(is_string($enrolledBase32)
    && strlen($enrolledBase32)===32
    && preg_match('/\A[A-Z2-7]{32}\z/D',$enrolledBase32)===1,
    'server generates strict 160-bit Base32 TOTP secret');
$after=$source();
$ok(PortalPasswordCredential::verify($password,$after['password_hash'])
    && $after['mfa_secret_ciphertext']!==null
    && str_starts_with($after['mfa_secret_ciphertext'],'mfa-sb1:')
    && !str_contains($after['mfa_secret_ciphertext'],$enrolledBase32),
    'Argon2id password and encrypted TOTP secret saved without plaintext');
$ok($after['status']==='invited'&&$after['mfa_confirmed_at']===null
    &&(int)$after['mfa_last_used_counter']===-1,
    'pending MFA enrollment never activates identity');
$ok($enrollment->begin($emailBearer,$identity,$password,$key)===null,
    'second enrollment cannot replace already installed credentials');
$raw=$rawFromBase32($enrolledBase32);
$ok(strlen($raw)===20,'generated TOTP secret has 160 raw bits');

$epoch=$dbEpoch();
$code=$codeAt($raw,$epoch);
$incorrect=$code==='999999'?'888888':'999999';
$ok(!$enrollment->complete($emailBearer,$identity,$incorrect,$key,$ip,$pepper),
    'incorrect TOTP cannot activate identity');
$ok(!$enrollment->complete($emailBearer,$identity,$code,random_bytes(32),$ip,$pepper),
    'wrong server encryption key cannot activate identity');
$ok($source()['status']==='invited',
    'invalid TOTP/code/key does not activate invited identity');

// Shared CI runner time may tick over a 30-second TOTP boundary.
$epoch=$dbEpoch();
$code=$codeAt($raw,$epoch);
$ok($enrollment->complete($emailBearer,$identity,$code,$key,$ip,$pepper),
    'first valid enrolled TOTP activates identity after email verification');
$active=$source();
$ok($active['status']==='active'
    &&$active['mfa_confirmed_at']!==null
    &&(int)$active['security_revision']===2
    &&(int)$active['mfa_last_used_counter']>=0,
    'TOTP proof atomically activates identity and raises security revision');
$ok(!$enrollment->complete($emailBearer,$identity,$code,$key,$ip,$pepper),
    'MFA enrollment cannot be replayed');

$membershipStatus=$db->query(
    "SELECT status FROM portal_memberships WHERE id='$member'"
)->fetchColumn();
$ok($membershipStatus==='invited',
    'identity activation does NOT activate membership');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$identity'"
)->fetchColumn()===0,'initial email/password/MFA flow creates no session');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_membership_grants WHERE membership_id='$member'"
)->fetchColumn()===0,'initial credentials do not imply any service grant');

$pendingBearer=$issuer->begin($email,$password,$org,$ip,$pepper);
$ok(is_string($pendingBearer)&&strlen($pendingBearer)===43,
    'newly enrolled identity can begin F114 provisional invited-only login');
$pending=$db->prepare(
    "SELECT status,session_purpose FROM portal_auth_sessions
      WHERE session_sha256=?"
);
$pending->execute([hash('sha256',(string)$pendingBearer)]);
$p=$pending->fetch();
$ok(is_array($p)&&$p['status']==='pending_mfa'
    &&$p['session_purpose']==='invitation_only',
    'post-enrollment login remains pending MFA and invitation-only');
$ok($evidence->findFor($pendingBearer,$tenant)===null,
    'pre-MFA provisional session has no identity evidence');

$issueEpoch=$db->prepare(
    "SELECT floor(extract(epoch from issued_at))::bigint
       FROM portal_auth_sessions WHERE session_sha256=?"
);
$issueEpoch->execute([hash('sha256',$pendingBearer)]);
$issued=(int)$issueEpoch->fetchColumn();
$now=$dbEpoch();
for ($i=0;$now<$issued && $i<30;$i++) {
    usleep(100000);
    $now=$dbEpoch();
}
$ok($now>=$issued,'PostgreSQL test clock reached session issue epoch');
// The first proof consumed one counter; a code from the next counter is
// accepted by RFC6238's +1 skew and cannot replay the enrollment code.
$nextCode=$codeAt($raw,$now+30);
$ok($issuer->complete($pendingBearer,$key,$nextCode,$ip,$pepper),
    'distinct later TOTP counter completes provisional login without replay');
$verified=null;
for($evidenceAttempt=0;$evidenceAttempt<50;$evidenceAttempt++){
    $verified=$evidence->findFor($pendingBearer,$tenant);
    if($verified!==null)break;
    usleep(100000);
}
$ok($verified!==null,
    'F112 derives authenticated synthetic invited-session evidence from DB');
$invite=PortalInvitationSecret::mint()->takeDeliverySecretOnce();
if(!is_string($invite))throw new RuntimeException('missing synthetic invitation bearer');
$verdict=$preflight->inspect($pendingBearer,$invite,$tenant,$dbEpoch());
$ok($verdict->syntheticInputsPlausible
    &&!$verdict->canAuthorizeHttp()
    &&!$verdict->canConsumeInvitation()
    &&!$verdict->canIssueSession(),
    'F110 still blocks all real access despite complete synthetic onboarding');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions
      WHERE identity_id='$identity' AND session_purpose='business'"
)->fetchColumn()===0,'no business session has been issued');

putenv('PORTAL_F116_PG_LAB=0');
$ok($enrollment->begin($emailBearer,$identity,$password,$key)===null,
    'disabled lab gate denies new enrollment');
putenv('PORTAL_F116_PG_LAB=1');
putenv('APP_ENV=production');
$ok(!$enrollment->complete($emailBearer,$identity,$code,$key,$ip,$pepper),
    'production environment rejects laboratory enrollment');

sodium_memzero($key);
sodium_memzero($pepper);
sodium_memzero($raw);
echo "PASS PORTAL-F116 {$n} email->password->TOTP->provisional-only Portal checks\n";
