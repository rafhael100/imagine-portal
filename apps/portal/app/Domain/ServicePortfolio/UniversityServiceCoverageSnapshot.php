<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * Candidate current-service coverage from a FUTURE trusted billing/contract
 * source; never evidence of paid invoices or authority by itself.
 */
final readonly class UniversityServiceCoverageSnapshot
{
    public function __construct(
        public string $serviceReference,
        public OrganizationId $organizationId,
        public PortfolioProduct $product,
        public string $planSku,
        public int $contractRevision,
        public int $policyRevision,
        public int $effectiveFromEpoch,
        public int $effectiveUntilEpoch,
        public int $observedAtEpoch,
        public int $expiresAtEpoch,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,119}\z/D', $serviceReference) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $planSku) !== 1
            || $product === PortfolioProduct::UniversidadeImagine
            || $contractRevision < 1
            || $policyRevision < 1
            || $effectiveFromEpoch <= 0
            || $effectiveUntilEpoch <= $effectiveFromEpoch
            || $observedAtEpoch <= 0
            || $expiresAtEpoch <= $observedAtEpoch
        ) {
            throw new InvalidArgumentException('Invalid university service coverage snapshot.');
        }
    }

    public function mayAuthorizeUniversity(): bool
    {
        return false;
    }
}
