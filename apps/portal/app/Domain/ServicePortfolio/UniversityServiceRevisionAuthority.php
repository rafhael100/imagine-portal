<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;

/**
 * Future independently trusted, server-only revocation/revision source.
 * null means unavailable: NEVER infer that no revocation occurred.
 */
interface UniversityServiceRevisionAuthority
{
    public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot;
}
