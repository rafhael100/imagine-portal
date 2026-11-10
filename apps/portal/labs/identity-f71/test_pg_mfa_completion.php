<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Labs\IdentityF71\PortalSyntheticMfaSessionCompleter;

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F71_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F71 cannot run outside disposable PostgreSQL.');
}
$password = getenv('F66_PG_ADMIN_PASSWORD');
if (!is_string($password) || strlen($password) < 12) {
    throw new RuntimeException('Missing disposable CI password.');
}
$connect = static fn(): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    'postgres', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false],
);
$db = $connect();
$other = $connect();
$completer = new PortalSyntheticMfaSessionCompleter($db);
$otherCompleter = new PortalSyntheticMfaSessionCompleter($other);
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$identityId = 'a1111111-1111-4111-8111-111111111111';
$orgId = 'a2222222-2222-4222-8222-222222222222';
$membershipId = 'a3333333-3333-4333-8333-333333333333';
$now = 2000000000;
$n = 0;
$check = static function (bool $valid, string $label) use (&$n): void {
    if (!$valid) {
        throw new RuntimeException('F71 FAIL ' . $label);
    }
    ++$n;
};
$db->prepare(
    "INSERT INTO portal_identities
        (id,email_normalized,status,mfa_confirmed_at,security_revision,created_at,updated_at)
     VALUES(?,?,'active',to_timestamp(1999999900),1,now(),now())"
)->execute([$identityId, 'f71@example.invalid']);
$db->prepare(
    "INSERT INTO portal_organizations
        (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,'f71-org','F71 synthetic tenant','active',1,now(),now())"
)->execute([$orgId]);
$db->prepare(
    "INSERT INTO portal_memberships
        (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$membershipId,$identityId,$orgId]);
