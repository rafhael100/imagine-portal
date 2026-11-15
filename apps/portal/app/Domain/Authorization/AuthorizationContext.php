<?php

namespace App\Domain\Authorization;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;

/**
 * Explicit request-scoped information. All identities must come from
 * future trusted server-side resolvers; this object does not authenticate.
 */
final readonly class AuthorizationContext
{
    public function __construct(
        public ?PortalUserId $actorId,
        public ?OrganizationId $organizationId,
        public ?OrganizationId $resourceOrganizationId,
        public string $requestedPermission,
    ) {
    }
}
