<?php

namespace App\Domain\PortalAccess\Authority;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Organizations\OrganizationId;
use Throwable;

/**
 * F1 non-operational composition boundary; no middleware, HTTP routes or
 * concrete trusted adapters. Input actor/grant/resource-owner fields are never
 * accepted from a request. All collaborators remain abstract and unverified.
 *
 * IMPORTANT: Even when synthetic inputs pass, the returned verdict CANNOT
 * authorize HTTP, issue a session, read protected data or grant a capability.
 * Real security requires a separately approved issuer, transactional
 * authority, revocation barriers, PostgreSQL/RLS and AppSec evidence.
 */
final readonly class PortalAuthorityBoundary
{
    public function __construct(
        private PortalSessionAuthority $sessions,
        private PortalMembershipAuthority $memberships,
        private PortalResourceAuthority $resources,
        private OrganizationAuthorizationService $policy = new OrganizationAuthorizationService(),
    ) {
    }

    public function inspect(
        ?string $opaqueSessionReference,
        ?OrganizationId $selectedOrganization,
        ?string $opaqueResourceReference,
        string $requestedPermission,
        int $now,
    ): PortalBoundaryVerdict {
        if ($opaqueSessionReference === null
            || $opaqueSessionReference === ''
            || strlen($opaqueSessionReference) > 512
            || $opaqueResourceReference === null
            || $opaqueResourceReference === ''
            || strlen($opaqueResourceReference) > 512
            || $selectedOrganization === null
            || $now <= 0) {
            return PortalBoundaryVerdict::blocked();
        }

        try {
            $session = $this->sessions->find($opaqueSessionReference);

            if ($session === null
                || ! $session->passesSyntheticFreshnessChecks($now)
                // F152: even a multi-company user cannot reuse an org-A
                // session to inspect an org-B resource. Check before any
                // membership/resource lookup and always fail closed.
                || ! $session->sessionOrganizationId->equals($selectedOrganization)) {
                return PortalBoundaryVerdict::blocked();
            }

            $membership = $this->memberships->findFor(
                $session->actorId,
                $selectedOrganization,
            );

            // F153: a session issued before a role/grant/membership change
            // must be rejected, even when the same actor's membership is
            // active now. Check the trusted current revision before
            // resolving resource ownership or making a policy decision.
            if ($membership === null
                || $membership->membershipRevision !== $session->sessionMembershipRevision) {
                return PortalBoundaryVerdict::blocked();
            }

            $resourceOrganization = $this->resources->ownerOf(
                $selectedOrganization,
                $opaqueResourceReference,
            );

            $decision = $this->policy->decide(
                new AuthorizationContext(
                    $session->actorId,
                    $selectedOrganization,
                    $resourceOrganization,
                    $requestedPermission,
                ),
                $membership,
            );

            return $decision->allowed
                ? PortalBoundaryVerdict::syntheticCandidate()
                : PortalBoundaryVerdict::blocked();
        } catch (Throwable) {
            // No error type, user existence, tenant or provider detail escapes.
            return PortalBoundaryVerdict::blocked();
        }
    }
}
