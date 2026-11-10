<?php

declare(strict_types=1);

require __DIR__.'/PortalGscF106OfflineStateLedger.php';

function f106Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('GSC F106: '.$message);
    }
}
function f106State(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

$env = [
    'APP_ENV' => 'testing',
    'PORTAL_F106_PG_LAB' => '1',
    'PGHOST' => '127.0.0.1',
    'PGPORT' => '5432',
    'PGDATABASE' => 'portal_identity_lab',
];
foreach ($env as $key => $value) {
    putenv($key.'='.$value);
}

$pdo = static function (string $user, string $password): PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab',
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
};
$password = getenv('F106_PG_CLIENT_PASSWORD');
f106Assert(is_string($password) && strlen($password) > 10, 'synthetic role credential set');
$client = $pdo('portal_gsc_f106_client', $password);
$otherClient = $pdo('portal_gsc_f106_client', $password);
$ledger = new PortalGscF106OfflineStateLedger($client);
$secondLedger = new PortalGscF106OfflineStateLedger($otherClient);

$actor = '123e4567-e89b-42d3-a456-426614174000';
$otherActor = '123e4567-e89b-42d3-a456-426614174099';
$org = '123e4567-e89b-42d3-a456-426614174001';
$otherOrg = '123e4567-e89b-42d3-a456-426614174002';
$property = str_repeat('a', 64);
$otherProperty = str_repeat('b', 64);
$now = 1794330000;

$state = f106State();
f106Assert($ledger->issue($state, $actor, $org, $property, $now), 'initial issue');
f106Assert(!$ledger->issue($state, $actor, $org, $property, $now), 'duplicate issue');
f106Assert($secondLedger->consume($state, $actor, $org, $property, $now + 1),
    'consume by different PDO connection');
f106Assert(!$ledger->consume($state, $actor, $org, $property, $now + 2),
    'one-time state across connections');

foreach ([
    [$actor, $otherOrg, $property, $now, 'wrong tenant'],
    [$otherActor, $org, $property, $now, 'wrong actor'],
    [$actor, $org, $otherProperty, $now, 'wrong property'],
    [$actor, $org, $property, $now - 1, 'premature'],
    [$actor, $org, $property, $now + 301, 'expired'],
] as [$candidateActor, $candidateOrg, $candidateProperty, $attemptTime, $name]) {
    $fresh = f106State();
    f106Assert($ledger->issue($fresh, $actor, $org, $property, $now), 'issue '.$name);
    f106Assert(!$secondLedger->consume(
        $fresh, $candidateActor, $candidateOrg, $candidateProperty, $attemptTime
    ), 'reject '.$name);
    f106Assert(!$ledger->consume($fresh, $actor, $org, $property, $now + 1),
        'failures burn state permanently: '.$name);
}

f106Assert(!$ledger->issue('bad-state', $actor, $org, $property, $now),
    'reject malformed state');
f106Assert(!$ledger->issue(f106State(), 'invalid-actor', $org, $property, $now),
    'reject malformed UUID');
f106Assert(!$ledger->issue(f106State(), $actor, $org, 'abc', $now),
    'reject malformed property fingerprint');

try {
    $client->query('SELECT state_digest FROM public.portal_gsc_f106_pending');
    throw new RuntimeException('direct SELECT on states unexpectedly allowed');
} catch (PDOException $e) {
    f106Assert($e->getCode() === '42501', 'direct state table SELECT denied');
}

try {
    $client->query('DELETE FROM public.portal_gsc_f106_pending');
    throw new RuntimeException('direct DELETE on states unexpectedly allowed');
} catch (PDOException $e) {
    f106Assert($e->getCode() === '42501', 'direct state table DELETE denied');
}

// Confirm a privileged handle cannot be injected into the public probe.
$superuserPassword = getenv('F106_PG_ADMIN_PASSWORD');
f106Assert(is_string($superuserPassword), 'admin lab credential');
$superuser = new PortalGscF106OfflineStateLedger($pdo('postgres', $superuserPassword));
f106Assert(!$superuser->issue(f106State(), $actor, $org, $property, $now),
    'refuse privileged PDO handle');

putenv('PORTAL_F106_PG_LAB=0');
f106Assert(!$ledger->issue(f106State(), $actor, $org, $property, $now),
    'lab disabled denies issue');
f106Assert(!$ledger->consume(f106State(), $actor, $org, $property, $now),
    'lab disabled denies consume');
f106Assert(!PortalGscF106OfflineStateLedger::authorizesGoogleAccess(), 'no Google grant');
f106Assert(!PortalGscF106OfflineStateLedger::authorizesPortalHttp(), 'no HTTP grant');

echo "PORTAL-GSC-F106 PostgreSQL atomic state ledger: PASS\n";
