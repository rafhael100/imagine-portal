<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

/** @return list<UniversityServiceCoverageSnapshot>|null (future source only) */
interface UniversityServiceCoverageAuthority
{
    /** @return list<UniversityServiceCoverageSnapshot>|null */
    public function currentFor(OrganizationId $organization): ?array;
}
