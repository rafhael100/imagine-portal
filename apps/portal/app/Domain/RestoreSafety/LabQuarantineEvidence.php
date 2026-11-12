<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

/**
 * Intentionally UNTRUSTED boolean fixtures, not real operational attestations.
 * In production, each condition would need an independent, non-rollbackable
 * machine-verifiable receipt and explicit reviewer approval.
 */
final readonly class LabQuarantineEvidence
{
    public function __construct(
        public bool $httpIngressFenced,
        public bool $writersAndJobsFenced,
        public bool $allSessionsQuarantined,
        public bool $mfaAndRecoveryChallengesInvalidated,
        public bool $serviceCredentialsRotated,
        public bool $membershipGrantsReconciled,
        public bool $cacheAndPoolDrained,
    ) {
    }

    public function allClaimsPresent(): bool
    {
        return $this->httpIngressFenced
            && $this->writersAndJobsFenced
            && $this->allSessionsQuarantined
            && $this->mfaAndRecoveryChallengesInvalidated
            && $this->serviceCredentialsRotated
            && $this->membershipGrantsReconciled
            && $this->cacheAndPoolDrained;
    }
}
