<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Labs\IdentityF81\PortalOrgRevisionMfaCompleter;
use App\Labs\IdentityF82\PortalSyntheticPasswordSessionIssuer;

if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_F82_PG_LAB')!=='1' || getenv('PORTAL_F81_PG_LAB')!=='1'
    || getenv('PGHOST')!=='127.0.0.1' || getenv('PGPORT')!=='5432'
    || getenv('PGDATABASE')!=='portal_identity_lab') {
    throw new RuntimeException('F82 requires disposable local PostgreSQL only.');
}

function f82Connect(string $role,string $password): PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
        $role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false]);
}
function f82Code(string $rawSecret,int $counter): string {
    $digest=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),$rawSecret,true);
    $offset=ord($digest[19])&15;
    $number=((ord($digest[$offset])&127)<<24)
        | (ord($digest[$offset+1])<<16)
        | (ord($digest[$offset+2])<<8)
        | ord($digest[$offset+3]);
    return str_pad((string)($number%1000000),6,'0',STR_PAD_LEFT);
}
$adminPass=getenv('F66_PG_ADMIN_PASSWORD');
$throttlePass=getenv('F77_PG_THROTTLE_PASSWORD');
$readerPass=getenv('F76_PG_READER_PASSWORD');
if (!is_string($adminPass)||strlen($adminPass)<12
    || !is_string($throttlePass)||strlen($throttlePass)<12
    || !is_string($readerPass)||strlen($readerPass)<12) {
    throw new RuntimeException('F82 needs ephemeral CI-only passwords.');
}
$db=f82Connect('postgres',$adminPass);
$throttle=f82Connect('portal_f77_throttler',$throttlePass);
$reader=f82Connect('portal_f76_reader',$readerPass);
$issuer=new PortalSyntheticPasswordSessionIssuer($db,$throttle);
$completer=new PortalOrgRevisionMfaCompleter($db);
$count=0;
$ok=static function(bool $valid,string $name)use(&$count):void{
    if(!$valid)throw new RuntimeException('F82 FAIL: '.$name);
    ++$count;
};
$id='c1111111-1111-4111-8111-111111111111';
$org='c2222222-2222-4222-8222-222222222222';
$foreign='c3333333-3333-4333-8333-333333333333';
$member='c4444444-4444-4444-8444-444444444444';
$email='synthetic-f82@example.invalid';
$passphrase='F82-Only-Synthetic-Credential-42!';
$passhash=PortalPasswordCredential::makeHash($passphrase);
$pepper=random_bytes(32);
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$sealed=PortalTotpSecretCipher::seal($secret,$key);
$ok(is_string($passhash)&&str_starts_with($passhash,'$argon2id$'),'Argon2id used');
$ok(is_string($sealed)&&!str_contains($sealed,$secret),'TOTP secret stored encrypted');
$db->prepare(
    "INSERT INTO portal_identities
    (id,email_normalized,password_hash,status,email_verified_at,
     mfa_secret_ciphertext,mfa_confirmed_at,security_revision,created_at,updated_at)
    VALUES(?,?,?,'active',now()-interval '1 day',?,now()-interval '1 day',1,now(),now())"
)->execute([$id,$email,$passhash,$sealed]);
$orgInsert=$db->prepare(
    "INSERT INTO portal_organizations
    (id,reference,display_name,status,security_revision,created_at,updated_at)
    VALUES(?,?,?,'active',1,now(),now())");
$orgInsert->execute([$org,'f82-org','F82 synthetic org']);
$orgInsert->execute([$foreign,'f82-foreign','F82 foreign org']);
$db->prepare(
    "INSERT INTO portal_memberships
    (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
    VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$member,$id,$org]);
$db->prepare(
    "INSERT INTO portal_membership_grants
    (id,membership_id,permission_code,created_at,updated_at)
    VALUES(?,?,'services.view',now(),now())"
)->execute(['c5555555-5555-4555-8555-555555555555',$member]);

$source='198.51.100.42'; // RFC 5737 documentation-only
$check=static function(string $bearer,string $tenant,string $permission)use($reader):bool{
    $q=$reader->prepare(
        'SELECT public.portal_f76_permission_check(:digest,CAST(:tenant AS uuid),:permission)');
    $q->execute(['digest'=>hash('sha256',$bearer),'tenant'=>$tenant,'permission'=>$permission]);
    return in_array($q->fetchColumn(),[true,1,'1','t'],true);
};
$sessionCount=static fn():int=>(int)$db->query('SELECT count(*) FROM portal_auth_sessions')->fetchColumn();

$ok($issuer->begin($email,'wrong-F82-synthetic-password',$org,$source,$pepper)===null,
    'incorrect Argon2id credential denied');
$ok($sessionCount()===0,'wrong password cannot create pending session');
$ok($issuer->begin($email,$passphrase,$foreign,$source,$pepper)===null,
    'correct password cannot create cross-tenant session');
$ok($issuer->begin('unknown@example.invalid',$passphrase,$org,$source,$pepper)===null,
    'unknown account is denied');
$ok($issuer->begin($email,$passphrase,$org,'spoofed-ip',$pepper)===null,
    'invalid trusted-source IP rejected');
$ok($issuer->begin($email,$passphrase,$org,$source,'short-pepper')===null,
    'server scope pepper must be at least 32 bytes');

$bearer=$issuer->begin($email,$passphrase,$org,$source,$pepper);
$ok(is_string($bearer)&&strlen($bearer)===43,'256-bit random pending bearer emitted');
$ok($sessionCount()===1,'one pending session created only after valid password');
$q=$db->prepare(
    "SELECT status,session_sha256,identity_revision,membership_revision,
      organization_revision,EXTRACT(EPOCH FROM (expires_at-issued_at)) AS lifetime
     FROM portal_auth_sessions WHERE session_sha256=?");
$q->execute([hash('sha256',$bearer)]);
$pending=$q->fetch();
$ok(is_array($pending)&&$pending['status']==='pending_mfa','session cannot bypass MFA');
$ok($pending['session_sha256']===hash('sha256',$bearer)
    && $pending['session_sha256']!==$bearer,'bearer stored only as SHA256');
$ok((int)$pending['lifetime']===300,'session pending MFA lifetime five minutes');
$ok((int)$pending['identity_revision']===1
    && (int)$pending['membership_revision']===1
    && (int)$pending['organization_revision']===1,'all three security revisions captured');
$ok(!$check($bearer,$org,'services.view'),'pending session cannot authorize a grant');
$ok(!$check($bearer,$foreign,'services.view'),'pending session cannot cross tenant');

$epoch=time();
$code=f82Code('12345678901234567890',intdiv($epoch,30));
$ok(!$completer->complete($bearer,$key,'000000',$epoch) || $code==='000000',
    'incorrect OTP denied unless it coincides with valid vector code');
// A changed organization revision must reject MFA without spending its counter.
$db->exec("UPDATE portal_organizations SET security_revision=2 WHERE id='$org'");
$ok(!$completer->complete($bearer,$key,$code,$epoch),
    'organization security revision prevents MFA completion');
$db->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$org'");
// Verify genuine OTP after the revision has returned to fixture value.
$ok($completer->complete($bearer,$key,$code,$epoch),'valid enrolled TOTP completes MFA');
$ok($check($bearer,$org,'services.view'),
    'only after MFA is grant visible via execute-only PG authority');
$ok(!$check($bearer,$org,'billing.view'),
    'role is not wildcard and billing grant is absent');
$ok(!$check($bearer,$foreign,'services.view'),'MFA does not confer foreign tenant rights');
$ok(!$completer->complete($bearer,$key,$code,$epoch),
    'completed session cannot reuse TOTP');

$db->exec("UPDATE portal_memberships SET membership_revision=2 WHERE id='$member'");
$ok(!$check($bearer,$org,'services.view'),'stale membership revision revokes permission');
$db->exec("UPDATE portal_memberships SET membership_revision=1 WHERE id='$member'");
$db->exec("UPDATE portal_organizations SET security_revision=2 WHERE id='$org'");
$ok(!$check($bearer,$org,'services.view'),'stale organization revision revokes permission');
$db->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$org'");

$ok($issuer->begin($email,'wrong-F82-synthetic-password',$org,$source,$pepper)===null,
    'further wrong password is denied and counted');
$ok($issuer->begin($email,'wrong-F82-synthetic-password',$org,$source,$pepper)===null,
    'fifth login attempt denied');
$ok($issuer->begin($email,$passphrase,$org,$source,$pepper)===null,
    'sixth attempt throttled even with correct password');
$ok($sessionCount()===1,'throttling creates no additional pending session');

$ok($issuer->begin($email,$passphrase,$org,'192.0.2.51',$pepper)!==null,
    'distinct trusted test-source scope has separate budget');
$ok($sessionCount()===2,'one additional pending session only in new scope');
$ok(!$check($bearer,$org,'members.manage'),'no escalation to membership administration');

putenv('APP_ENV=production');
$ok($issuer->begin($email,$passphrase,$org,$source,$pepper)===null,
    'no session creation outside laboratory');
putenv('APP_ENV=testing');
$ok(!PortalSyntheticPasswordSessionIssuer::mayIssueCustomerSession(),
    'laboratory class does not authorize live customer sessions');
sodium_memzero($key);
sodium_memzero($pepper);
echo 'PASS PORTAL-F82 '.$count." password→throttle→pending MFA→grant PG assertions; customer login CLOSED.\n";
