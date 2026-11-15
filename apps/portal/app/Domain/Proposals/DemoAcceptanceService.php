<?php

namespace App\Domain\Proposals;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;

/**
 * Pure, fail-closed demonstration acknowledgment. A future trusted backend
 * must verify actor/membership provenance, identity, freshness, audit and
 * idempotency. This service performs NO persistence or external side effects.
 */
final readonly class DemoAcceptanceService
{
    public function acknowledge(
        DemoProposalSnapshot $proposal,
        ?PortalUserId $actor,
        ?OrganizationId $selectedOrganization,
        ?OrganizationMembership $membership,
        string $shownFingerprint,
        string $shownDemoTermsVersion,
        bool $expressAcknowledgement,
    ): ?DemoAcceptance {
        if (! $expressAcknowledgement
            || $actor === null
            || $selectedOrganization === null
            || ! $proposal->organizationId->equals($selectedOrganization)
            || ! hash_equals($proposal->fingerprint(), $shownFingerprint)
            || ! hash_equals($proposal->demoTermsVersion, $shownDemoTermsVersion)) {
            return null;
        }

        $decision = (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext(
                $actor,
                $selectedOrganization,
                $proposal->organizationId,
                Permission::ManageServices->value,
            ),
            $membership,
        );

        if (! $decision->allowed) {
            return null;
        }

        return new DemoAcceptance(
            $proposal->id,
            $proposal->organizationId,
            $actor,
            $proposal->fingerprint(),
            $proposal->demoTermsVersion,
        );
    }
}
