<?php

namespace App\Domain\PortalAccess\Authority;

use App\Domain\Organizations\OrganizationId;

/**
 * Future server-side resource owner resolver; no database/HTTP adapter in F1.
 * A request's organization ID is a lookup intention, never proof of ownership.
 */
interface PortalResourceAuthority
{
    public function ownerOf(OrganizationId $selectedOrganization, string $opaqueResourceReference): ?OrganizationId;
}
