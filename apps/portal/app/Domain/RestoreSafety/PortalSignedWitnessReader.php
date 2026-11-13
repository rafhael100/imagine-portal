<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use SensitiveParameter;
use Throwable;

/**
 * Uses a fresh challenge on every observation and a first-party signature
 * verifier. Not configured with any witness endpoint or service credentials.
 *
 * A verified response supplies only a synthetic claim: an authenticated
 * signer can still be restored to old state and answer fresh challenges.
 * Never promotes a session, approves a recovery or resumes serving.
 */
final readonly class PortalSignedWitnessReader implements LabExternalWitnessReader
{
    public function __construct(
        private PortalWitnessChallengeTransport $transport,
        #[SensitiveParameter] private string $pinnedPublicKey,
        private PortalWitnessAttestationVerifier $verifier,
    ) {
    }

    public function observe(OrganizationId $organization): ?LabExternalWitnessFloor
    {
        try {
            if (strlen($this->pinnedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return null;
            }
            $challenge = bin2hex(random_bytes(32));
            $envelope = $this->transport->fetchAttestation($organization, $challenge);
            if (!is_string($envelope)) {
                return null;
            }
            return $this->verifier->verify(
                $envelope, $organization, $challenge, $this->pinnedPublicKey
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function canAuthorizeServing(): bool
    {
        return false;
    }
}
