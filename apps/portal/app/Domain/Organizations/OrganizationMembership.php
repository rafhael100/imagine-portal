<?php

namespace App\Domain\Organizations;

use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use InvalidArgumentException;

/**
 * An explicit actor <-> organization membership. Every permission is scoped
 * to this one organization; role alone does not imply any grant.
 */
final readonly class OrganizationMembership
{
    /** @param list<Permission> $permissions */
    public function __construct(
        public PortalUserId $actorId,
        public OrganizationId $organizationId,
        public OrganizationRole $role,
        public MembershipStatus $status,
        public array $permissions = [],
    ) {
        foreach ($permissions as $permission) {
            if (! $permission instanceof Permission) {
                throw new InvalidArgumentException('Membership permissions must be Permission values.');
            }
        }
    }

    public function hasPermission(Permission $requested): bool
    {
        return in_array($requested, $this->permissions, true);
    }
}
