<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Domain\Identity\Security\PortalTotpVerifier;
use App\Labs\IdentityF70\PortalMfaReplayFence;

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F70_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F70 requires disposable PostgreSQL and CLI testing.');
}

$db = static fn(string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$adminPassword = getenv('F66_PG_ADMIN_PASSWORD');
$readerPassword = getenv('F66_PG_READER_PASSWORD');
if (!is_string($adminPassword) || !is_string($readerPassword)) {
    throw new RuntimeException('Missing ephemeral CI role credentials.');
}

$admin = $db('postgres', $adminPassword);
$second = $db('postgres', $adminPassword);
$reader = $db('portal_f66_reader', $readerPassword);
$fence = new PortalMfaReplayFence($admin);
$secondFence = new PortalMfaReplayFence($second);
$id = '11111111-1111-4111-8111-111111111111';
$counter = 0;
$ok = static function (bool $value, string $label) use (&$counter): void {
    if (!$value) {
        throw new RuntimeException('F70 FAIL ' . $label);
    }
    ++$counter;
};

$admin->exec("UPDATE portal_identities
    SET mfa_confirmed_at=now(), status='active'
    WHERE id='$id'");

$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$matched = PortalTotpVerifier::matchCounter($secret, '287082', 59);
$ok($matched === 1, 'RFC TOTP matched counter 1');
$ok($fence->claim($id, 1, $matched), 'first SQL CAS claims counter');
$ok(!$fence->claim($id, 1, $matched), 'same process replay denied');
$ok(!$secondFence->claim($id, 1, $matched), 'different PG connection replay denied');
$ok(!$fence->claim($id, 1, 0), 'older counter denied');
$ok($secondFence->claim($id, 1, 2), 'strictly increasing counter accepted');

$actual = (int)$admin->query(
    "SELECT mfa_last_used_counter FROM portal_identities WHERE id='$id'"
)->fetchColumn();
$ok($actual === 2, 'higher counter stored in real PG row');

$admin->exec("UPDATE portal_identities SET security_revision=2 WHERE id='$id'");
$ok(!$fence->claim($id, 1, 3), 'old security revision denied');
$ok($fence->claim($id, 2, 3), 'current security revision accepted');

$admin->exec("UPDATE portal_identities SET mfa_confirmed_at=NULL WHERE id='$id'");
$ok(!$fence->claim($id, 2, 4), 'lost MFA confirmation denied');
$admin->exec("UPDATE portal_identities SET mfa_confirmed_at=now(), status='revoked' WHERE id='$id'");
$ok(!$fence->claim($id, 2, 4), 'revoked identity denied');
$admin->exec("UPDATE portal_identities SET status='active' WHERE id='$id'");

// No policy: a non-superuser with an explicit UPDATE GRANT still changes 0 rows.
$ok($reader->exec("UPDATE portal_identities SET mfa_last_used_counter=999") === 0,
    'FORCE RLS refuses ordinary SQL writer even with UPDATE grant');

$other = $db('postgres', $adminPassword);
$other->exec("SET lock_timeout TO '100ms'");
$lock = $db('postgres', $adminPassword);
$lock->beginTransaction();
$locked = $lock->query(
    "SELECT mfa_last_used_counter FROM portal_identities WHERE id='$id' FOR UPDATE"
)->fetchColumn();
$ok((int)$locked === 3, 'lock holder sees latest monotonic counter');
try {
    $ok(!(new PortalMfaReplayFence($other))->claim($id, 2, 4),
        'lock timeout fails closed without accepting OTP');
} finally {
    $lock->rollBack();
}
$ok((new PortalMfaReplayFence($other))->claim($id, 2, 4),
    'counter can be retried after lock release');

$deniedConstraint = false;
try {
    $admin->exec("UPDATE portal_identities SET mfa_last_used_counter=-2 WHERE id='$id'");
} catch (PDOException) {
    $deniedConstraint = true;
}
$ok($deniedConstraint, 'SQL blocks counter below -1');

putenv('APP_ENV=production');
$ok(!$fence->claim($id, 2, 5), 'production environment is always denied');
putenv('APP_ENV=testing');
putenv('PORTAL_F70_PG_LAB=0');
$ok(!$fence->claim($id, 2, 5), 'missing laboratory flag blocks claim');
putenv('PORTAL_F70_PG_LAB=1');

$ok(!PortalMfaReplayFence::mayIssueSession(), 'SQL CAS never grants HTTP/session');
$ok(!$fence->claim('../foreign', 2, 5), 'malformed identity denied');
$ok(!$fence->claim($id, 2, -1), 'negative matched counter denied');
$ok(!$fence->claim($id, 0, 5), 'invalid security revision denied');
$ok((int)$admin->query(
    "SELECT count(*) FROM pg_policies WHERE tablename='portal_identities'"
)->fetchColumn() === 0, 'no RLS allow policy added');

echo 'PASS F70 ' . $counter . " PostgreSQL TOTP CAS and denial checks; login remains disabled.\n";
