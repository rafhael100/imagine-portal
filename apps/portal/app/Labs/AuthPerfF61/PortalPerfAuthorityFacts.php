<?php

declare(strict_types=1);

namespace App\Labs\AuthPerfF61;

/**
 * Synthetic data observed in the CI PostgreSQL tables — NOT an authenticated
 * customer principal, commercial contract or token issuance grant.
 */
final readonly class PortalPerfAuthorityFacts
{
    public function __construct(
        public string $organizationRef,
        public string $tenantRef,
        public string $serviceRef,
        public string $actorRef,
        public string $sessionRef,
        public string $permission,
        public int $membershipRevision,
        public int $serviceRevision,
        public int $sessionRevision,
        public int $recoveryEpoch,
        public int $sourceObservedAt,
    ) {
    }
}
