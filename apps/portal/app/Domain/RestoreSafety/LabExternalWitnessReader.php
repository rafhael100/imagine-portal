<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;

/**
 * Test-only abstraction. No network, database or Cloudflare implementation
 * is provided or bound to any real application service.
 */
interface LabExternalWitnessReader
{
    public function observe(OrganizationId $organization): ?LabExternalWitnessFloor;
}
