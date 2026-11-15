<?php

namespace App\Domain\Authorization;

use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationMembership;

/**
 * Pure authorization domain evaluator. Fail-closed and without I/O.
 *
 * SECURITY BOUNDARY (NOT IMPLEMENTED HERE): The caller must obtain actor
 * identity from the Portal's own authenticated session, and resolve the
 * membership, permissions and resource owner from trusted server-side
 * authorities, with freshness/revocation checks. Never construct these
 * values from client-supplied actor, organization, role or permissions.
 *
 * This pure service checks equality and an explicit active grant only;
 * it cannot validate provenance, an issuer, token, organization ownership,
 * a trusted membership version, concurrent revocation or a CRM identity.
 * It does not read storage, perform I/O or expose an authorization endpoint.
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
