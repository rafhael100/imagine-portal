<?php

declare(strict_types=1);

namespace App\Domain\PortalAccess\Authority;

use App\Domain\Organizations\OrganizationId;

/**
 * Future Portal-only organizational status/revision authority.
 * No trusted implementation is wired, and a caller-created snapshot grants
 * no capability. Never derive active/revision from a browser or CRM actor.
 */
interface PortalOrganizationAuthority
{
    public function lookup(OrganizationId $organization): ?PortalOrganizationSnapshot;
}
