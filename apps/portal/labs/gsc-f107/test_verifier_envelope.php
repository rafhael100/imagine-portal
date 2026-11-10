<?php

declare(strict_types=1);

require __DIR__.'/PortalGscF107OfflineVerifierEnvelope.php';

function f107Check(bool $condition, string $why): void
{
    if (!$condition) {
        throw new RuntimeException('GSC-F107: '.$why);
    }
}
function f107B64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

putenv('APP_ENV=testing');
putenv('PORTAL_F107_GSC_LAB=1');
$testKey = f107B64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('PORTAL_F107_KEY_B64='.$testKey);
$verifier = f107B64(random_bytes(32));
$stateDigest = hash('sha256', f107B64(random_bytes(32)));
$actor = '123e4567-e89b-42d3-a456-426614174000';
$org = '123e4567-e89b-42d3-a456-426614174001';
$otherOrg = '123e4567-e89b-42d3-a456-426614174002';
$property = hash('sha256', 'sc-domain:imagine.example');
$now = 1794330000;

$envelope = PortalGscF107OfflineVerifierEnvelope::seal(
    $verifier, $stateDigest, $actor, $org, $property, $now
);
f107Check(is_array($envelope), 'seal synthetic verifier');
f107Check($envelope['version'] === 'portal-gsc-secretbox-offline-v1', 'versioned envelope');
f107Check($envelope['persisted'] === false && $envelope['authorization'] === 'HOLD', 'no grant');
f107Check(!str_contains(json_encode($envelope), $verifier), 'never return clear verifier');
f107Check(!str_contains(json_encode($envelope), $actor), 'never return actor id');
f107Check(!str_contains(json_encode($envelope), $testKey), 'never return encryption key');

$opened = PortalGscF107OfflineVerifierEnvelope::unseal(
    $envelope, $stateDigest, $actor, $org, $property, $now + 20
);
f107Check($opened === $verifier, 'open only for the exact synthetic context');

$second = PortalGscF107OfflineVerifierEnvelope::seal(
    $verifier, $stateDigest, $actor, $org, $property, $now
);
f107Check(is_array($second)
    && $second['nonce'] !== $envelope['nonce']
    && $second['ciphertext'] !== $envelope['ciphertext'], 'fresh independent nonces');

foreach ([
    [$stateDigest, $actor, $otherOrg, $property, $now, 'cross tenant'],
    [$stateDigest, '123e4567-e89b-42d3-a456-426614174099', $org, $property, $now, 'cross actor'],
    [$stateDigest, $actor, $org, str_repeat('b', 64), $now, 'cross property'],
    [str_repeat('f', 64), $actor, $org, $property, $now, 'wrong state'],
    [$stateDigest, $actor, $org, $property, $now - 1, 'before issue'],
    [$stateDigest, $actor, $org, $property, $now + 301, 'expired'],
] as [$s, $a, $o, $p, $time, $name]) {
    f107Check(PortalGscF107OfflineVerifierEnvelope::unseal(
        $envelope, $s, $a, $o, $p, $time
    ) === null, 'reject '.$name);
}

$invalidEnvelope = [...$envelope, 'oauthVerified' => true];
f107Check(PortalGscF107OfflineVerifierEnvelope::unseal(
    $invalidEnvelope, $stateDigest, $actor, $org, $property, $now
) === null, 'reject unknown envelope fields');

foreach (['nonce', 'ciphertext'] as $field) {
    $tampered = $envelope;
    $tampered[$field] = ($tampered[$field][0] === 'A' ? 'B' : 'A')
        .substr($tampered[$field], 1);
    f107Check(PortalGscF107OfflineVerifierEnvelope::unseal(
        $tampered, $stateDigest, $actor, $org, $property, $now
    ) === null, 'reject tampered '.$field);
}
f107Check(PortalGscF107OfflineVerifierEnvelope::seal(
    'invalid-short', $stateDigest, $actor, $org, $property, $now
) === null, 'reject weak verifier');

putenv('PORTAL_F107_KEY_B64='.f107B64(random_bytes(32)));
f107Check(PortalGscF107OfflineVerifierEnvelope::unseal(
    $envelope, $stateDigest, $actor, $org, $property, $now
) === null, 'wrong key cannot decrypt');

putenv('PORTAL_F107_KEY_B64=short');
f107Check(PortalGscF107OfflineVerifierEnvelope::seal(
    $verifier, $stateDigest, $actor, $org, $property, $now
) === null, 'weak key rejected');
putenv('PORTAL_F107_KEY_B64='.$testKey);
putenv('PORTAL_F107_GSC_LAB=0');
f107Check(PortalGscF107OfflineVerifierEnvelope::unseal(
    $envelope, $stateDigest, $actor, $org, $property, $now
) === null, 'disabled lab cannot decrypt');
f107Check(PortalGscF107OfflineVerifierEnvelope::authorizesGoogleAccess() === false,
    'never grants Google access');

echo "PORTAL-GSC-F107 encrypted verifier envelope: PASS\n";
