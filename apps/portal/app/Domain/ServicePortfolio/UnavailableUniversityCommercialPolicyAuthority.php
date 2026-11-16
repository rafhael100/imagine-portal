<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

final readonly class UnavailableUniversityCommercialPolicyAuthority implements UniversityCommercialPolicyAuthority
{
    public function currentFor(OrganizationId $organization): ?UniversityCommercialPolicySnapshot
    {
        return null;
    }
}
