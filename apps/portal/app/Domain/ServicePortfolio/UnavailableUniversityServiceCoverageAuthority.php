<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

final readonly class UnavailableUniversityServiceCoverageAuthority implements UniversityServiceCoverageAuthority
{
    public function currentFor(OrganizationId $organization): ?array
    {
        return null;
    }
}
