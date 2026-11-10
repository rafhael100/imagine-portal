<?php

declare(strict_types=1);

// F66: disposable PostgreSQL-only schema regression; not an identity issuer.
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F66_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F66 refuses non-test PostgreSQL.');
}

$adminPassword = getenv('F66_PG_ADMIN_PASSWORD');
$readerPassword = getenv('F66_PG_READER_PASSWORD');
if (!is_string($adminPassword) || !is_string($readerPassword)
    || strlen($adminPassword) < 12 || strlen($readerPassword) < 12) {
    throw new RuntimeException('F66 missing disposable runner credentials.');
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
$admin = $db('postgres', $adminPassword);
$reader = $db('portal_f66_reader', $readerPassword);
$count = 0;
$ok = static function (bool $success, string $label) use (&$count): void {
    if (!$success) {
        throw new RuntimeException('F66 FAIL: ' . $label);
    }
    ++$count;
};
$deny = static function (callable $action, string $label) use ($ok): void {
    try {
        $action();
        $ok(false, $label . ' unexpectedly succeeded');
    } catch (PDOException) {
        $ok(true, $label);
    }
};

$tables = [
    'portal_identities', 'portal_organizations', 'portal_memberships',
    'portal_membership_grants', 'portal_auth_sessions',
];
foreach ($tables as $table) {
    $stmt = $admin->query("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = '" . $table . "'::regclass");
    $flags = $stmt->fetch();
    $ok(in_array($flags['relrowsecurity'], [true, 't', '1'], true)
        && in_array($flags['relforcerowsecurity'], [true, 't', '1'], true),
        $table . ' has FORCE RLS');
    $stmt = $admin->prepare('SELECT count(*) FROM pg_policies WHERE schemaname=? AND tablename=?');
    $stmt->execute(['public', $table]);
    $ok((int)$stmt->fetchColumn() === 0, $table . ' has NO implicit allow policy');
}

$identity = '11111111-1111-4111-8111-111111111111';
$orgA = '22222222-2222-4222-8222-222222222222';
$orgB = '33333333-3333-4333-8333-333333333333';
$member = '44444444-4444-4444-8444-444444444444';
$grant = '55555555-5555-4555-8555-555555555555';
$session = '66666666-6666-4666-8666-666666666666';
$hash = hash('sha256', 'F66-ONLY-SYNTHETIC-OPAQUE-BEARER-DO-NOT-USE');
$password = password_hash('F66 synthetic password not an account', PASSWORD_ARGON2ID);

$stmt = $admin->prepare(
    'INSERT INTO portal_identities(id,email_normalized,password_hash,status,security_revision,created_at,updated_at)
     VALUES(?, ?, ?, ?, 1, now(), now())'
);
$stmt->execute([$identity,'f66@example.invalid',$password,'active']);
$stmt = $admin->prepare(
    'INSERT INTO portal_organizations(id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?, ?, ?, ?, 1, now(), now())'
);
$stmt->execute([$orgA, 'f66-tenant-a', 'Test Tenant A', 'active']);
$stmt->execute([$orgB, 'f66-tenant-b', 'Test Tenant B', 'active']);
$stmt = $admin->prepare(
    'INSERT INTO portal_memberships(id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?, ?, ?, ?, ?, 1, now(), now())'
);
$stmt->execute([$member,$identity,$orgA,'owner','active']);
$stmt = $admin->prepare(
    'INSERT INTO portal_membership_grants(id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,?,now(),now())'
);
$stmt->execute([$grant,$member,'services.view']);
$stmt = $admin->prepare(
    'INSERT INTO portal_auth_sessions(id,session_sha256,identity_id,organization_id,status,identity_revision,
      membership_revision,mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?,?,1,1,now(),now()-interval \'1 minute\',now()+interval \'1 hour\',now(),now())'
);
$stmt->execute([$session,$hash,$identity,$orgA,'active']);
$ok((int)$admin->query('SELECT count(*) FROM portal_identities')->fetchColumn() === 1, 'admin fixture identity inserted');
$ok((int)$admin->query('SELECT count(*) FROM portal_auth_sessions')->fetchColumn() === 1, 'admin fixture hashed session inserted');

// Even if SELECT/INSERT/UPDATE/DELETE are accidentally granted, absence of
// RLS policies is deny-by-default for any non-bypass role.
foreach ($tables as $table) {
    $ok((int)$reader->query("SELECT count(*) FROM $table")->fetchColumn() === 0,
        "ordinary role denied all rows in $table");
}
$ok($reader->exec("UPDATE portal_memberships SET status='active'") === 0,
    'ordinary role cannot update tenant membership even with grant');
$deny(static fn() => $reader->exec(
    "INSERT INTO portal_organizations(id,reference,display_name,status,created_at,updated_at)
     VALUES('77777777-7777-4777-8777-777777777777','f66-inject','Injected','active',now(),now())"
), 'ordinary role cannot create organization');

// SQL constraints cannot be bypassed even by fixture admin.
$deny(static fn() => $admin->exec(
    "INSERT INTO portal_identities(id,email_normalized,status,created_at,updated_at)
     VALUES('88888888-8888-4888-8888-888888888888','Upper@Example.INVALID','active',now(),now())"
), 'reject non-normalized email');
$deny(static fn() => $admin->exec(
    "INSERT INTO portal_memberships(id,identity_id,organization_id,role,status,created_at,updated_at)
     VALUES('88888888-8888-4888-8888-888888888888','$identity','$orgA','superadmin','active',now(),now())"
), 'reject unapproved role');
$deny(static fn() => $admin->exec(
    "INSERT INTO portal_membership_grants(id,membership_id,permission_code,created_at,updated_at)
     VALUES('88888888-8888-4888-8888-888888888888','$member','*',now(),now())"
), 'reject wildcard permission');
$deny(static fn() => $admin->exec(
    "INSERT INTO portal_auth_sessions(id,session_sha256,identity_id,organization_id,status,identity_revision,
     membership_revision,mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES('88888888-8888-4888-8888-888888888888','" . str_repeat('a', 64) . "','$identity','$orgB',
     'active',1,1,now(),now(),now()+interval '1 hour',now(),now())"
), 'reject session without membership in other tenant');
$deny(static fn() => $admin->exec(
    "INSERT INTO portal_auth_sessions(id,session_sha256,identity_id,organization_id,status,identity_revision,
     membership_revision,mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES('88888888-8888-4888-8888-888888888888','" . str_repeat('b', 64) . "','$identity','$orgA',
     'active',1,1,NULL,now(),now()+interval '1 hour',now(),now())"
), 'reject fully active session without MFA evidence');
$deny(static fn() => $admin->exec(
    "UPDATE portal_identities SET security_revision=0 WHERE id='$identity'"
), 'reject invalid identity security revision');

$ok((int)$admin->query('SELECT count(*) FROM portal_auth_sessions')->fetchColumn() === 1,
    'all invalid sessions rolled back without residue');
$ok((int)$admin->query('SELECT count(*) FROM portal_membership_grants')->fetchColumn() === 1,
    'invalid grant did not persist');

echo 'PASS F66 ' . $count . " PostgreSQL tenant/identity schema checks; app permissions remain CLOSED.\n";
