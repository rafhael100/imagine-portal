<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

/** FUTURE official and independently approved source; default unavailable. */
interface UniversityCommercialPolicyAuthority
{
    public function currentFor(OrganizationId $organization): ?UniversityCommercialPolicySnapshot;
}
