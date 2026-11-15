<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

/** Default deny-all; no official lifecycle provider has been homologated. */
final class UnavailableUniversityServiceRevisionAuthority implements UniversityServiceRevisionAuthority
{
    public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot
    {
        return null;
    }
}
