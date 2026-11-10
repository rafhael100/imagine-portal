<?php

declare(strict_types=1);

// F76 only: ephemeral PG18 security-definer predicate with a non-superuser
// caller and *no* direct table rights. Never expose the hash as public RPC.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F76_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F76 test must run on disposable local PG18 only.');
}
$adminPassword = getenv('F66_PG_ADMIN_PASSWORD');
$readerPassword = getenv('F76_PG_READER_PASSWORD');
$unprivPassword = getenv('F76_PG_UNPRIV_PASSWORD');
if (!is_string($adminPassword) || !is_string($readerPassword) || !is_string($unprivPassword)
    || strlen($adminPassword) < 12 || strlen($readerPassword) < 12
    || strlen($unprivPassword) < 12) {
    throw new RuntimeException('Missing disposable runner database role credentials.');
}
$connect = static fn(string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$admin = $connect('postgres', $adminPassword);
$reader = $connect('portal_f76_reader', $readerPassword);
$unpriv = $connect('portal_f76_unprivileged', $unprivPassword);

$checks = 0;
$ok = static function (bool $state, string $label) use (&$checks): void {
    if (!$state) {
        throw new RuntimeException('F76 FAIL: ' . $label);
    }
    ++$checks;
};
$deny = static function (callable $fn, string $label) use ($ok): void {
    try {
        $fn();
    } catch (PDOException $e) {
        $ok($e->getCode() === '42501', $label . ' (permission denied)');
        return;
    }
    $ok(false, $label . ' (unexpectedly permitted)');
};

$identity = 'd1111111-1111-4111-8111-111111111111';
$org = 'd2222222-2222-4222-8222-222222222222';
$otherOrg = 'd3333333-3333-4333-8333-333333333333';
$membership = 'd4444444-4444-4444-8444-444444444444';
$grant = 'd5555555-5555-4555-8555-555555555555';
$session = 'd6666666-6666-4666-8666-666666666666';
$opaque = 'F76-SYNTHETIC-NEVER-ISSUED-USER-TOKEN-abcdef';
$bearerHash = hash('sha256', $opaque);

$admin->prepare(
    "INSERT INTO portal_identities
       (id,email_normalized,status,email_verified_at,mfa_confirmed_at,security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '2 days',now()-interval '2 days',1,now(),now())"
)->execute([$identity, 'f76@example.invalid']);
$insertOrg = $admin->prepare(
    "INSERT INTO portal_organizations
       (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$insertOrg->execute([$org,'f76-tenant-a','F76 synthetic tenant A']);
$insertOrg->execute([$otherOrg,'f76-tenant-b','F76 synthetic tenant B']);
$admin->prepare(
    "INSERT INTO portal_memberships
       (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$membership,$identity,$org]);
$admin->prepare(
    "INSERT INTO portal_membership_grants
       (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute([$grant,$membership]);
$admin->prepare(
    "INSERT INTO portal_auth_sessions
       (id,session_sha256,identity_id,organization_id,status,identity_revision,
        membership_revision,organization_revision,mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '1 minute',
            now()-interval '10 minutes',now()+interval '30 minutes',now(),now())"
)->execute([$session,$bearerHash,$identity,$org]);

$check = static function (PDO $db, string $hash, string $orgUuid, string $permission): bool {
    $stmt = $db->prepare(
        'SELECT public.portal_f76_permission_check(:token, CAST(:org AS uuid), :permission)'
    );
    $stmt->execute(['token'=>$hash, 'org'=>$orgUuid, 'permission'=>$permission]);
    return in_array($stmt->fetchColumn(), [true, 1, '1', 't'], true);
};

$ok($check($reader,$bearerHash,$org,'services.view'),
    'non-superuser can verify valid, MFA-confirmed, scoped and explicitly granted session');

$deny(static fn() => $reader->query('SELECT * FROM portal_auth_sessions'),
    'EXECUTE-only role cannot read session rows');
$deny(static fn() => $reader->query('SELECT * FROM portal_identities'),
    'EXECUTE-only role cannot read identity or encrypted TOTP');
$deny(static fn() => $reader->query('SELECT * FROM portal_membership_grants'),
    'EXECUTE-only role cannot list grant rows');
$deny(static fn() => $unpriv->query(
    "SELECT public.portal_f76_permission_check('" . str_repeat('0',64) . "', '$org'::uuid, 'services.view')"
), 'no PUBLIC EXECUTE privilege on security-definer function');

$ok(!$check($reader,hash('sha256','never-issued'),$org,'services.view'),
    'unknown bearer hash denied');
$ok(!$check($reader,strtoupper($bearerHash),$org,'services.view'),
    'noncanonical session digest denied');
$ok(!$check($reader,$bearerHash,$otherOrg,'services.view'),
    'cross-tenant organization denied');
$ok(!$check($reader,$bearerHash,$org,'billing.view'),
    'role is not a substitute for explicit billing permission');
$ok(!$check($reader,$bearerHash,$org,'*'),
    'wildcard permission denied');
$ok(!$check($reader,$bearerHash,$org,"services.view'; DROP TABLE portal_identities;--"),
    'SQL injection-shaped permission denied');

$admin->exec("UPDATE portal_memberships SET status='suspended' WHERE id='$membership'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'suspended membership immediately denied');
$admin->exec("UPDATE portal_memberships SET status='active',membership_revision=2 WHERE id='$membership'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'stale membership revision immediately denied');
$admin->exec("UPDATE portal_memberships SET membership_revision=1 WHERE id='$membership'");

$admin->exec("UPDATE portal_identities SET security_revision=2 WHERE id='$identity'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'stale Portal identity revision denied');
$admin->exec("UPDATE portal_identities SET security_revision=1,status='revoked' WHERE id='$identity'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'revoked first-party identity denied');
$admin->exec("UPDATE portal_identities SET status='active',email_verified_at=NULL WHERE id='$identity'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'unverified email denied');
$admin->exec("UPDATE portal_identities SET email_verified_at=now(),mfa_confirmed_at=NULL WHERE id='$identity'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'unenrolled MFA denied');
$admin->exec("UPDATE portal_identities SET mfa_confirmed_at=now() WHERE id='$identity'");

$admin->exec("UPDATE portal_organizations SET status='suspended' WHERE id='$org'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'suspended organization denied');
$admin->exec("UPDATE portal_organizations SET status='active',security_revision=2 WHERE id='$org'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'organization revision mismatch denied even with active membership');
$admin->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$org'");

$admin->exec("UPDATE portal_auth_sessions SET organization_revision=NULL WHERE id='$session'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'legacy session with NULL organization revision fails closed');
$admin->exec("UPDATE portal_auth_sessions SET organization_revision=1 WHERE id='$session'");
$admin->exec("UPDATE portal_auth_sessions SET expires_at=now()-interval '1 minute' WHERE id='$session'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'expired session denied based on server clock');
$admin->exec("UPDATE portal_auth_sessions SET expires_at=now()+interval '30 minutes',
    issued_at=now()+interval '5 minutes' WHERE id='$session'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'not-yet-issued session denied');
$admin->exec("UPDATE portal_auth_sessions SET issued_at=now()-interval '10 minutes',
    expires_at=now()+interval '2 days' WHERE id='$session'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'abnormally long session lifetime denied');
$admin->exec("UPDATE portal_auth_sessions SET expires_at=now()+interval '30 minutes',
    status='revoked',revoked_at=now() WHERE id='$session'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'revoked session denied');
$admin->exec("UPDATE portal_auth_sessions SET status='active',revoked_at=NULL WHERE id='$session'");

$admin->exec("DELETE FROM portal_membership_grants WHERE id='$grant'");
$ok(!$check($reader,$bearerHash,$org,'services.view'),
    'removed membership permission denied');
$ok((int)$admin->query("SELECT count(*) FROM portal_identities WHERE id='$identity'")->fetchColumn()===1,
    'denial leaves fixture identity present');

$ok((int)$admin->query(
    "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND tablename LIKE 'portal_%'"
)->fetchColumn() === 0,
    'no RLS allow policies introduced');
$ok((int)$admin->query(
    "SELECT count(*) FROM information_schema.columns
     WHERE table_schema='public' AND table_name='portal_auth_sessions'
       AND column_name='organization_revision'"
)->fetchColumn() === 1,
    'organization security version persisted on session');
$ok((int)$admin->query(
    "SELECT count(*) FROM pg_proc
     WHERE oid='public.portal_f76_permission_check(text,uuid,text)'::regprocedure
       AND prosecdef = true
       AND proconfig::text LIKE '%search_path=pg_catalog, pg_temp%'"
)->fetchColumn() === 1,
    'security definer has fixed search_path');

echo 'PASS PORTAL-F76 ' . $checks .
    " least-privilege, tenant and revocation checks; NO customer authorization.\n";
