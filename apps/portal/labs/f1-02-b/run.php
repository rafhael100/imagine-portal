<?php

declare(strict_types=1);

/**
 * F1-02-B: disposable PostgreSQL 18/PDO lab, NOT a Portal authenticator.
 * No Laravel bootstrap, HTTP routes, real user, VPS, CRM or production data.
 */
if (getenv('PORTAL_LAB_ALLOW') !== '1'
    || getenv('PORTAL_LAB_DSN') !== 'pgsql:host=127.0.0.1;port=5432;dbname=portal_f1_02_lab'
    || getenv('PORTAL_LAB_ADMIN_USER') !== 'lab_owner'
    || PHP_VERSION_ID < 80400
    || PHP_VERSION_ID >= 80500
    || ! extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "F1-02-B stopped: requires approved isolated PG18/PHP8.4 lab environment.\n");
    exit(2);
}

$dsn = (string) getenv('PORTAL_LAB_DSN');
$adminPassword = (string) getenv('PORTAL_LAB_ADMIN_PASSWORD');
$runtimePassword = (string) getenv('PORTAL_LAB_RUNTIME_PASSWORD');

if (strlen($adminPassword) < 12 || strlen($runtimePassword) < 12 || $adminPassword === $runtimePassword) {
    fwrite(STDERR, "F1-02-B stopped: missing distinct disposable lab credentials.\n");
    exit(2);
}

