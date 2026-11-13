<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabExternalWitnessReader;
use Closure;
use Throwable;

/**
 * Offline reader that pins a witness Ed25519 public key and challenges
 * each observation with a fresh 256-bit nonce. No network, HTTP, DB,
 * container binding or release capability. Signatures are NOT proof
 * that a witness's own state is rollback-resistant.
 */
final readonly class DisposableSignedWitnessReader implements LabExternalWitnessReader
{
    /** @param Closure(OrganizationId,string):(string|null) $transport */
    public function __construct(
        private string $pinnedPublicKey,
        private Closure $transport,
    ) {
    }

    public function observe(OrganizationId $organization): ?LabExternalWitnessFloor
    {
        try {
            if (strlen($this->pinnedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return null;
            }
            $nonce = bin2hex(random_bytes(32));
            $raw = ($this->transport)($organization, $nonce);
            if (!is_string($raw) || strlen($raw) === 0 || strlen($raw) > 2048) {
                return null;
            }
            $attestation = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($attestation)
                || array_keys($attestation) !== ['payload','signature']
                || !is_array($attestation['payload'])
                || array_keys($attestation['payload']) !== [
                    'version','challenge','tenant','cluster','sequence',
                    'coveredBackupSequence','sessionFloor','membershipFloor',
                ] || !is_string($attestation['signature'])) {
                return null;
            }
            $p = $attestation['payload'];
            if ($p['version'] !== 1 || !is_string($p['challenge'])
                || !hash_equals($nonce, $p['challenge'])
                || !is_string($p['tenant'])
                || !hash_equals($organization->value, $p['tenant'])
                || !is_string($p['cluster'])
                || !is_int($p['sequence']) || !is_int($p['coveredBackupSequence'])
                || !is_int($p['sessionFloor']) || !is_int($p['membershipFloor'])) {
                return null;
            }
            $signature = base64_decode($attestation['signature'], true);
            if (!is_string($signature)
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return null;
            }
            $message = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!sodium_crypto_sign_verify_detached(
                $signature, $message, $this->pinnedPublicKey
            )) {
                return null;
            }
            return new LabExternalWitnessFloor(
                new OrganizationId($p['tenant']),
                $p['cluster'],
                $p['sequence'],
                $p['coveredBackupSequence'],
                $p['sessionFloor'],
                $p['membershipFloor'],
            );
        } catch (Throwable) {
            // Avoid revealing tenant, public key, payload or remote exception.
            return null;
        }
    }
}
