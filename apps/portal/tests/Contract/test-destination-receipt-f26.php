<?php

declare(strict_types=1);

/**
 * F26 — prova de contrato local. As chaves são efêmeras; nenhum recibo é
 * coletado de Cloudflare e nenhum ACK de banco é efetuado.
 */
require_once dirname(__DIR__, 2).'/app/Domain/CommercialPublishing/DestinationDeliveryReceiptVerifier.php';

use App\Domain\CommercialPublishing\DestinationDeliveryReceiptVerifier as Verifier;

$destinationPair = sodium_crypto_sign_keypair();
$destinationSecret = sodium_crypto_sign_secretkey($destinationPair);
$destinationPublic = sodium_crypto_sign_publickey($destinationPair);
$portalPair = sodium_crypto_sign_keypair();
$untrustedSecret = sodium_crypto_sign_secretkey($portalPair);
$keyId = 'cloudflare_lab_receipt_01';
$now = new DateTimeImmutable('2026-11-09T16:40:00Z');
$scope = [
    'organizationId' => 'tenant_imagine_01',
    'releaseId' => 'release_20261109_parity',
    'payloadSha256' => str_repeat('a', 64),
    'treeSha256' => str_repeat('b', 64),
    'dispatchId' => 'dispatch_parity_20261109',
    'generation' => 3,
    'attempt' => 2,
    'destinationId' => 'cloudflare_staging_01',
    'environment' => 'staging',
    'nonce' => bin2hex(random_bytes(16)),
];
$expected = $scope + ['receiptKeyId' => $keyId];
$claims = ['schemaVersion'=>'1.0'] + $scope + ['observedAt'=>'2026-11-09T16:39:30Z'];
$keys = [$keyId => $destinationPublic];
$checks = 0;

$envelopeOf = static function (
    array $receipt,
    ?string $secret = null,
    ?string $raw = null
) use ($destinationSecret, $keyId): array {
    $bytes = $raw ?? json_encode(
        $receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    return [
        'protocol' => 'imagine-commercial-delivery-receipt-v1',
        'keyId' => $keyId,
        'payloadBase64' => base64_encode($bytes),
        'signatureBase64' => base64_encode(sodium_crypto_sign_detached(
            "imagine-commercial-delivery-receipt-v1\0".$bytes,
            $secret ?? $destinationSecret
        )),
    ];
};
$mustReject = static function (string $case, callable $action, string $fragment) use (&$checks): void {
    try {
        $action();
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), $fragment)) {
            throw new RuntimeException($case.': incorrect denial: '.$exception->getMessage());
        }
        $checks++;
        return;
    }
    throw new RuntimeException($case.': dangerous input was accepted');
};
$valid = $envelopeOf($claims);
$verified = Verifier::verify($valid, $expected, $keys, $now);
if ($verified !== $claims) {
    throw new RuntimeException('Valid independently signed receipt did not match exact claims.');
}
$checks++;

// An independent destination signature is required; the Portal's own key
// must not be accepted as a delivery witness merely because it can sign.
$mustReject('forged destination signature', static fn() => Verifier::verify(
    $envelopeOf($claims, $untrustedSecret), $expected, $keys, $now
), 'signature verification failed');
$mustReject('missing trusted key', static fn() => Verifier::verify(
    $valid, $expected, [], $now
), 'unknown destination key');
$mustReject('wrong pinned destination key ID', static fn() => Verifier::verify(
    $valid, [...$expected, 'receiptKeyId'=>'another_receipt_key'], $keys, $now
), 'untrusted key ID');
$mustReject('wrong domain protocol', static fn() => Verifier::verify(
    [...$valid, 'protocol'=>'imagine-catalog-v1'], $expected, $keys, $now
), 'unsupported protocol');
$mustReject('private envelope field', static fn() => Verifier::verify(
    [...$valid, 'token'=>'secret'], $expected, $keys, $now
), 'invalid envelope fields');
$mustReject('invalid Base64', static fn() => Verifier::verify(
    [...$valid, 'payloadBase64'=>'%%%'], $expected, $keys, $now
), 'invalid payload encoding');
$mustReject('invalid signature size', static fn() => Verifier::verify(
    [...$valid, 'signatureBase64'=>base64_encode('small')], $expected, $keys, $now
), 'signature verification failed');

// Every release/attempt/authority/target/nonce must match the trusted state.
foreach ([
    'organizationId'=>'tenant_foreign_01',
    'releaseId'=>'release_20261109_wrong',
    'payloadSha256'=>str_repeat('c', 64),
    'treeSha256'=>str_repeat('c', 64),
    'dispatchId'=>'dispatch_wrong_01',
    'generation'=>4,
    'attempt'=>1,
    'destinationId'=>'cloudflare_other_01',
    'environment'=>'production',
    'nonce'=>bin2hex(random_bytes(16)),
] as $field=>$changed) {
    $badClaims = $claims;
    $badClaims[$field] = $changed;
    $mustReject('unexpected scope '.$field, static fn() => Verifier::verify(
        $envelopeOf($badClaims), $expected, $keys, $now
    ), 'scope mismatch: '.$field);
}
$mustReject('unexpected unapproved payload field', static fn() => Verifier::verify(
    $envelopeOf([...$claims, 'customerEmail'=>'private@example.com']),
    $expected, $keys, $now
), 'invalid payload fields');
$mustReject('empty generation', static fn() => Verifier::verify(
    $envelopeOf([...$claims, 'generation'=>0]), $expected, $keys, $now
), 'invalid generation');
$mustReject('invalid nonce', static fn() => Verifier::verify(
    $envelopeOf([...$claims, 'nonce'=>'predictable']), $expected, $keys, $now
), 'invalid environment or nonce');
$mustReject('expired receipt', static fn() => Verifier::verify(
    $envelopeOf([...$claims, 'observedAt'=>'2026-11-09T16:34:00Z']),
    $expected, $keys, $now
), 'outside freshness window');
$mustReject('far-future receipt', static fn() => Verifier::verify(
    $envelopeOf([...$claims, 'observedAt'=>'2026-11-09T16:41:00Z']),
    $expected, $keys, $now
), 'outside freshness window');
$mustReject('invalid calendar', static fn() => Verifier::verify(
    $envelopeOf([...$claims, 'observedAt'=>'2026-11-31T16:39:30Z']),
    $expected, $keys, $now
), 'invalid observation date');
// Duplicate JSON keys are forbidden even when a valid destination key
// (or test signer) signs the ambiguous byte sequence.
$raw = json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$duplicateJson = str_replace('"schemaVersion":"1.0"', '"schemaVersion":"1.0","schemaVersion":"1.0"', $raw);
$mustReject('ambiguous duplicate JSON key', static fn() => Verifier::verify(
    $envelopeOf($claims, null, $duplicateJson), $expected, $keys, $now
), 'noncanonical or ambiguous JSON');

// This verifies a cryptographic statement only. Replaying the same receipt
// is still possible here; the durable dispatch/lease/nonce layer MUST reject it.
if (Verifier::verify($valid, $expected, $keys, $now) !== $claims) {
    throw new RuntimeException('Unexpected verifier behavior for repeated valid proof.');
}
$checks++;
echo "Portal Commercial F26 destination receipt contract passed: {$checks} checks; no ack, no deploy.\n";
