<?php

declare(strict_types=1);

require_once __DIR__.'/PortalF114InvitedSessionIssuer.php';
require_once dirname(__DIR__).'/identity-f112/PortalF112OfflinePgSessionIdentitySource.php';

use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\Identity\Invitations\PortalInvitationPreflight;
use App\Domain\Identity\Invitations\PortalInvitationSecret;
use App\Domain\Organizations\OrganizationId;

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F114_PG_LAB') !== '1'
    || getenv('PORTAL_F112_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F114 uses disposable PostgreSQL 18 CI only.');
}
$adminPass=getenv('F66_PG_ADMIN_PASSWORD');
$throttlePass=getenv('F77_PG_THROTTLE_PASSWORD');
$readerPass=getenv('F112_PG_READER_PASSWORD');
$businessPass=getenv('F76_PG_READER_PASSWORD');
if (!is_string($adminPass)||strlen($adminPass)<12
    || !is_string($throttlePass)||strlen($throttlePass)<12
    || !is_string($readerPass)||strlen($readerPass)<12
    || !is_string($businessPass)||strlen($businessPass)<12) {
    throw new RuntimeException('F114 needs CI-only ephemeral credentials.');
}
$pdo=static fn(string $role,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db=$pdo('postgres',$adminPass);
$throttler=$pdo('portal_f77_throttler',$throttlePass);
$reader=$pdo('portal_f112_reader',$readerPass);
$business=$pdo('portal_f76_reader',$businessPass);
$issuer=new PortalF114InvitedSessionIssuer($db,$throttler);
$source=new PortalF112OfflinePgSessionIdentitySource($reader);
$gate=new PortalInvitationPreflight($source);
$n=0;
$ok=static function(bool $value,string $why)use(&$n):void{
    if (!$value)throw new RuntimeException('PORTAL-F114 FAILED '.$why);
    $n++;
};
$yes=static fn(mixed $value):bool=>in_array($value,[true,1,'1','t'],true);
$totp=static function(string $rawSecret,int $counter):string {
    $digest=hash_hmac(
        'sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),
        $rawSecret,true
    );
    $offset=ord($digest[19])&0x0f;
    $num=((ord($digest[$offset])&0x7f)<<24)
        |(ord($digest[$offset+1])<<16)
        |(ord($digest[$offset+2])<<8)
        |ord($digest[$offset+3]);
    return str_pad((string)($num%1000000),6,'0',STR_PAD_LEFT);
};

$identity='c1111111-1111-4111-8111-111111111111';
$org='c2222222-2222-4222-8222-222222222222';
$other='c3333333-3333-4333-8333-333333333333';
$membership='c4444444-4444-4444-8444-444444444444';
$email='f114-provisional@example.invalid';
$password='F114-Only-Synthetic-Passphrase-42!';
$hash=PortalPasswordCredential::makeHash($password);
$encryptionKey=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$pepper=random_bytes(32);
$rawSecret='12345678901234567890';
$base32Secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$sealed=PortalTotpSecretCipher::seal($base32Secret,$encryptionKey);
$ok(is_string($hash)&&str_starts_with($hash,'$argon2id$'),
    'actual Argon2id password hash fixture');
$ok(is_string($sealed)&&!str_contains($sealed,$base32Secret),
    'enrolled TOTP secret stored under test-only encrypted envelope');
$ok(!PortalF114InvitedSessionIssuer::mayIssueCustomerSession(),
    'synthetic issuer cannot activate customer sessions');

$db->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,password_hash,status,email_verified_at,
       mfa_secret_ciphertext,mfa_confirmed_at,security_revision,
       mfa_last_used_counter,created_at,updated_at)
     VALUES(?,?,?,'active',now()-interval '1 day',?,
       now()-interval '1 day',1,-1,now(),now())"
)->execute([$identity,$email,$hash,$sealed]);
$insertOrg=$db->prepare(
    "INSERT INTO portal_organizations
      (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$insertOrg->execute([$org,'f114-home','F114 synthetic home']);
$insertOrg->execute([$other,'f114-other','F114 foreign company']);
$db->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,
       created_at,updated_at)
     VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$membership,$identity,$org]);

$ip='198.51.100.41';
$ipOther='192.0.2.49';
$origin=new OrganizationId($org);
$inviteToken=PortalInvitationSecret::mint()->takeDeliverySecretOnce();
if (!is_string($inviteToken))throw new RuntimeException('No synthetic invite secret.');
$sessionCount=static fn():int=>(int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='c1111111-1111-4111-8111-111111111111'"
)->fetchColumn();
$baseCount=$sessionCount();

$ok($issuer->begin($email,'invalid-F114-password',$org,$ip,$pepper)===null,
    'wrong Argon2id password rejected');
