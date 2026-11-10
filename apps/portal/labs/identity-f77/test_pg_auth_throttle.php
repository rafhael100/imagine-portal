<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F77_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F77 can only run in disposable PG18 laboratory.');
}
$adminPass = getenv('F66_PG_ADMIN_PASSWORD');
$throttlePass = getenv('F77_PG_THROTTLE_PASSWORD');
$unprivPass = getenv('F77_PG_UNPRIV_PASSWORD');
if (!is_string($adminPass) || strlen($adminPass) < 12
    || !is_string($throttlePass) || strlen($throttlePass) < 12
    || !is_string($unprivPass) || strlen($unprivPass) < 12) {
    throw new RuntimeException('F77 requires ephemeral CI role secrets.');
}
$connect = static fn(string $role, string $pass): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$admin = $connect('postgres', $adminPass);
$throttler = $connect('portal_f77_throttler', $throttlePass);
$second = $connect('portal_f77_throttler', $throttlePass);
$unpriv = $connect('portal_f77_unprivileged', $unprivPass);
$checks = 0;
$ok = static function (bool $valid, string $message) use (&$checks): void {
    if (!$valid) {
        throw new RuntimeException('F77 FAIL: ' . $message);
    }
    ++$checks;
};
$claim = static function (PDO $pdo, string $scope, string $action): bool {
    $stmt = $pdo->prepare('SELECT public.portal_f77_claim_auth_attempt(:scope,:action)');
    $stmt->execute(['scope'=>$scope,'action'=>$action]);
    return in_array($stmt->fetchColumn(), [true, 1, '1', 't'], true);
};
$denied = static function (callable $attempt, string $label) use ($ok): void {
    try {
        $attempt();
    } catch (PDOException $e) {
        $ok($e->getCode() === '42501', $label . ' permission denied');
        return;
    }
    $ok(false, $label . ' unexpectedly allowed');
};

// The pepper never leaves the PHP test process. These are not real user
// identities/IP addresses and the scope digests are not bearer credentials.
$pepper = random_bytes(32);
$scope = hash_hmac(
    'sha256',
    'portal-v1|account-ip|login|fictional-user@example.invalid|198.51.100.0/24',
    $pepper,
);
$secondScope = hash_hmac(
    'sha256',
    'portal-v1|account-ip|login|second-fictional-user@example.invalid|198.51.100.0/24',
    $pepper,
);

$ok(strlen($scope) === 64 && $scope !== $secondScope,
    'scopes are HMAC strings, no plaintext account/IP');
for ($i=1;$i<=5;$i++) {
    $ok($claim($i % 2 === 0 ? $second : $throttler, $scope, 'login'),
        'allowed login attempt '.$i.' on different DB connections');
}
$ok(!$claim($throttler,$scope,'login'), 'sixth login attempt blocked in 15-minute window');
$ok(!$claim($second,$scope,'login'), 'seventh login attempt still blocked');
$ok($claim($throttler,$secondScope,'login'),
    'independent account+IP scope still has its own budget');
$ok($claim($second,$scope,'totp'), 'MFA budget independent from login budget');
$ok($claim($throttler,$scope,'recovery'), 'recovery budget independent from login budget');

$denied(static fn() => $throttler->query('SELECT * FROM portal_auth_attempt_buckets'),
    'non-superuser cannot SELECT throttle rows');
$denied(static fn() => $unpriv->query(
    "SELECT public.portal_f77_claim_auth_attempt('" . str_repeat('0',64) . "','login')"
), 'unprivileged database role cannot EXECUTE throttle function');

$ok(!$claim($throttler,'invalid-hash','login'), 'malformed scope rejected');
$ok(!$claim($throttler,strtoupper($scope),'login'), 'uppercase hash rejected');
$ok(!$claim($throttler,$scope,'admin'), 'unsupported action rejected');
$ok(!$claim($throttler,$scope,"login'); DROP TABLE portal_auth_attempt_buckets;--"),
    'SQL-injection-shaped action rejected');

$stmt=$admin->prepare(
    'SELECT attempts FROM portal_auth_attempt_buckets WHERE scope_hash=? AND action_code=?'
);
$stmt->execute([$scope,'login']);
$ok((int)$stmt->fetchColumn() === 6, 'excess attempts cap at six');
$ok((int)$admin->query(
    "SELECT count(*) FROM pg_policies WHERE tablename='portal_auth_attempt_buckets'"
)->fetchColumn() === 0, 'table remains FORCE RLS with zero permissive policies');

$old = $admin->prepare(
    "UPDATE portal_auth_attempt_buckets
        SET window_started_at=clock_timestamp()-interval '16 minutes',
            last_seen_at=clock_timestamp()-interval '1 minute'
      WHERE scope_hash=? AND action_code='login'"
);
$old->execute([$scope]);
$ok($claim($second,$scope,'login'), '15-minute window reset renews budget');
$stmt->execute([$scope,'login']);
$ok((int)$stmt->fetchColumn() === 1, 'window reset also resets attempts to one');

$lock = $connect('postgres',$adminPass);
$lock->beginTransaction();
$lockStmt=$lock->prepare(
    "SELECT attempts FROM portal_auth_attempt_buckets
      WHERE scope_hash=? AND action_code='login' FOR UPDATE"
);
$lockStmt->execute([$scope]);
$ok((int)$lockStmt->fetchColumn()===1, 'counter row locked by parallel connection');
$throttler->exec("SET lock_timeout TO '100ms'");
try {
    try {
        $claim($throttler,$scope,'login');
        $ok(false,'lock timeout unexpectedly accepted');
    } catch(PDOException $e) {
        $ok(in_array($e->getCode(), ['55P03','57014'], true),
            'lock timeout fails closed without consuming attempt');
    }
} finally {
    $lock->rollBack();
}
$stmt->execute([$scope,'login']);
$ok((int)$stmt->fetchColumn()===1,
    'attempt count unchanged by aborted locked transaction');
$ok($claim($second,$scope,'login'), 'after lock release a valid claim can proceed');
$stmt->execute([$scope,'login']);
$ok((int)$stmt->fetchColumn()===2, 'after lock release update is committed');

$ok((int)$admin->query(
    "SELECT count(*) FROM pg_roles
      WHERE rolname='portal_f77_throttler' AND NOT rolsuper
        AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole"
)->fetchColumn()===1, 'throttle role has no superuser, RLS-bypass or admin rights');
$ok((int)$admin->query(
    "SELECT count(*) FROM pg_proc
      WHERE oid='public.portal_f77_claim_auth_attempt(text,text)'::regprocedure
        AND prosecdef = true
        AND proconfig::text LIKE '%search_path=pg_catalog, pg_temp%'"
)->fetchColumn()===1,'function uses fixed schema qualification and search path');
$ok((int)$admin->query(
    "SELECT count(*) FROM information_schema.columns
      WHERE table_schema='public' AND table_name='portal_auth_attempt_buckets'
      AND column_name IN ('email','ip','phone','password','bearer','session_hash')"
)->fetchColumn()===0, 'throttle table has no direct PII or session secret column');

sodium_memzero($pepper);
echo 'PASS PORTAL-F77 ' . $checks .
    " atomic PostgreSQL throttle claims, no customer login or release permission.\n";
