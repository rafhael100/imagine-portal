<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use RuntimeException;

/**
 * IN-MEMORY synthetic remote signer. Not a remote service, a durable
 * monotonic authority, a deployment primitive or a serving permission.
 * Signatures prove possession of one fixture key, NOT freshness of floors.
 */
final class DisposableWitnessAttestationSigner
{
    public function __construct(
        private readonly string $secretKey,
        private LabExternalWitnessFloor $floor,
    ) {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Invalid disposable witness signing key.');
        }
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey);
    }

    /** Synthetic update, deliberately volatile and not rollback resistant. */
    public function advance(LabExternalWitnessFloor $next): void
    {
        if (!$next->organization->equals($this->floor->organization)
            || $next->witnessClusterId !== $this->floor->witnessClusterId
            || $next->sequence !== $this->floor->sequence + 1
            || $next->coveredBackupSequence < $this->floor->coveredBackupSequence
            || $next->sessionFloor < $this->floor->sessionFloor
            || $next->membershipFloor < $this->floor->membershipFloor) {
            throw new RuntimeException('Disposable signer floor regression refused.');
        }
        $this->floor = $next;
    }

    /**
     * Respond to a fresh caller-supplied random nonce. No network call.
     *
     * @return string|null Strict JSON containing payload + Ed25519 signature.
     */
    public function respond(OrganizationId $tenant, string $challenge): ?string
    {
        if (!$tenant->equals($this->floor->organization)
            || preg_match('/\A[a-f0-9]{64}\z/D', $challenge) !== 1) {
            return null;
        }
        $f = $this->floor;
        $payload = [
            'version' => 1,
            'challenge' => $challenge,
            'tenant' => $f->organization->value,
            'cluster' => $f->witnessClusterId,
            'sequence' => $f->sequence,
            'coveredBackupSequence' => $f->coveredBackupSequence,
            'sessionFloor' => $f->sessionFloor,
            'membershipFloor' => $f->membershipFloor,
        ];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode([
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached($encoded, $this->secretKey)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
