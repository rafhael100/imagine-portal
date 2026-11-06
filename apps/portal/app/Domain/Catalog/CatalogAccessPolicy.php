<?php

namespace App\Domain\Catalog;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;

/**
 * Pure illustrative access evaluation, reusing the Portal's fail-closed
 * organization permissions. Inputs need trusted server-side provenance in
 * later phases; not an API, authentication system or tenant resolver.
 */
final readonly class CatalogAccessPolicy
{
    public function canView(
        ?PortalUserId $actor,
        ?OrganizationId $selectedOrganization,
        ?OrganizationId $resourceOwner,
        ?OrganizationMembership $membership,
    ): bool {
        return (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext(
                $actor,
                $selectedOrganization,
                $resourceOwner,
                Permission::ViewServices->value,
            ),
            $membership,
        )->allowed;
    }
}