$ok($issuer->begin($email,$password,$other,$ip,$pepper)===null,
    'correct password cannot open foreign tenant');
$ok($issuer->begin($email,$password,$org,'spoofed-ip',$pepper)===null,
    'untrusted IP format rejected');
$ok($issuer->begin($email,$password,$org,$ip,'weak-pepper')===null,
    'short server rate-limit secret denied');
$ok($sessionCount()===$baseCount,'negative attempts never create sessions');

$bearer=$issuer->begin($email,$password,$org,$ip,$pepper);
$ok(is_string($bearer)&&strlen($bearer)===43,
    'valid invited membership issues 256-bit provisional bearer');
$session=$db->prepare(
    "SELECT session_sha256,status,session_purpose,
        extract(epoch FROM (expires_at-issued_at)) AS ttl
       FROM portal_auth_sessions WHERE session_sha256=?"
);
$session->execute([hash('sha256',(string)$bearer)]);
$pending=$session->fetch();
$ok(is_array($pending)
    && $pending['status']==='pending_mfa'
    && $pending['session_purpose']==='invitation_only'
    && $pending['session_sha256']===hash('sha256',(string)$bearer)
    && $pending['session_sha256']!==$bearer
    && (int)$pending['ttl']===300,
    'provisional session purpose immutable and bearer stored only as digest');
$ok($source->findFor($bearer,$origin)===null,
    'session before MFA cannot pass first-party evidence gate');
$checkBusiness=static function(string $sessionRef)use($business,$org,$yes):bool{
    $q=$business->prepare(
        'SELECT public.portal_f113_business_permission_check('
        .':digest,CAST(:org AS uuid),:permission)'
    );
    $q->execute([
        'digest'=>hash('sha256',$sessionRef),
        'org'=>$org,'permission'=>'services.view',
    ]);
    return $yes($q->fetchColumn());
};
$ok(!$checkBusiness($bearer),'provisional session does not imply service grant');

$nowEpoch=(int)$db->query(
    'SELECT floor(extract(epoch FROM clock_timestamp()))::bigint'
)->fetchColumn();
$code=$totp($rawSecret,intdiv($nowEpoch,30));
$wrong=$code==='999999'?'888888':'999999';
$ok(!$issuer->complete($bearer,$encryptionKey,$wrong,$ip,$pepper),
    'incorrect enrolled TOTP refused');
$ok(!$issuer->complete($bearer,random_bytes(32),$code,$ip,$pepper),
    'wrong encryption key fails closed');
$ok(!$issuer->complete('CRM-cookie-not-Portal',$encryptionKey,$code,$ip,$pepper),
    'foreign CRM token cannot finish MFA');
// F77 and Argon2id integration can take variable time on shared CI runners.
// Recompute the enrolled TOTP from PostgreSQL time immediately before
// the acceptance check, rather than depending on the earlier 30s slot.
$nowEpoch=(int)$db->query(
    'SELECT floor(extract(epoch FROM clock_timestamp()))::bigint'
)->fetchColumn();
$code=$totp($rawSecret,intdiv($nowEpoch,30));
$completed=$issuer->complete($bearer,$encryptionKey,$code,$ip,$pepper);
if(!$completed) {
    // Test-only, non-secret status diagnostics for shared CI runners.
    // Never print email, bearer, digest, password, OTP or secret material.
    $debug=$db->prepare(
        "SELECT s.status AS session_status,s.session_purpose,
          floor(extract(epoch from s.issued_at))::bigint AS issued_epoch,
          floor(extract(epoch from s.expires_at))::bigint AS expires_epoch,
          floor(extract(epoch from clock_timestamp()))::bigint AS db_epoch,
          s.mfa_verified_at IS NOT NULL AS mfa_recorded,
          s.identity_revision,i.security_revision,
          s.membership_revision,m.membership_revision AS current_membership_revision,
          s.organization_revision,o.security_revision AS current_org_revision,
          i.mfa_last_used_counter,m.status AS membership_status,o.status AS org_status
         FROM portal_auth_sessions s
         JOIN portal_identities i ON i.id=s.identity_id
         JOIN portal_memberships m ON m.identity_id=s.identity_id
          AND m.organization_id=s.organization_id
         JOIN portal_organizations o ON o.id=s.organization_id
         WHERE s.session_sha256=:digest"
    );
    $debug->execute(['digest'=>hash('sha256',$bearer)]);
    $state=$debug->fetch();
    $pepperScope=hash_hmac('sha256',
        'portal-f114|invitation-only|totp|'.hash('sha256',$bearer).'|'.$ip,
        $pepper
    );
    $bucket=$db->prepare(
        "SELECT attempts FROM portal_auth_attempt_buckets
         WHERE scope_hash=:scope AND action_code='totp'"
    );
    $bucket->execute(['scope'=>$pepperScope]);
    $storedCounter=is_array($state)?(int)$state['mfa_last_used_counter']:-2;
    $dbNow=is_array($state)?(int)$state['db_epoch']:0;
    $matched=PortalTotpVerifier::matchCounter(
        $base32Secret,$code,$dbNow,$storedCounter
    );
    $safe=[
        'row_found'=>is_array($state),
        'session_status'=>$state['session_status']??null,
        'purpose'=>$state['session_purpose']??null,
        'lifetime_seconds'=>is_array($state)
            ? (int)$state['expires_epoch']-(int)$state['issued_epoch']:null,
        'age_seconds'=>is_array($state)
            ? (int)$state['db_epoch']-(int)$state['issued_epoch']:null,
        'mfa_recorded'=>$state['mfa_recorded']??null,
        'revisions_match'=>is_array($state)
            && $state['identity_revision']===$state['security_revision']
            && $state['membership_revision']===$state['current_membership_revision']
            && $state['organization_revision']===$state['current_org_revision'],
        'membership_status'=>$state['membership_status']??null,
        'org_status'=>$state['org_status']??null,
        'otp_matches_db_counter'=>$matched!==null,
        'last_used_counter_nonnegative'=>$storedCounter>=0,
        'throttle_attempts'=>(int)$bucket->fetchColumn(),
        'db_transaction_open'=>$db->inTransaction(),
    ];
    fwrite(STDERR,'PORTAL-F114 TEST DIAGNOSTIC '
        .json_encode($safe,JSON_THROW_ON_ERROR)."\n");
}
$ok($completed,
    'correct password followed by enrolled TOTP completes provisional session');
