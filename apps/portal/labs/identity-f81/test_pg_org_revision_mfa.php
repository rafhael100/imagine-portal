<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Labs\IdentityF81\PortalOrgRevisionMfaCompleter;

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F81_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F81 refuses non-test database or runtime.');
}

$password = getenv('F66_PG_ADMIN_PASSWORD');
$readerPassword = getenv('F66_PG_READER_PASSWORD');
if (!is_string($password) || strlen($password) < 12
    || !is_string($readerPassword) || strlen($readerPassword) < 12) {
    throw new RuntimeException('F81 missing disposable PostgreSQL role credentials.');
}
$connect = static fn(string $role, string $rolePassword): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,
    $rolePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false],
);
$db = $connect('postgres', $password);
$db2 = $connect('postgres', $password);
$reader = $connect('portal_f66_reader', $readerPassword);
$service = new PortalOrgRevisionMfaCompleter($db);
$service2 = new PortalOrgRevisionMfaCompleter($db2);
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // public RFC test vector
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$wrongKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$sealed = PortalTotpSecretCipher::seal($secret, $key);
if (!is_string($sealed)) {
    throw new RuntimeException('Cannot build synthetic encrypted enrollment.');
}

$identityId = 'b1111111-1111-4111-8111-111111111111';
$orgId = 'b2222222-2222-4222-8222-222222222222';
$memberId = 'b3333333-3333-4333-8333-333333333333';
$sessionA = 'b4444444-4444-4444-8444-444444444444';
$sessionB = 'b5555555-5555-4555-8555-555555555555';
$sessionC = 'b6666666-6666-4666-8666-666666666666';
$bearerA = 'F75_Synthetic_Bearer_A_abcdefghijk';
$bearerB = 'F75_Synthetic_Bearer_B_abcdefghijk';
$bearerC = 'F75_Synthetic_Bearer_C_abcdefghijk';
$now = 2000000000;
$count = 0;
$ok = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException('F81 FAIL ' . $message);
    }
    ++$count;
};

$db->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,status,mfa_secret_ciphertext,mfa_confirmed_at,email_verified_at,security_revision,created_at,updated_at)
     VALUES(?,?,'active',?,to_timestamp(1999999900),to_timestamp(1999999900),1,now(),now())"
)->execute([$identityId, 'f75@example.invalid', $sealed]);
$db->prepare(
    "INSERT INTO portal_organizations
      (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,'f75-tenant','F75 synthetic tenant','active',1,now(),now())"
)->execute([$orgId]);
$db->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$memberId, $identityId, $orgId]);

