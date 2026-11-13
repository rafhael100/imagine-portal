<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use SensitiveParameter;
use Throwable;

/**
 * Cryptographic format checker for an externally supplied synthetic witness.
 *
 * Verification proves only that the holder of the pinned key signed the
 * supplied floor for this challenge and tenant. It CANNOT prove that this
 * floor was durably written, independently hosted, not rolled back, or
 * ordered with the primary database's revocation transactions.
 *
 * No HTTP transport, key discovery, container binding or serving authority.
 */
final class PortalWitnessAttestationVerifier
{
    /**
     * @return LabExternalWitnessFloor|null A parsed claim, never permission.
     */
    public function verify(
        #[SensitiveParameter] string $envelope,
        OrganizationId $organization,
        #[SensitiveParameter] string $challenge,
        #[SensitiveParameter] string $pinnedPublicKey,
    ): ?LabExternalWitnessFloor {
        try {
            if (strlen($envelope) === 0 || strlen($envelope) > 2048
                || strlen($pinnedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || preg_match('/\A[a-f0-9]{64}\z/D', $challenge) !== 1) {
                return null;
            }

            $attestation = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($attestation)
                || array_keys($attestation) !== ['payload', 'signature']
                || !is_array($attestation['payload'])
                || array_keys($attestation['payload']) !== [
                    'version', 'challenge', 'tenant', 'cluster', 'sequence',
                    'coveredBackupSequence', 'sessionFloor', 'membershipFloor',
                ] || !is_string($attestation['signature'])) {
                return null;
            }

            $payload = $attestation['payload'];
            if ($payload['version'] !== 1
                || !is_string($payload['challenge'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $payload['challenge']) !== 1
                || !hash_equals($challenge, $payload['challenge'])
                || !is_string($payload['tenant'])
                || !hash_equals($organization->value, $payload['tenant'])
                || !is_string($payload['cluster'])
                || !is_int($payload['sequence'])
                || !is_int($payload['coveredBackupSequence'])
                || !is_int($payload['sessionFloor'])
                || !is_int($payload['membershipFloor'])) {
                return null;
            }

            $signature = base64_decode($attestation['signature'], true);
            if (!is_string($signature)
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return null;
            }

            // Reconstruct the exact signer-defined canonical field ordering;
            // unknown/missing fields are rejected before verifying signature.
            $message = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!sodium_crypto_sign_verify_detached(
                $signature, $message, $pinnedPublicKey
            )) {
                return null;
            }

            return new LabExternalWitnessFloor(
                new OrganizationId($payload['tenant']),
                $payload['cluster'],
                $payload['sequence'],
                $payload['coveredBackupSequence'],
                $payload['sessionFloor'],
                $payload['membershipFloor'],
            );
        } catch (Throwable) {
            // Never leak signer identity, tenant, invalid payload or key.
            return null;
        }
    }

    /** Signatures alone can never unlock the Portal or reissue a session. */
    public function canAuthorizeProductionServing(): bool
    {
        return false;
    }
}
