<?php
namespace App\Domain\PortalAccess;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;

/**
 * Test-only rules for future session/MFA/tenant navigation.
 * The sample 600-second MFA freshness window is NOT an approved policy.
 * Never mount this as real middleware: actors and grants lack trusted issuer,
 * persisted revocation, session cryptography, anti-abuse and tenant RLS.
 */
final readonly class DemoNavigationPolicy
{
    public function inspect(
        ?DemoPortalSession $session,
        ?OrganizationId $selectedOrg,
        ?OrganizationId $ownerOrg,
        ?OrganizationMembership $membership,
        int $now,
    ): DemoNavigationVerdict {
        if ($session === null || $selectedOrg === null || $ownerOrg === null) {
            return DemoNavigationVerdict::blocked(DemoAccessBlocker::Missing);
        }
        if ($now <= 0 || $session->issuedAt > $now) {
            return DemoNavigationVerdict::blocked(DemoAccessBlocker::TimeInvalid);
        }
        if ($session->state !== DemoPortalSessionState::Active) {
            return DemoNavigationVerdict::blocked(DemoAccessBlocker::Inactive);
        }
        if ($session->expiresAt <= $now) {
            return DemoNavigationVerdict::blocked(DemoAccessBlocker::Expired);
        }
        if (! $session->organization->equals($selectedOrg) || ! $ownerOrg->equals($selectedOrg)) {
            return DemoNavigationVerdict::blocked(DemoAccessBlocker::Organization);
        }
        if ($session->mfaAt === null) {
            return DemoNavigationVerdict::blocked(DemoAccessBlocker::MfaRequired);
        }
        if ($session->mfaAt < $session->issuedAt || $session->mfaAt > $now || $now - $session->mfaAt > 600) {
            return DemoNavigationVerdict::blocked(DemoAccessBlocker::MfaStale);
        }

        $allowed = (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext($session->actor, $selectedOrg, $ownerOrg, Permission::ViewServices->value),
            $membership,
        )->allowed;

        return $allowed
            ? DemoNavigationVerdict::preview()
            : DemoNavigationVerdict::blocked(DemoAccessBlocker::Permission);
    }
}
