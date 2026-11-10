<?php

declare(strict_types=1);

require __DIR__.'/PortalGscF108OfflineSealedState.php';

function f108Check(bool $condition, string $why): void
{
    if (!$condition) {
        throw new RuntimeException('GSC-F108: '.$why);
    }
}
function f108Random(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

foreach ([
    'APP_ENV' => 'testing',
    'PORTAL_F106_PG_LAB' => '1',
    'PORTAL_F107_GSC_LAB' => '1',
    'PORTAL_F108_PG_LAB' => '1',
    'PGHOST' => '127.0.0.1',
    'PGPORT' => '5432',
    'PGDATABASE' => 'portal_identity_lab',
] as $k => $v) {
    putenv($k.'='.$v);
}
$key = f108Random();
putenv('PORTAL_F107_KEY_B64='.$key);

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab';
$db = static fn(string $user, string $pw): PDO => new PDO($dsn, $user, $pw, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$clientPassword = getenv('F106_PG_CLIENT_PASSWORD');
$adminPassword = getenv('F106_PG_ADMIN_PASSWORD');
f108Check(is_string($clientPassword) && is_string($adminPassword), 'test credentials supplied');

$client = $db('portal_gsc_f106_client', $clientPassword);
$otherClient = $db('portal_gsc_f106_client', $clientPassword);
$admin = $db('postgres', $adminPassword);
$service = new PortalGscF108OfflineSealedState($client);
$other = new PortalGscF108OfflineSealedState($otherClient);
$actor = '123e4567-e89b-42d3-a456-426614174000';
$otherActor = '123e4567-e89b-42d3-a456-426614174099';
$org = '123e4567-e89b-42d3-a456-426614174001';
$otherOrg = '123e4567-e89b-42d3-a456-426614174002';
$property = hash('sha256', 'sc-domain:imagine.example');
$otherProperty = str_repeat('f', 64);
$now = 1794330000;

$state = f108Random();
$verifier = f108Random();
f108Check($service->issue($state, $verifier, $actor, $org, $property, $now),
    'issue sealed OAuth state');
f108Check(!$service->issue($state, $verifier, $actor, $org, $property, $now),
    'duplicate state rejected');

$pending = $admin->query(
    'SELECT state_digest,envelope_nonce,envelope_ciphertext '
    .'FROM public.portal_gsc_f106_pending LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
f108Check(is_array($pending) && $pending['state_digest'] === hash('sha256', $state),
    'only digest persisted');
f108Check(!str_contains(json_encode($pending), $verifier)
    && !str_contains(json_encode($pending), $state), 'secrets not persisted in clear');
f108Check(strlen($pending['envelope_nonce']) === 32
    && strlen($pending['envelope_ciphertext']) > 80, 'authenticated ciphertext persisted');

f108Check($other->consume($state, $actor, $org, $property, $now + 1) === $verifier,
    'decrypt only after correct state consumed across PDO connections');
f108Check($service->consume($state, $actor, $org, $property, $now + 2) === null,
    'cross-process replay denied');

foreach ([
    [$otherActor, $org, $property, $now + 1, 'other actor'],
    [$actor, $otherOrg, $property, $now + 1, 'other organization'],
    [$actor, $org, $otherProperty, $now + 1, 'other property'],
    [$actor, $org, $property, $now - 1, 'early callback'],
    [$actor, $org, $property, $now + 301, 'expired callback'],
] as [$a, $o, $p, $when, $case]) {
    $s = f108Random();
    f108Check($service->issue($s, f108Random(), $actor, $org, $property, $now),
        'prepare '.$case);
    f108Check($other->consume($s, $a, $o, $p, $when) === null,
        'deny '.$case);
    f108Check($service->consume($s, $actor, $org, $property, $now + 2) === null,
        'failed claim permanently burns state '.$case);
}

$wrongKeyState = f108Random();
f108Check($service->issue($wrongKeyState, $verifier, $actor, $org, $property, $now),
    'prepare invalid key callback');
putenv('PORTAL_F107_KEY_B64='.f108Random());
f108Check($other->consume($wrongKeyState, $actor, $org, $property, $now + 1) === null,
    'ciphertext cannot be decrypted with different key');
putenv('PORTAL_F107_KEY_B64='.$key);
f108Check($service->consume($wrongKeyState, $actor, $org, $property, $now + 2) === null,
    'key failure cannot replay');

$falsified = f108Random();
f108Check($service->issue($falsified, $verifier, $actor, $org, $property, $now),
    'prepare altered ciphertext');
$alter = $admin->prepare(
    'UPDATE public.portal_gsc_f106_pending SET envelope_ciphertext=:cipher '
    .'WHERE state_digest=:digest'
);
$alter->execute([
    'cipher' => str_repeat('A', 120),
    'digest' => hash('sha256', $falsified),
]);
f108Check($service->consume($falsified, $actor, $org, $property, $now + 1) === null,
    'ciphertext forgery rejected after atomic claim');

try {
    $client->query('SELECT envelope_ciphertext FROM public.portal_gsc_f106_pending');
    throw new RuntimeException('unexpected client SELECT of sealed verifier');
} catch (PDOException $e) {
    f108Check($e->getCode() === '42501', 'client has no direct table SELECT');
}

f108Check(!$service->issue(f108Random(), 'short', $actor, $org, $property, $now),
    'weak PKCE verifier rejected');
f108Check(!$service->issue('invalid-state', $verifier, $actor, $org, $property, $now),
    'invalid state rejected');
f108Check(!$service->issue(f108Random(), $verifier, $actor, $org, 'bad', $now),
    'invalid property rejected');
f108Check(!(new PortalGscF108OfflineSealedState($admin))
    ->issue(f108Random(), $verifier, $actor, $org, $property, $now),
    'privileged PDO refused');
putenv('PORTAL_F108_PG_LAB=0');
f108Check(!$service->issue(f108Random(), $verifier, $actor, $org, $property, $now),
    'disabled lab denies issue');
f108Check($service->consume(f108Random(), $actor, $org, $property, $now) === null,
    'disabled lab denies consumption');
f108Check(!PortalGscF108OfflineSealedState::authorizesHttp()
    && !PortalGscF108OfflineSealedState::authorizesGoogle(),
    'no operational OAuth or HTTP');

echo "PORTAL-GSC-F108 encrypted PG one-time OAuth state: PASS\n";
