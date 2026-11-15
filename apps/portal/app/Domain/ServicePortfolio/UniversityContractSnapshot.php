<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * Candidate contract snapshot from a future trusted server-side source.
 *
 * CALLER-CONSTRUCTIBLE: not identity evidence, paid invoice, signed
 * entitlement, or an authorization token. A tenant ID and boolean in
 * this object never suffice to grant university access.
 */
final readonly class UniversityContractSnapshot
{
    public function __construct(
        public string $serviceReference,
        public OrganizationId $organizationId,
        public PortfolioProduct $product,
        public UniversityContractStatus $status,
        public bool $eligibleUnderContract,
        public bool $trialOnly,
        public int $sourceRevision,
        public int $observedAtEpoch,
        public int $expiresAtEpoch,
    ) {
        if (
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,119}\z/D', $serviceReference) !== 1
            || $sourceRevision < 1
            || $observedAtEpoch <= 0
            || $expiresAtEpoch <= $observedAtEpoch
            || $product === PortfolioProduct::UniversidadeImagine
        ) {
            throw new InvalidArgumentException('Invalid university contract snapshot.');
        }
    }

    public function isQualifyingCandidate(): bool
    {
        return $this->status === UniversityContractStatus::Active
            && $this->eligibleUnderContract
            && !$this->trialOnly;
    }
}
