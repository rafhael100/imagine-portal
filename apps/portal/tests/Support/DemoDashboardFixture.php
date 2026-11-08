<?php

namespace Tests\Support;

use App\Domain\Authorization\Permission;
use App\Domain\Catalog\OfferingId;
use App\Domain\Catalog\OfferingStatus;
use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Catalog\PerformanceModality;
use App\Domain\Catalog\PerformanceOffering;
use App\Domain\Catalog\ProductCode;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\PerformanceDashboard\DemoCampaignCard;
use App\Domain\PerformanceDashboard\DemoCampaignStage;
use App\Domain\PerformanceDashboard\DemoMetric;
use App\Domain\PerformanceDashboard\DemoMetricKey;
use App\Domain\PerformanceDashboard\DemoRequestCard;
use App\Domain\PerformanceDashboard\DemoRequestStage;
use App\Domain\PerformanceOnboarding\DemoAdAccount;
use App\Domain\PerformanceOnboarding\DemoBusinessGoal;
use App\Domain\PerformanceOnboarding\DemoOnboardingFactory;
use App\Domain\PerformanceOnboarding\DemoOnboardingId;
use App\Domain\Proposals\DemoAcceptanceService;
use App\Domain\Proposals\DemoProposalId;
use App\Domain\Proposals\DemoProposalSimulator;
use RuntimeException;

/** Synthetic unit/feature test fixture, never customer or advertiser data. */
final class DemoDashboardFixture
{
    public static function case(string $org = 'org-a'): array
    {
        $organization = new OrganizationId($org);
        $actor = new PortalUserId('demo-actor-a');
        $member = new OrganizationMembership(
            $actor, $organization, OrganizationRole::Member, MembershipStatus::Active,
            [Permission::ManageServices, Permission::ViewServices],
        );
        $offering = new PerformanceOffering(
            new OfferingId('demo-offer'), ProductCode::PerformanceAI, 'v0.1',
            'Plano demonstrativo', OfferingStatus::NotForSale,
            PerformanceModality::SingleChannel, [PerformanceChannel::GoogleAds],
        );
        $proposal = (new DemoProposalSimulator())->present(
            new DemoProposalId('demo-proposal'), $organization, $offering, 'demo-v0.1',
        );
        $ack = (new DemoAcceptanceService())->acknowledge(
            $proposal, $actor, $organization, $member,
            $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        );
        if ($ack === null) {
            throw new RuntimeException('Synthetic acknowledgement setup failed.');
        }
        $onboarding = (new DemoOnboardingFactory())->create(
            new DemoOnboardingId('demo_onboard-a'), $proposal, $ack,
            $actor, $organization, $member, DemoBusinessGoal::LeadGeneration,
            [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_google_a')],
        );
        if ($onboarding === null) {
            throw new RuntimeException('Synthetic onboarding setup failed.');
        }

        return [
            'organization' => $organization,
            'actor' => $actor,
            'member' => $member,
            'proposal' => $proposal,
            'onboarding' => $onboarding,
            'metrics' => [
                new DemoMetric(DemoMetricKey::Impressions, 1280),
                new DemoMetric(DemoMetricKey::Clicks, 34),
                new DemoMetric(DemoMetricKey::Leads, 3),
            ],
            'campaigns' => [
                new DemoCampaignCard('demo_campaign_one', $organization, PerformanceChannel::GoogleAds, 'Exemplo de campanha', DemoCampaignStage::MockReview),
            ],
            'requests' => [
                new DemoRequestCard('demo_request_one', $organization, 'Exemplo de aprovação', DemoRequestStage::AwaitingMockReview),
            ],
        ];
    }
}
