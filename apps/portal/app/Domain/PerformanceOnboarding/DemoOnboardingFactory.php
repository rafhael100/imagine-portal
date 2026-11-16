<?php

namespace App\Domain\PerformanceOnboarding;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Proposals\DemoAcceptance;
use App\Domain\Proposals\DemoProposalSnapshot;

/**
 * Pure, fail-closed demonstration intake. All inputs are synthetic.
 * Identity and membership provenance cannot be established in this phase.
 */
final readonly class DemoOnboardingFactory
{
    /** @param list<DemoAdAccount> $accounts */
    public function create(
        DemoOnboardingId $id,
        DemoProposalSnapshot $proposal,
        DemoAcceptance $acknowledgement,
        ?PortalUserId $actor,
        ?OrganizationId $selectedOrganization,
        ?OrganizationMembership $membership,
        DemoBusinessGoal $goal,
        array $accounts,
    ): ?DemoOnboardingDraft {
        if ($actor === null || $selectedOrganization === null
            || ! $proposal->organizationId->equals($selectedOrganization)
            || ! $acknowledgement->organizationId->equals($proposal->organizationId)
            || ! $acknowledgement->proposalId->equals($proposal->id)
            || ! $acknowledgement->acknowledgingActorId->equals($actor)
            || ! hash_equals($proposal->fingerprint(), $acknowledgement->presentedFingerprint)
            || ! hash_equals($proposal->demoTermsVersion, $acknowledgement->acknowledgedDemoTermsVersion)) {
            return null;
        }

        $authorized = (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext(
                $actor, $selectedOrganization, $proposal->organizationId, Permission::ManageServices->value,
            ),
            $membership,
        );

        if (! $authorized->allowed || count($accounts) !== count($proposal->channels)) {
            return null;
        }

        $allowedChannels = [];
        foreach ($proposal->channels as $channel) {
            if (! $channel instanceof PerformanceChannel) {
                return null;
            }
            $allowedChannels[$channel->value] = true;
        }

        $seenChannels = [];
        foreach ($accounts as $account) {
            if (! $account instanceof DemoAdAccount
                || ! isset($allowedChannels[$account->channel->value])
                || isset($seenChannels[$account->channel->value])) {
                return null;
            }
            $seenChannels[$account->channel->value] = true;
        }

        return new DemoOnboardingDraft(
            $id, $proposal->organizationId, $proposal->id, $proposal->fingerprint(),
            $actor, $goal, $accounts,
        );
    }
}
