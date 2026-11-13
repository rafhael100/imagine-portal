<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use SensitiveParameter;
use Throwable;

/**
 * Verify a domain-separated, event-bound, challenged Ed25519 receipt.
 *
 * This verifies ONLY a cryptographic assertion. Key ownership, remote
 * durability, independence of restore domains and revocation atomicity
 * require separate operational mechanisms and independent audit.
 */
final class PortalRevocationReceiptVerifier
{
    public function verify(
        #[SensitiveParameter] string $rawEnvelope,
        OrganizationId $tenant,
        string $expectedEventId,
        #[SensitiveParameter] string $expectedChallenge,
        #[SensitiveParameter] string $pinnedPublicKey,
    ): ?PortalSignedRevocationClaim {
        try {
            if (strlen($rawEnvelope) < 1 || strlen($rawEnvelope) > 4096
                || strlen($pinnedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || preg_match('/\A[a-f0-9]{64}\z/D', $expectedChallenge) !== 1
                || preg_match(
                    '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',
                    $expectedEventId,
                ) !== 1) {
                return null;
            }

            $outer = json_decode($rawEnvelope, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($outer) || array_keys($outer) !== ['payload', 'signature']
                || !is_array($outer['payload'])
                || array_keys($outer['payload']) !== [
                    'version', 'purpose', 'challenge', 'eventId', 'tenant',
                    'cluster', 'sequence', 'coveredBackupSequence',
                    'sessionFloor', 'membershipFloor',
                ] || !is_string($outer['signature'])) {
                return null;
            }

            $p = $outer['payload'];
            if ($p['version'] !== 1
                || $p['purpose'] !== 'imagine-portal/revocation-ack/v1'
                || !is_string($p['challenge'])
                || !hash_equals($expectedChallenge, $p['challenge'])
                || !is_string($p['eventId'])
                || !hash_equals($expectedEventId, $p['eventId'])
                || !is_string($p['tenant'])
                || !hash_equals($tenant->value, $p['tenant'])
                || !is_string($p['cluster'])
                || !is_int($p['sequence'])
                || !is_int($p['coveredBackupSequence'])
                || !is_int($p['sessionFloor'])
                || !is_int($p['membershipFloor'])) {
                return null;
            }
            $signature = base64_decode($outer['signature'], true);
            if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return null;
            }
            // Sign the exact declared canonical ordering (no ambient JSON
            // fields or unsigned headers). Fail closed on any parse error.
            $bytes = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!sodium_crypto_sign_verify_detached(
                $signature, $bytes, $pinnedPublicKey
            )) {
                return null;
            }
            $floor = new LabExternalWitnessFloor(
                new OrganizationId($p['tenant']), $p['cluster'],
                $p['sequence'], $p['coveredBackupSequence'],
                $p['sessionFloor'], $p['membershipFloor'],
            );
            return new PortalSignedRevocationClaim($p['eventId'], $floor);
        } catch (Throwable) {
            return null;
        }
    }
}