function connection(string $dsn, string $user, string $password): PDO
{
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function expectSqlState(callable $callback, string $state): void
{
    try {
        $callback();
    } catch (PDOException $error) {
        verify($error->getCode() === $state, "Expected SQLSTATE $state, got ".$error->getCode());
        return;
    }
    throw new RuntimeException("Expected SQLSTATE $state but SQL was accepted");
}

function validSyntheticSession(PDO $db, string $rawHandle): bool
{
    $statement = $db->prepare(
        "SELECT 1
         FROM portal_lab.session s
         JOIN portal_lab.account a ON a.actor_id = s.actor_id
         WHERE s.verifier_sha256 = :digest
           AND s.state = 'active'
           AND a.state = 'active'
           AND s.factor_verified
           AND s.issued_at <= clock_timestamp()
           AND s.expires_at > clock_timestamp()
           AND s.session_epoch = a.session_epoch
           AND s.identity_revision = a.identity_revision
           AND s.credential_revision = a.credential_revision
           AND s.mfa_revision = a.mfa_revision
         LIMIT 1"
    );
    $statement->execute(['digest' => hash('sha256', $rawHandle)]);
    return $statement->fetchColumn() !== false;
}

function consumeSyntheticChallenge(PDO $db, string $id, string $purpose): bool
{
    $statement = $db->prepare(
        "UPDATE portal_lab.challenge c
         SET consumed_at = clock_timestamp()
         WHERE c.challenge_id = :id
           AND c.purpose = :purpose
           AND c.consumed_at IS NULL
           AND c.expires_at > clock_timestamp()
           AND EXISTS (
             SELECT 1 FROM portal_lab.account a
             WHERE a.actor_id = c.actor_id
               AND a.state = 'active'
               AND a.session_epoch = c.account_epoch
           )
         RETURNING challenge_id"
    );
    $statement->execute(['id' => $id, 'purpose' => $purpose]);
    return $statement->fetchColumn() !== false;
}

$admin = connection($dsn, 'lab_owner', $adminPassword);
$version = (int) $admin->query('SHOW server_version_num')->fetchColumn();
$database = (string) $admin->query('SELECT current_database()')->fetchColumn();
verify($version >= 180000 && $version < 190000, 'Server is not PostgreSQL 18');
verify($database === 'portal_f1_02_lab', 'Unexpected PostgreSQL database');
verify((string) $admin->query('SELECT current_user')->fetchColumn() === 'lab_owner', 'Unexpected lab owner');

$admin->exec(
    "CREATE ROLE portal_lab_runtime WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD ".
    $admin->quote($runtimePassword)
);
$schema = file_get_contents(__DIR__.'/schema.sql');
verify(is_string($schema) && strlen($schema) > 0, 'Missing synthetic SQL');
$schema = preg_replace('/^\s*--.*$/m', '', $schema);
foreach (explode(';', (string) $schema) as $statement) {
    if (trim($statement) !== '') {
        $admin->exec($statement);
    }
}

$admin->exec("INSERT INTO portal_lab.account VALUES
    ('actor-a','active',1,1,1,1),
    ('actor-b','active',1,1,1,1)");
$admin->exec("INSERT INTO portal_lab.organization VALUES ('org-a'), ('org-b')");
$admin->exec("INSERT INTO portal_lab.membership VALUES
    ('org-a','actor-a','active'), ('org-b','actor-b','active')");
$admin->exec("INSERT INTO portal_lab.resource VALUES
    ('org-a','resource-a','SYNTHETIC_A_ONLY'),
    ('org-b','resource-b','SYNTHETIC_B_ONLY')");
$admin->exec("INSERT INTO portal_lab.challenge
    (challenge_id,actor_id,purpose,account_epoch,expires_at) VALUES
    ('challenge-a','actor-a','login',1,clock_timestamp() + interval '1 hour'),
    ('challenge-expired','actor-a','login',1,clock_timestamp() - interval '1 hour')");

$session = $admin->prepare(
    "INSERT INTO portal_lab.session
     (verifier_sha256, actor_id, state, factor_verified, session_epoch,
      identity_revision, credential_revision, mfa_revision, issued_at, expires_at)
     VALUES (:digest,:actor,'active',:factor,1,1,1,1,
             clock_timestamp() - interval '2 minutes',
             clock_timestamp() + interval '1 hour')"
);
$session->execute(['digest'=>hash('sha256','portal-lab-handle-a'),'actor'=>'actor-a','factor'=>true]);
$session->execute(['digest'=>hash('sha256','portal-lab-handle-no-mfa'),'actor'=>'actor-b','factor'=>false]);

$passed = 0;
function labCase(string $id, string $label, callable $test): void
{
    global $passed;
    $test();
    ++$passed;
    echo "[PASS $id] $label\n";
}

labCase('LAB-T01', 'CRM textual subject and raw handle cannot authenticate by ID coincidence', function () use ($admin): void {
    verify(validSyntheticSession($admin,'portal-lab-handle-a'), 'Expected synthetic valid verifier');
    verify(!validSyntheticSession($admin,'actor-a'), 'Subject string accepted as handle');
    verify(!validSyntheticSession($admin,'crm-cookie-same-actor-a'), 'CRM-style token accepted');
    $digest = (string) $admin->query("SELECT verifier_sha256 FROM portal_lab.session WHERE actor_id='actor-a'")->fetchColumn();
    verify($digest === hash('sha256','portal-lab-handle-a'), 'Token verifier not SHA-256 digest');
    verify($digest !== 'portal-lab-handle-a', 'Raw secret stored instead of verifier');
});

labCase('LAB-T02', 'Login challenge purpose, expiry and atomic single-use update', function () use ($admin): void {
    verify(!consumeSyntheticChallenge($admin, 'challenge-a', 'step_up'), 'Wrong purpose consumed');
    verify(consumeSyntheticChallenge($admin, 'challenge-a', 'login'), 'Valid challenge not consumed');
    verify(!consumeSyntheticChallenge($admin, 'challenge-a', 'login'), 'Replay consumed twice');
    verify(!consumeSyntheticChallenge($admin, 'challenge-expired', 'login'), 'Expired challenge accepted');
});

labCase('LAB-T03', 'Synthetic MFA missing, expiry and stale credential revision denied', function () use ($admin, $session): void {
    verify(!validSyntheticSession($admin,'portal-lab-handle-no-mfa'), 'Missing MFA accepted');
    $admin->exec("UPDATE portal_lab.session SET expires_at = clock_timestamp() - interval '1 second'
                  WHERE actor_id = 'actor-b'");
    verify(!validSyntheticSession($admin,'portal-lab-handle-no-mfa'), 'Expired session accepted');
    $admin->exec("UPDATE portal_lab.account SET credential_revision = 2 WHERE actor_id='actor-b'");
    verify(!validSyntheticSession($admin,'portal-lab-handle-no-mfa'), 'Stale credential revision accepted');
});

labCase('LAB-T05', 'Tenant-composite FK rejects cross-organization resource', function () use ($admin): void {
    expectSqlState(function () use ($admin): void {
        $admin->exec("INSERT INTO portal_lab.effect(effect_id,organization_id,resource_id,actor_id)
                      VALUES ('cross-tenant-fk','org-a','resource-b','actor-a')");
    }, '23503');
    $count = (int) $admin->query("SELECT count(*) FROM portal_lab.effect")->fetchColumn();
    verify($count === 0, 'Cross-tenant effect persisted after FK failure');
});

$runtime = connection($dsn,'portal_lab_runtime',$runtimePassword);
labCase('LAB-T06', 'Nonprivileged runtime and RLS deny without tenant context', function () use ($admin,$runtime): void {
    $flags = $admin->query(
        "SELECT (rolsuper::integer + rolbypassrls::integer + rolcreaterole::integer + rolcreatedb::integer) AS elevated
         FROM pg_roles WHERE rolname='portal_lab_runtime'"
    )->fetchColumn();
    verify((int) $flags === 0, 'Runtime has elevated DB powers');
    expectSqlState(fn () => $runtime->exec('SET ROLE lab_owner'), '42501');
    expectSqlState(fn () => $runtime->exec("UPDATE portal_lab.account SET session_epoch=7 WHERE actor_id='actor-a'"), '42501');
    verify((int) $runtime->query('SELECT count(*) FROM portal_lab.resource')->fetchColumn() === 0,
        'Runtime read resource without tenant context');
    $runtime->beginTransaction();
    $runtime->exec("SET LOCAL portal_lab.organization_id = 'org-a'");
    $payloads = $runtime->query('SELECT synthetic_payload FROM portal_lab.resource ORDER BY resource_id')->fetchAll(PDO::FETCH_COLUMN);
    verify($payloads === ['SYNTHETIC_A_ONLY'], 'Runtime context A exposed other tenant');
    $runtime->exec('SAVEPOINT cross_tenant_attempt');
    expectSqlState(function () use ($runtime): void {
        $runtime->exec("INSERT INTO portal_lab.effect(effect_id,organization_id,resource_id,actor_id)
                        VALUES ('rls-cross','org-b','resource-b','actor-b')");
    }, '42501');
    $runtime->exec('ROLLBACK TO SAVEPOINT cross_tenant_attempt');
    $runtime->commit();
    verify((int) $runtime->query('SELECT count(*) FROM portal_lab.resource')->fetchColumn() === 0,
        'SET LOCAL leaked across transaction boundary');
});

labCase('LAB-T04', 'Row lock blocks concurrent revocation until earlier effect commits', function () use ($dsn,$adminPassword,$admin): void {
    $writer = connection($dsn,'lab_owner',$adminPassword);
    $revoker = connection($dsn,'lab_owner',$adminPassword);

    $writer->beginTransaction();
    $actor = $writer->query("SELECT actor_id FROM portal_lab.account WHERE actor_id='actor-a' FOR SHARE")->fetchColumn();
    verify($actor === 'actor-a', 'Unable to lock synthetic account');
    verify(validSyntheticSession($writer,'portal-lab-handle-a'), 'Original session already invalid');
    $writer->exec("INSERT INTO portal_lab.effect(effect_id,organization_id,resource_id,actor_id)
                   VALUES ('effect-before-cut','org-a','resource-a','actor-a')");

    $revoker->beginTransaction();
    $revoker->exec("SET LOCAL lock_timeout = '200ms'");
    expectSqlState(function () use ($revoker): void {
        $revoker->exec("UPDATE portal_lab.account SET session_epoch=session_epoch + 1 WHERE actor_id='actor-a'");
    }, '55P03');
    $revoker->rollBack();
    $writer->commit();

    $revoker->beginTransaction();
    $revoker->exec("UPDATE portal_lab.account SET session_epoch=session_epoch + 1 WHERE actor_id='actor-a'");
    $revoker->commit();
    verify(!validSyntheticSession($admin,'portal-lab-handle-a'), 'Session survived completed revocation');
    verify((int)$admin->query("SELECT count(*) FROM portal_lab.effect WHERE effect_id='effect-before-cut'")->fetchColumn() === 1,
        'Previously committed effect was incorrectly lost');
});

labCase('LAB-T08', 'Epoch cut invalidates prior synthetic session', function () use ($admin): void {
    $epoch=(int) $admin->query("SELECT session_epoch FROM portal_lab.account WHERE actor_id='actor-a'")->fetchColumn();
    verify($epoch===2, 'Revocation epoch did not advance exactly once');
    verify(!validSyntheticSession($admin,'portal-lab-handle-a'), 'Old session became valid after revocation');
});

labCase('LAB-T09', 'Unprivileged SQL role cannot bypass account and session tables', function () use ($runtime, $admin): void {
    expectSqlState(fn () => $runtime->query('SELECT actor_id FROM portal_lab.account')->fetchAll(), '42501');
    expectSqlState(fn () => $runtime->query('SELECT verifier_sha256 FROM portal_lab.session')->fetchAll(), '42501');
    verify((int)$admin->query("SELECT count(*) FROM portal_lab.effect WHERE effect_id='rls-cross'")->fetchColumn()===0,
        'Rejected out-of-tenant effect persisted');
});

labCase('LAB-T07', 'Demonstrate RLS context spoofing counterexample: NOT mitigated', function () use ($runtime): void {
    $runtime->beginTransaction();
    $runtime->exec("SET LOCAL portal_lab.organization_id = 'org-b'");
    $payload=$runtime->query("SELECT synthetic_payload FROM portal_lab.resource WHERE resource_id='resource-b'")->fetchColumn();
    $runtime->commit();
    verify($payload === 'SYNTHETIC_B_ONLY', 'Counterexample not reproduced: examine threat-model assumptions');
    echo "[KNOWN GAP R01-10] SQL runtime can forge tenant context; RLS does NOT contain this attacker.\n";
});

echo "LAB SUITE: $passed executable synthetic cases completed; ZERO operational P0 gates closed.\n";
echo "NOT TESTED: actual issuer/MFA, SQL-injection defense, restore, service fencing, real HTTP and multi-writer order.\n";
