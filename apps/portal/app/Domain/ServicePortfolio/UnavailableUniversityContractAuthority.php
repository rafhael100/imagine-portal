<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

/** Fail-closed until an independently audited active-contract source exists. */
final class UnavailableUniversityContractAuthority implements UniversityContractAuthority
{
    public function currentFor(OrganizationId $organization): ?array
    {
        return null;
    }
}