$ok(!$issuer->complete($bearer,$encryptionKey,$code,$ip,$pepper),
    'same provisional session cannot accept TOTP twice');
$ok($source->findFor($bearer,$origin)!==null,
    'F112 evidence now comes from authenticated synthetic session record');
$verdict=$gate->inspect($bearer,$inviteToken,$origin,time());
$ok($verdict->syntheticInputsPlausible
    && !$verdict->canConsumeInvitation()
    && !$verdict->canIssueSession()
    && !$verdict->canAuthorizeHttp(),
    'F110 permits a synthetic candidate but NEVER actual access or redemption');
$ok(!$checkBusiness($bearer),'MFA completion does not grant business permissions');

$another=$issuer->begin($email,$password,$org,$ipOther,$pepper);
$ok(is_string($another)&&$another!==$bearer,
    'separate source can create another pending-only token');
$ok(!$issuer->complete($another,$encryptionKey,$code,$ipOther,$pepper),
    'durable TOTP counter prevents replay across two sessions');
$counter=$db->query(
    "SELECT mfa_last_used_counter FROM portal_identities WHERE id='$identity'"
)->fetchColumn();
$ok((int)$counter>=0,'TOTP replay counter persisted server-side in PostgreSQL');

$db->prepare(
    "INSERT INTO portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute(['c5555555-5555-4555-8555-555555555555',$membership]);
$db->exec("UPDATE portal_memberships SET status='active' WHERE id='$membership'");
$ok(!$checkBusiness($bearer),
    'activation without revision increment still cannot elevate provisional credential');
$ok($source->findFor($bearer,$origin)===null,
    'active membership is not invitation acceptance evidence');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$identity'
      AND session_purpose='business'"
)->fetchColumn()===0,'no business-purpose session issued by F114');

$db->exec("UPDATE portal_memberships SET status='invited' WHERE id='$membership'");
for($attempt=0;$attempt<5;$attempt++){
    $ok($issuer->begin($email,'wrong-F114-password',$org,'203.0.113.77',$pepper)===null,
        'wrong password remains denied during brute-force attempts');
}
$ok($issuer->begin($email,$password,$org,'203.0.113.77',$pepper)===null,
    'F77 sixth attempt blocked even with correct password');
$ok($sessionCount()===$baseCount+2,
    'throttle cannot create a third pending session');

putenv('PORTAL_F114_PG_LAB=0');
$ok($issuer->begin($email,$password,$org,$ip,$pepper)===null,
    'disabled laboratory denies issuance');
putenv('PORTAL_F114_PG_LAB=1');
putenv('APP_ENV=production');
$ok($issuer->begin($email,$password,$org,$ip,$pepper)===null,
    'production environment never issues provisional bearer');
$ok(!$issuer->complete($bearer,$encryptionKey,$code,$ip,$pepper),
    'production environment never processes TOTP via lab');

sodium_memzero($pepper);
sodium_memzero($encryptionKey);
echo "PASS PORTAL-F114 {$n} invited-only password->MFA->purpose-safe PG18 assertions\n";
