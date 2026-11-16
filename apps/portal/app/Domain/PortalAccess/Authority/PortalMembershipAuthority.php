<?php

namespace App\Domain\PortalAccess\Authority;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;

/**
 * Future trusted membership, role and grant resolver.
 *
 * In production this must enforce fresh authoritative state and transaction
 * barriers; a caller-constructed OrganizationMembership proves NOTHING.
 */
interface PortalMembershipAuthority
{
    public function findFor(
        PortalUserId $actor,
        OrganizationId $organization,
    ): ?OrganizationMembership;
}
