<?php

namespace App\Domain\Organizations;

use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use InvalidArgumentException;

/**
 * An in-memory actor <-> organization membership representation.
 * Every permission is scoped to one organization; role alone grants nothing.
 *
 * Instantiation is NOT evidence of an actual authorized membership.
 * Only a future trusted server-side authority may supply verified, current
 * memberships to the evaluator; never hydrate from untrusted HTTP payloads.
 * This B-A object has no database, signature, issuer or revocation clock.
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
        // Current authoritative revision from portal_memberships, not a
        // revision claimed by a browser, CRM or downstream service.
        public int $membershipRevision = 1,
    ) {
        if ($membershipRevision <= 0) {
            throw new InvalidArgumentException('Membership revision must be positive.');
        }
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
