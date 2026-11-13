<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabExternalWitnessReader;
use App\Domain\RestoreSafety\PortalWitnessAttestationVerifier;
use Closure;
use Throwable;

/**
 * Test-only transport for a purported Ed25519 witness. The verifier is now
 * first-party domain code, but the transport and signer are disposable.
 * No network, deployment, session grant, or rollback-resistant state.
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

            // Never permit a transport to choose its own challenge. A new
            // nonce for each read fences recorded response replay, not a
            // signer whose durable floor itself was rolled back.
            $nonce = bin2hex(random_bytes(32));
            $raw = ($this->transport)($organization, $nonce);
            if (!is_string($raw)) {
                return null;
            }
            return (new PortalWitnessAttestationVerifier())->verify(
                $raw, $organization, $nonce, $this->pinnedPublicKey
            );
        } catch (Throwable) {
            // A provider error or malformed signed claim is unavailable.
            return null;
        }
    }
}
