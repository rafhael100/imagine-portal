<?php

declare(strict_types=1);

require_once __DIR__.'/PortalF100OfflinePermissionProbe.php';

// Disposable CI-only fixture: never call against a customer's PostgreSQL,
// real accounts or a provider. No bearer/digest/connection strings in output.
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F100_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F100 is limited to disposable local PG18 CI.');
}
$adminPassword = getenv('F66_PG_ADMIN_PASSWORD');
$readerPassword = getenv('F76_PG_READER_PASSWORD');
if (!is_string($adminPassword) || strlen($adminPassword) < 12
    || !is_string($readerPassword) || strlen($readerPassword) < 12) {
    throw new RuntimeException('F100 requires disposable CI-only credentials.');
}

$pdo = static function (string $role, string $password): PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
        $role,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
};
$admin = $pdo('postgres', $adminPassword);
$reader = $pdo('portal_f76_reader', $readerPassword);
$probe = new PortalF100OfflinePermissionProbe($reader);
$privilegedProbe = new PortalF100OfflinePermissionProbe($admin);
$checks = 0;
$ok = static function (bool $yes, string $label) use (&$checks): void {
    if (!$yes) {
        throw new RuntimeException('F100 assertion failed: '.$label);
    }
    ++$checks;
};

$identity = 'a1111111-1111-4111-8111-111111111111';
$organization = 'a2222222-2222-4222-8222-222222222222';
$foreign = 'a3333333-3333-4333-8333-333333333333';
$membership = 'a4444444-4444-4444-8444-444444444444';
$grant = 'a5555555-5555-4555-8555-555555555555';
$session = 'a6666666-6666-4666-8666-666666666666';
$bearer = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$ok(strlen($bearer) === 43, 'synthetic bearer format');
$ok(!PortalF100OfflinePermissionProbe::canAuthorizeHttp(), 'no HTTP authority');

$ok(!$probe->check($bearer, $organization, 'services.view'), 'no session yet');
$admin->prepare(
    "INSERT INTO portal_identities
     (id,email_normalized,status,email_verified_at,mfa_confirmed_at,security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',1,now(),now())"
)->execute([$identity, 'f100-synthetic@example.invalid']);
$insertOrganization = $admin->prepare(
    "INSERT INTO portal_organizations
     (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$insertOrganization->execute([$organization, 'f100-org', 'Synthetic F100 tenant A']);
$insertOrganization->execute([$foreign, 'f100-other', 'Synthetic F100 tenant B']);
$admin->prepare(
    "INSERT INTO portal_memberships
     (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$membership, $identity, $organization]);
$admin->prepare(
    "INSERT INTO portal_membership_grants
     (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute([$grant, $membership]);
$admin->prepare(
    "INSERT INTO portal_auth_sessions
     (id,session_sha256,identity_id,organization_id,status,
      identity_revision,membership_revision,organization_revision,
      mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '2 minutes',
       now()-interval '3 minutes',now()+interval '20 minutes',now(),now())"
)->execute([$session, hash('sha256', $bearer), $identity, $organization]);

$ok($probe->check($bearer, $organization, 'services.view'),
    'valid synthetic MFA session and explicit grant visible through EXECUTE-only PHP');
$ok(!$probe->check($bearer, $foreign, 'services.view'),
    'foreign tenant always denied');
$ok(!$probe->check($bearer, $organization, 'billing.view'),
    'missing explicit billing grant denied');
$ok(!$probe->check($bearer, $organization, 'services.manage'),
    'role is not a wildcard permission');
$ok(!$probe->check($bearer, $organization, '*'), 'wildcard denied');
$ok(!$probe->check($bearer, $organization, "services.view'; DROP TABLE portal_identities;--"),
    'SQL-shaped permission denied before query');
$ok(!$probe->check($bearer, "a2222222-2222-4222-8222-222222222222' OR TRUE --", 'services.view'),
    'SQL-shaped organization denied before query');
$ok(!$probe->check('CRM-session-cookie-not-Portal', $organization, 'services.view'),
    'CRM cookie rejected');
$ok(!$probe->check(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
    $organization, 'services.view'), 'unknown bearer denied');
$ok(!$probe->check(substr($bearer, 0, 42).'=', $organization, 'services.view'),
    'noncanonical padding rejected');
$ok(!$privilegedProbe->check($bearer, $organization, 'services.view'),
    'privileged PDO role rejected even for valid bearer');

try {
    $reader->query('SELECT * FROM portal_auth_sessions');
    $ok(false, 'no direct SELECT on identities/sessions');
} catch (PDOException $exception) {
    $ok($exception->getCode() === '42501', 'EXECUTE-only role has no SELECT privilege');
}

$admin->exec("UPDATE portal_memberships SET membership_revision=2 WHERE id='$membership'");
$ok(!$probe->check($bearer, $organization, 'services.view'), 'stale membership denied');
$admin->exec("UPDATE portal_memberships SET membership_revision=1 WHERE id='$membership'");
$ok($probe->check($bearer, $organization, 'services.view'), 'matching revision restored in fixture');
$admin->exec("UPDATE portal_organizations SET security_revision=2 WHERE id='$organization'");
$ok(!$probe->check($bearer, $organization, 'services.view'), 'org revision revokes immediately');
$admin->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$organization'");
$admin->exec("UPDATE portal_identities SET security_revision=2 WHERE id='$identity'");
$ok(!$probe->check($bearer, $organization, 'services.view'), 'identity revision revokes immediately');
$admin->exec("UPDATE portal_identities SET security_revision=1 WHERE id='$identity'");
$admin->exec("UPDATE portal_auth_sessions SET status='revoked',revoked_at=now() WHERE id='$session'");
$ok(!$probe->check($bearer, $organization, 'services.view'), 'revoked session denied');
$admin->exec("UPDATE portal_auth_sessions SET status='active',revoked_at=NULL,
    expires_at=now()-interval '1 minute' WHERE id='$session'");
$ok(!$probe->check($bearer, $organization, 'services.view'), 'expired session denied');
$admin->exec("UPDATE portal_auth_sessions SET expires_at=now()+interval '20 minutes',
    organization_revision=NULL WHERE id='$session'");
$ok(!$probe->check($bearer, $organization, 'services.view'), 'missing org revision denied');
$admin->exec("UPDATE portal_auth_sessions SET organization_revision=1 WHERE id='$session'");
$admin->exec("UPDATE portal_memberships SET status='suspended' WHERE id='$membership'");
$ok(!$probe->check($bearer, $organization, 'services.view'), 'suspended membership denied');
$admin->exec("UPDATE portal_memberships SET status='active' WHERE id='$membership'");
$admin->exec("DELETE FROM portal_membership_grants WHERE id='$grant'");
$ok(!$probe->check($bearer, $organization, 'services.view'), 'removed grant denied');
$ok((int)$admin->query(
    "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND tablename LIKE 'portal_%'"
)->fetchColumn() === 0, 'no RLS allow policies installed');

putenv('PORTAL_F100_PG_LAB=0');
$ok(!$probe->check($bearer, $organization, 'services.view'), 'disabled lab flag denied');
putenv('PORTAL_F100_PG_LAB=1');
putenv('APP_ENV=production');
$ok(!$probe->check($bearer, $organization, 'services.view'), 'production environment denied');

echo "PASS PORTAL-F100 {$checks} disposable PHP/PG18 least-privilege and revocation checks\n";
