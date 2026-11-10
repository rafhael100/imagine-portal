<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

/**
 * Illustrates the business criterion "at least one active, eligible service".
 * Caller-supplied org IDs and fixture booleans are untrusted: even positive
 * results NEVER issue access, login, membership, payment or entitlements.
 */
final readonly class UniversityBenefitPreviewPolicy
{
    /** @param array<array-key,mixed> $fixtures */
    public function inspect(
        ?OrganizationId $selectedOrganization,
        array $fixtures,
    ): UniversityBenefitPreviewResult {
        if ($selectedOrganization === null) {
            return UniversityBenefitPreviewResult::blocked('missing_organization');
        }
        if (!array_is_list($fixtures) || count($fixtures) > 50) {
            return UniversityBenefitPreviewResult::blocked('invalid_fixture_input');
        }

        $seen = [];
        $candidates = 0;
        foreach ($fixtures as $fixture) {
            if (!$fixture instanceof BenefitServiceFixture) {
                return UniversityBenefitPreviewResult::blocked('invalid_fixture_input');
            }
            if (!$fixture->organizationId->equals($selectedOrganization)) {
                // Reject the whole batch before displaying ANY partial count.
                return UniversityBenefitPreviewResult::blocked('foreign_organization');
            }
            if (isset($seen[$fixture->serviceReference])) {
                return UniversityBenefitPreviewResult::blocked('duplicate_reference');
            }
            $seen[$fixture->serviceReference] = true;
            if ($fixture->isHypotheticalCandidate()) {
                $candidates++;
            }
        }

        return UniversityBenefitPreviewResult::hypothetical($candidates);
    }
}
