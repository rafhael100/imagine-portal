<?php

namespace App\Domain\PortalAccess\Authority;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;

/**
 * Fail-closed placeholder until official Portal sources are implemented.
 * Does not query a database, session, CRM, request or service.
 */
final class DenyingPortalAuthorities implements PortalSessionAuthority, PortalMembershipAuthority, PortalResourceAuthority
{
    public function find(string $opaqueReference): ?PortalSessionSnapshot
    {
        return null;
    }

    public function findFor(PortalUserId $actor, OrganizationId $organization): ?OrganizationMembership
    {
        return null;
    }

    public function ownerOf(OrganizationId $selectedOrganization, string $opaqueResourceReference): ?OrganizationId
    {
        return null;
    }
}
