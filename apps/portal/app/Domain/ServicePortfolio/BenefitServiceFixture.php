<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * A synthetic, UNTRUSTED fixture. This object cannot establish customer
 * identity, service payment, eligibility, organization ownership or a grant.
 */
final readonly class BenefitServiceFixture
{
    public function __construct(
        public string $serviceReference,
        public OrganizationId $organizationId,
        public PortfolioProduct $product,
        public BenefitServiceState $state,
        public bool $declaredEligibleInFixture,
    ) {
        if (preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $serviceReference) !== 1) {
            throw new InvalidArgumentException('Only synthetic service references are valid.');
        }
        if ($product === PortfolioProduct::UniversidadeImagine) {
            throw new InvalidArgumentException('University cannot qualify itself as an active service.');
        }
    }

    public function isHypotheticalCandidate(): bool
    {
        return $this->declaredEligibleInFixture
            && $this->state === BenefitServiceState::ActiveIllustration;
    }

    public function provesRealEligibility(): bool
    {
        return false;
    }
}
