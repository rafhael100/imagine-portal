<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Candidate authoritative projection of a recorded customer action.
 * No controller or browser may construct this from request booleans.
 * A future implementation must load it from Portal IAM and consent ledger.
 */
final readonly class PortalClientAcceptanceSnapshot
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $releaseRef,
        public string $artifactDigest,
        public int $editorialRevision,
        public string $acceptanceRef,
        public int $acceptanceEpoch,
        public int $acceptedAt,
        public bool $clientMfaIdentityVerified,
        public bool $expressConsentRecorded,
        public bool $acceptanceCurrent,
    ) {}
}
