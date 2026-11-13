<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * Dormant review-only verifier for cryptographically signed witness epochs.
 * The trusted signing public key MUST be pinned independently of the restored
 * database, and private signing keys MUST NOT exist on the Portal cluster.
 * A valid signature never grants session/HTTP/business/deploy authority.
 */
final class IndependentWitnessCandidateVerifier
{
    public const SCHEMA = 'imagine.portal.f369.signed-witness.v1';
    private const SNAPSHOT_KEYS = [
        'organization', 'instance', 'generation', 'session_revision',
        'membership_revision', 'witnessed_sequence',
    ];
    private const RECEIPT_KEYS = [
        'schema', 'organization', 'instance', 'generation_floor',
        'session_floor', 'membership_floor', 'sequence', 'issued_at',
        'expires_at', 'signature',
    ];
    private const MAX_SEQUENCE = 1000000000;

    public function verify(array $snapshot, array $receipt, string $pinnedPublicKeyHex, int $now): array
    {
        if (!extension_loaded('sodium')
            || array_keys($snapshot) !== self::SNAPSHOT_KEYS
            || array_keys($receipt) !== self::RECEIPT_KEYS
            || ($receipt['schema'] ?? null) !== self::SCHEMA
            || $now < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $pinnedPublicKeyHex) !== 1
            || preg_match('/\A[a-f0-9]{128}\z/D', $receipt['signature'] ?? '') !== 1
        ) {
            return self::denied('WITNESS_CONTRACT_DENIED');
        }
        foreach (['organization', 'instance'] as $key) {
            if (!self::scope($snapshot[$key]) || !self::scope($receipt[$key])) {
                return self::denied('WITNESS_CONTRACT_DENIED');
            }
        }
        foreach (['generation', 'session_revision', 'membership_revision', 'witnessed_sequence'] as $key) {
            if (!self::positive($snapshot[$key])) {
                return self::denied('WITNESS_CONTRACT_DENIED');
            }
        }
        foreach (['generation_floor', 'session_floor', 'membership_floor', 'sequence'] as $key) {
            if (!self::positive($receipt[$key])) {
                return self::denied('WITNESS_CONTRACT_DENIED');
            }
        }
        $iat = $receipt['issued_at'];
        $exp = $receipt['expires_at'];
        if (!is_int($iat) || !is_int($exp) || $iat < 1 || $iat < $now - 120
            || $iat > $now + 30 || $exp <= $now || $exp <= $iat || $exp > $iat + 120) {
            return self::denied('WITNESS_FRESHNESS_DENIED');
        }
        $signature = hex2bin($receipt['signature']);
        $publicKey = hex2bin($pinnedPublicKeyHex);
        if (!is_string($signature) || !is_string($publicKey)
            || !sodium_crypto_sign_verify_detached($signature, self::canonical($receipt), $publicKey)) {
            return self::denied('WITNESS_SIGNATURE_DENIED');
        }
        if ($snapshot['organization'] !== $receipt['organization']
            || $snapshot['instance'] !== $receipt['instance']) {
            return self::denied('WITNESS_SCOPE_DENIED');
        }
        if ($snapshot['generation'] !== $receipt['generation_floor']
            || $snapshot['session_revision'] !== $receipt['session_floor']
            || $snapshot['membership_revision'] !== $receipt['membership_floor']
            || $snapshot['witnessed_sequence'] !== $receipt['sequence']) {
            return self::denied('WITNESS_RESTORED_EPOCH_CONFLICT');
        }
        return self::denied('WITNESS_MATCHED_STILL_NO_SERVING');
    }

    public static function canonical(array $receipt): string
    {
        return implode("\n", [
            'imagine.portal.f369.ed25519-witness-domain.v1',
            self::SCHEMA,
            (string) $receipt['organization'],
            (string) $receipt['instance'],
            (string) $receipt['generation_floor'],
            (string) $receipt['session_floor'],
            (string) $receipt['membership_floor'],
            (string) $receipt['sequence'],
            (string) $receipt['issued_at'],
            (string) $receipt['expires_at'],
        ]);
    }

    private static function scope(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $value) === 1;
    }

    private static function positive(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= self::MAX_SEQUENCE;
    }

    private static function denied(string $code): array
    {
        return [
            'schema' => 'imagine.portal.f369.witness-assessment.v1',
            'reasonCode' => $code,
            'canAuthorizeHttp' => false,
            'canAuthorizeBusiness' => false,
            'canReactivateSession' => false,
            'canRecoverFromRestore' => false,
            'canDeploy' => false,
        ];
    }
}
