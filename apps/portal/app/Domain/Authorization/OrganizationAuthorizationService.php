<?php

namespace App\Domain\Authorization;

use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationMembership;

/**
 * Pure authorization domain evaluator. Fail-closed and without I/O.
 *
 * The caller must resolve a membership from a trusted future backend.
 * This service does not query storage, trust client-supplied tenant IDs,
 * create CRM access or publish a production authorization endpoint.
 */
final readonly class OrganizationAuthorizationService
{
    public function decide(
        AuthorizationContext $context,
        ?OrganizationMembership $membership,
    ): AuthorizationDecision {
        if ($context->actorId === null
            || $context->organizationId === null
            || $context->resourceOrganizationId === null) {
            return AuthorizationDecision::deny();
        }

        $permission = Permission::tryFrom($context->requestedPermission);

        if ($permission === null
            || ! $context->organizationId->equals($context->resourceOrganizationId)
            || $membership === null
            || ! $membership->actorId->equals($context->actorId)
            || ! $membership->organizationId->equals($context->organizationId)
            || $membership->status !== MembershipStatus::Active
            || ! $membership->hasPermission($permission)) {
            return AuthorizationDecision::deny();
        }

        return AuthorizationDecision::allow();
    }
}