$pending = static function (string $id, string $bearer) use ($db, $identityId, $orgId): void {
    $db->prepare(
        "INSERT INTO portal_auth_sessions
          (id,session_sha256,identity_id,organization_id,status,
           identity_revision,membership_revision,organization_revision,issued_at,expires_at,created_at,updated_at)
         VALUES(?,?,?,?, 'pending_mfa',1,1,1,to_timestamp(1999999900),to_timestamp(2000000300),now(),now())"
    )->execute([$id, hash('sha256', $bearer), $identityId, $orgId]);
};
$db->prepare(
    "INSERT INTO portal_membership_grants(id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute(['b7777777-7777-4777-8777-777777777777',$memberId]);
$pending($sessionA, $bearerA);
$pending($sessionB, $bearerB);
$pending($sessionC, $bearerC);

$getCounter = static fn(): int => (int) $db->query(
    "SELECT mfa_last_used_counter FROM portal_identities WHERE id='$identityId'"
)->fetchColumn();
$getStatus = static function (string $id) use ($db): string {
    $stmt = $db->prepare('SELECT status FROM portal_auth_sessions WHERE id=?');
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
};

$ok(str_starts_with($sealed, 'mfa-sb1:') && !str_contains($sealed, $secret),
    'database enrollment stores only ciphertext');
$ok(PortalTotpSecretCipher::open($sealed, $key) === $secret,
    'ciphertext decrypts for correct independent server key');
$ok(!$service->complete($bearerA, $wrongKey, '279037', $now), 'incorrect decryption key denies');
$ok(!$service->complete($bearerA, 'short', '279037', $now), 'malformed key denies');
$ok($getCounter() === -1 && $getStatus($sessionA) === 'pending_mfa',
    'failed decrypt keeps both session and counter untouched');

$tampered = substr_replace($sealed, $sealed[20] === 'A' ? 'B' : 'A', 20, 1);
$db->prepare('UPDATE portal_identities SET mfa_secret_ciphertext=? WHERE id=?')
    ->execute([$tampered, $identityId]);
$ok(!$service->complete($bearerA, $key, '279037', $now),
    'tampered encrypted enrollment denied under row lock');
$ok($getCounter() === -1, 'tampered enrollment never claims counter');

$db->prepare('UPDATE portal_identities SET mfa_secret_ciphertext=? WHERE id=?')
    ->execute([$sealed, $identityId]);
$otherSealed = PortalTotpSecretCipher::seal('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', $key);
$ok(is_string($otherSealed), 'second synthetic enrollment can be encrypted');
$db->prepare('UPDATE portal_identities SET mfa_secret_ciphertext=? WHERE id=?')
    ->execute([$otherSealed, $identityId]);
$ok(!$service->complete($bearerA, $key, '279037', $now),
    'OTP for old identity enrollment denied when stored secret rotated');
$db->prepare('UPDATE portal_identities SET mfa_secret_ciphertext=? WHERE id=?')
    ->execute([$sealed, $identityId]);


$db->exec("UPDATE portal_organizations SET security_revision=2 WHERE id='$orgId'");
$ok(!$service->complete($bearerA, $key, '279037', $now),
    'pending MFA refused after org security revision is changed');
$ok($getCounter() === -1, 'org revision mismatch does not consume OTP counter');
$db->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$orgId'");

$db->exec("UPDATE portal_auth_sessions SET organization_revision=NULL WHERE id='$sessionA'");
$ok(!$service->complete($bearerA, $key, '279037', $now),
    'legacy pending MFA with null org revision refused');
$ok($getStatus($sessionA) === 'pending_mfa', 'no partial promotion for legacy session');
$db->exec("UPDATE portal_auth_sessions SET organization_revision=1 WHERE id='$sessionA'");

$db->exec("UPDATE portal_identities SET email_verified_at=NULL WHERE id='$identityId'");
$ok(!$service->complete($bearerA, $key, '279037', $now),
    'pending MFA refused when Portal email is not verified');
$db->exec("UPDATE portal_identities SET email_verified_at=to_timestamp(1999999900)
    WHERE id='$identityId'");

$ok(!$service->complete($bearerA, $key, '000000', $now), 'wrong OTP denies');
$ok(!$service->complete('F75_Not_An_Issued_Bearer_abcdefghijk', $key, '279037', $now),
    'unknown bearer denies even when code valid');
$ok($service->complete($bearerA, $key, '279037', $now),
    'enrolled, decrypted TOTP activates first pending session atomically');

$permissionReader = $connect('portal_f76_reader', getenv('F76_PG_READER_PASSWORD'));
$checkPermission = static function (PDO $pdo, string $digest, string $tenantId, string $permission): bool {
    $q = $pdo->prepare(
        'SELECT public.portal_f76_permission_check(:digest,CAST(:tenant AS uuid),:permission)'
    );
    $q->execute(['digest'=>$digest,'tenant'=>$tenantId,'permission'=>$permission]);
    return in_array($q->fetchColumn(),[true,1,'1','t'],true);
};
$ok(!$checkPermission($permissionReader,hash('sha256',$bearerB),$orgId,'services.view'),
    'still-pending second session not authorized despite explicit grant');

// Move ONLY the synthetic successfully completed session into the current
// server time window to check the F76 database gate with server clock.
$db->exec("UPDATE portal_auth_sessions SET
    issued_at=now()-interval '5 minutes',
    expires_at=now()+interval '30 minutes',
    mfa_verified_at=now()-interval '1 minute'
    WHERE id='$sessionA'");
$ok($checkPermission($permissionReader,hash('sha256',$bearerA),$orgId,'services.view'),
    'F81 activated session is visible to restricted F76 authorization gate');
$db->exec("UPDATE portal_organizations SET security_revision=2 WHERE id='$orgId'");
$ok(!$checkPermission($permissionReader,hash('sha256',$bearerA),$orgId,'services.view'),
    'F76 gate instantly denies org revision change after MFA completion');
$db->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$orgId'");
$ok($checkPermission($permissionReader,hash('sha256',$bearerA),$orgId,'services.view'),
    'F76 gate reconfirms unchanged organization revision for DEMO session');
$db->exec("DELETE FROM portal_membership_grants WHERE membership_id='$memberId'");
$ok(!$checkPermission($permissionReader,hash('sha256',$bearerA),$orgId,'services.view'),
    'revoking the explicit service grant disables DEMO session permission');
$db->prepare(
    "INSERT INTO portal_membership_grants(id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute(['b8888888-8888-4888-8888-888888888888',$memberId]);

$ok($getStatus($sessionA) === 'active' && $getCounter() === 66666666,
    'session activation and monotonic counter committed together');
$ok(!$service->complete($bearerA, $key, '279037', $now),
    'active session cannot be replayed');
$ok(!$service2->complete($bearerB, $key, '279037', $now),
    'same code cannot activate second session via separate PG connection');
$ok($getStatus($sessionB) === 'pending_mfa', 'replay did not promote second session');
$ok($service2->complete($bearerB, $key, '637009', $now + 30),
    'fresh counter permits second session');
$ok($getCounter() === 66666667, 'second counter advanced once');

$db->exec("UPDATE portal_memberships SET status='revoked' WHERE id='$memberId'");
$ok(!$service->complete($bearerC, $key, '353674', $now + 60),
    'revoked organization membership prevents MFA completion');
$db->exec("UPDATE portal_memberships SET status='active', membership_revision=2 WHERE id='$memberId'");
$ok(!$service->complete($bearerC, $key, '353674', $now + 60),
    'changed membership revision invalidates pending session');
$db->exec("UPDATE portal_memberships SET membership_revision=1 WHERE id='$memberId'");
$db->exec("UPDATE portal_identities SET security_revision=2 WHERE id='$identityId'");
$ok(!$service->complete($bearerC, $key, '353674', $now + 60),
    'changed identity security revision invalidates pending session');
$db->exec("UPDATE portal_identities SET security_revision=1, mfa_confirmed_at=NULL WHERE id='$identityId'");
$ok(!$service->complete($bearerC, $key, '353674', $now + 60),
    'unconfirmed MFA enrollment cannot authenticate');
$db->exec("UPDATE portal_identities SET mfa_confirmed_at=to_timestamp(1999999900) WHERE id='$identityId'");

$ok($reader->query('SELECT count(*) FROM portal_identities')->fetchColumn() == 0,
    'FORCE RLS keeps sealed secrets invisible to ordinary application role');
$ok($reader->query('SELECT count(*) FROM portal_auth_sessions')->fetchColumn() == 0,
    'FORCE RLS keeps session hashes invisible');
$ok($service->complete($bearerC, $key, '353674', $now + 60),
    'valid pending third session may complete only after restored verified revisions');
$ok($getCounter() === 66666668, 'last counter persists');
$ok(!PortalOrgRevisionMfaCompleter::mayIssueCustomerSession(),
    'passing synthetic MFA is never a customer login');

putenv('APP_ENV=production');
$ok(!$service->complete($bearerC, $key, '094178', $now + 90),
    'production invocation always forbidden');
putenv('APP_ENV=testing');
putenv('PORTAL_F75_PG_LAB=0');
$ok(!$service->complete($bearerC, $key, '094178', $now + 90),
    'missing lab flag denies');
putenv('PORTAL_F75_PG_LAB=1');

sodium_memzero($key);
sodium_memzero($wrongKey);

echo 'PASS F81 ' . $count . " enrolled TOTP PostgreSQL isolation, decryption and revocation checks.\n";