$db->prepare(
    "INSERT INTO portal_membership_grants
        (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute(['a4444444-4444-4444-8444-444444444444', $membershipId]);

$bearer1 = 'F71_Synthetic_Bearer_Only_aaaaaaaaaa';
$bearer2 = 'F71_Synthetic_Bearer_Only_bbbbbbbbbb';
$bearer3 = 'F71_Synthetic_Bearer_Only_cccccccccc';
$createPending = static function (string $sessionId, string $bearer) use ($db, $identityId, $orgId): void {
    $db->prepare(
        "INSERT INTO portal_auth_sessions
            (id,session_sha256,identity_id,organization_id,status,identity_revision,
             membership_revision,issued_at,expires_at,created_at,updated_at)
         VALUES(?,?,?,?, 'pending_mfa', 1,1, to_timestamp(1999999900),to_timestamp(2000000300),now(),now())"
    )->execute([$sessionId, hash('sha256',$bearer), $identityId, $orgId]);
};
$session1 = 'a5555555-5555-4555-8555-555555555555';
$session2 = 'a6666666-6666-4666-8666-666666666666';
$session3 = 'a7777777-7777-4777-8777-777777777777';
$createPending($session1,$bearer1);
$createPending($session2,$bearer2);
$createPending($session3,$bearer3);

$getCounter = static fn(): int => (int)$db->query(
    "SELECT mfa_last_used_counter FROM portal_identities WHERE id='$identityId'"
)->fetchColumn();
$getState = static function (string $id) use ($db): array {
    $stmt = $db->prepare('SELECT status,mfa_verified_at FROM portal_auth_sessions WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
};

$check(!$completer->complete($bearer1,$secret,'000000',$now),'wrong code refused');
$check($getCounter() === -1,'wrong code did not claim counter');
$check($getState($session1)['status'] === 'pending_mfa','wrong code did not promote pending session');
$check(!$completer->complete('wrong-bearer-value-not-present-in-db',$secret,'279037',$now),'unknown bearer refused');
$check(!$completer->complete($bearer1,'WRONGBASE32SECRETNOTMATCHING000000','279037',$now),'wrong test secret refused');
$encryptionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$encryptedTotp = \App\Domain\Identity\Security\PortalTotpSecretCipher::seal($secret, $encryptionKey);
$check(is_string($encryptedTotp), 'TOTP secret encrypted with ephemeral key');
$db->prepare('UPDATE portal_identities SET mfa_secret_ciphertext=? WHERE id=?')
    ->execute([$encryptedTotp, $identityId]);
$check(!$completer->complete($bearer1, $secret, '279037', $now, 'mfa-sb1:stale'),
    'stale secret envelope denied before OTP claim');
$check($getCounter() === -1, 'stale secret does not advance counter');
$check($completer->complete($bearer1,$secret,'279037',$now,$encryptedTotp),
    'valid TOTP and matching sealed secret atomically promote pending session');
sodium_memzero($encryptionKey);
$check($getState($session1)['status'] === 'active','session marked active after CAS');
$check($getState($session1)['mfa_verified_at'] !== null,'MFA timestamp written in same transaction');
$check($getCounter() === 66666666,'persisted RFC 6238 counter');
$check(!$completer->complete($bearer1,$secret,'279037',$now),'replay of active session refused');
$check(!$otherCompleter->complete($bearer2,$secret,'279037',$now),'second session cannot reuse counter');
$check($getState($session2)['status'] === 'pending_mfa','second session remains pending after replay');
$check($getCounter() === 66666666,'replay cannot advance counter');

$check($otherCompleter->complete($bearer2,$secret,'637009',$now+30),'new counter promotes second session');
$check($getCounter() === 66666667,'newer counter persisted');
$check(!$completer->complete($bearer3,$secret,'637009',$now+30),'third session cannot reuse second code');

$db->exec("UPDATE portal_memberships SET status='revoked' WHERE id='$membershipId'");
$check(!$completer->complete($bearer3,$secret,'353674',$now+60),'revoked membership denies session MFA');
$check($getState($session3)['status'] === 'pending_mfa','membership denial does not partially activate session');
$db->exec("UPDATE portal_memberships SET status='active',membership_revision=2 WHERE id='$membershipId'");
$check(!$completer->complete($bearer3,$secret,'353674',$now+60),'stale membership revision denies session MFA');
$db->exec("UPDATE portal_memberships SET membership_revision=1 WHERE id='$membershipId'");
$db->exec("UPDATE portal_identities SET security_revision=2 WHERE id='$identityId'");
$check(!$completer->complete($bearer3,$secret,'353674',$now+60),'stale security revision denies session MFA');
$db->exec("UPDATE portal_identities SET security_revision=1,mfa_confirmed_at=NULL WHERE id='$identityId'");
$check(!$completer->complete($bearer3,$secret,'353674',$now+60),'removed enrolled MFA evidence denies session');
$db->exec("UPDATE portal_identities SET mfa_confirmed_at=to_timestamp(1999999900),status='revoked' WHERE id='$identityId'");
$check(!$completer->complete($bearer3,$secret,'353674',$now+60),'revoked first-party identity denied');
$db->exec("UPDATE portal_identities SET status='active' WHERE id='$identityId'");

$check(!$completer->complete($bearer3,$secret,'353674',1999999899),'session not valid before issue');
$check(!$completer->complete($bearer3,$secret,'353674',2000000300),'session expired at upper boundary');

$db->exec("SET lock_timeout TO '100ms'");
$lock = $connect();
$lock->beginTransaction();
$locked = $lock->query("SELECT mfa_last_used_counter FROM portal_identities WHERE id='$identityId' FOR UPDATE")->fetchColumn();
$check((int)$locked === 66666667, 'locked row stays unchanged');
try {
    $check(!$completer->complete($bearer3,$secret,'353674',$now+60),
        'lock conflict denies all session promotion');
    $check($getState($session3)['status'] === 'pending_mfa',
        'failed transaction does not activate pending session');
} finally {
    $lock->rollBack();
}
$check($completer->complete($bearer3,$secret,'353674',$now+60),
    'valid code works after lock released');
$check($getCounter() === 66666668,'new counter stored with active session');
$check(!PortalSyntheticMfaSessionCompleter::mayIssueCustomerSession(),
    'a lab state transition is not a real customer session');

putenv('APP_ENV=production');
$check(!$completer->complete($bearer3,$secret,'094178',$now+90),'production always denied');
putenv('APP_ENV=testing');
putenv('PORTAL_F71_PG_LAB=0');
$check(!$completer->complete($bearer3,$secret,'094178',$now+90),'missing lab flag denies');
putenv('PORTAL_F71_PG_LAB=1');

$check((int)$db->query(
    "SELECT count(*) FROM pg_policies WHERE tablename='portal_auth_sessions'"
)->fetchColumn() === 0, 'no application RLS policies introduced');
echo 'PASS F71 ' . $n . " atomic MFA counter/session lab assertions, no login or customer authority.\n";
