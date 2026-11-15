<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

/**
 * Future server-side contract/finance provider.
 *
 * null = unavailable or unverified; [] = verified no service records.
 * It MUST bind the contract to the selected organization in the
 * authoritative source. Candidate resource claims are not eligible input.
 *
 * There is deliberately no production adapter yet.
 */
interface UniversityContractAuthority
{
    /** @return list<UniversityContractSnapshot>|null */
    public function currentFor(OrganizationId $organization): ?array;
}
