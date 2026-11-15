<?php

namespace App\Domain\PerformanceDashboard;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\PerformanceOnboarding\DemoAdAccount;
use App\Domain\PerformanceOnboarding\DemoOnboardingDraft;
use App\Domain\PerformanceOnboarding\DemoOnboardingStatus;
use App\Domain\Proposals\DemoProposalSnapshot;

/**
 * Pure test-only projection builder. Verifies context even for in-memory
 * inputs. Does not authenticate an actor or authorize HTTP: actual identity,
 * resource ownership and current memberships need trusted backend sources.
 */
final readonly class DemoDashboardFactory
{
    /**
     * @param list<DemoMetric> $metrics
     * @param list<DemoCampaignCard> $campaigns
     * @param list<DemoRequestCard> $requests
     */
    public function build(
        DemoProposalSnapshot $proposal,
        DemoOnboardingDraft $onboarding,
        ?PortalUserId $actor,
        ?OrganizationId $selectedOrganization,
        ?OrganizationMembership $membership,
        array $metrics,
        array $campaigns,
        array $requests,
    ): ?DemoDashboardSnapshot {
        if ($actor === null || $selectedOrganization === null
            || ! $proposal->organizationId->equals($selectedOrganization)
            || ! $onboarding->organizationId->equals($selectedOrganization)
            || ! $onboarding->proposalId->equals($proposal->id)
            || ! hash_equals($proposal->fingerprint(), $onboarding->proposalFingerprint)
            || $onboarding->status !== DemoOnboardingStatus::AwaitingMockReview
            || $onboarding->isProvisioned()
            || $onboarding->isExternallyConnected()
            || $proposal->isCheckoutEligible()
            || count($metrics) > 3 || count($campaigns) > 10 || count($requests) > 10) {
            return null;
        }

        $decision = (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext(
                $actor, $selectedOrganization, $proposal->organizationId,
                Permission::ViewServices->value,
            ),
            $membership,
        );

        if (! $decision->allowed) {
            return null;
        }

        $channels = [];
        foreach ($proposal->channels as $channel) {
            if (! $channel instanceof PerformanceChannel
                || isset($channels[$channel->value])) {
                return null;
            }
            $channels[$channel->value] = true;
        }

        // A manually constructed DTO must not bypass P02-C channel selection.
        if (count($onboarding->adAccounts) !== count($channels)) {
            return null;
        }

        $onboardingChannels = [];
        foreach ($onboarding->adAccounts as $account) {
            if (! $account instanceof DemoAdAccount
                || ! isset($channels[$account->channel->value])
                || isset($onboardingChannels[$account->channel->value])) {
                return null;
            }
            $onboardingChannels[$account->channel->value] = true;
        }

        $metricNames = [];
        foreach ($metrics as $metric) {
            if (! $metric instanceof DemoMetric || isset($metricNames[$metric->key->value])) {
                return null;
            }
            $metricNames[$metric->key->value] = true;
        }

        $campaignIds = [];
        foreach ($campaigns as $campaign) {
            if (! $campaign instanceof DemoCampaignCard
                || ! $campaign->organizationId->equals($selectedOrganization)
                || ! isset($channels[$campaign->channel->value])
                || isset($campaignIds[$campaign->id])) {
                return null;
            }
            $campaignIds[$campaign->id] = true;
        }

        $requestIds = [];
        foreach ($requests as $request) {
            if (! $request instanceof DemoRequestCard
                || ! $request->organizationId->equals($selectedOrganization)
                || isset($requestIds[$request->id])) {
                return null;
            }
            $requestIds[$request->id] = true;
        }

        return new DemoDashboardSnapshot(
            $selectedOrganization, $proposal, $onboarding,
            $metrics, $campaigns, $requests,
        );
    }
}
