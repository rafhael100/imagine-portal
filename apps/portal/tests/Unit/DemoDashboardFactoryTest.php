<?php

namespace Tests\Unit;

use App\Domain\Authorization\Permission;
use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\PerformanceDashboard\DemoCampaignCard;
use App\Domain\PerformanceDashboard\DemoCampaignStage;
use App\Domain\PerformanceDashboard\DemoDashboardFactory;
use App\Domain\PerformanceDashboard\DemoMetric;
use App\Domain\PerformanceDashboard\DemoMetricKey;
use App\Domain\PerformanceDashboard\DemoRequestCard;
use App\Domain\PerformanceDashboard\DemoRequestStage;
use App\Domain\PerformanceOnboarding\DemoAdAccount;
use App\Domain\PerformanceOnboarding\DemoBusinessGoal;
use App\Domain\PerformanceOnboarding\DemoOnboardingDraft;
use App\Domain\PerformanceOnboarding\DemoOnboardingId;
use App\Domain\PerformanceOnboarding\DemoOnboardingStatus;
use App\Domain\Proposals\DemoProposalId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\DemoDashboardFixture;

final class DemoDashboardFactoryTest extends TestCase
{
    private function build(array $f, array $overrides = [])
    {
        return (new DemoDashboardFactory())->build(
            $overrides['proposal'] ?? $f['proposal'],
            $overrides['onboarding'] ?? $f['onboarding'],
            array_key_exists('actor', $overrides) ? $overrides['actor'] : $f['actor'],
            array_key_exists('organization', $overrides) ? $overrides['organization'] : $f['organization'],
            array_key_exists('member', $overrides) ? $overrides['member'] : $f['member'],
            $overrides['metrics'] ?? $f['metrics'],
            $overrides['campaigns'] ?? $f['campaigns'],
            $overrides['requests'] ?? $f['requests'],
        );
    }

    public function test_valid_snapshot_is_explicitly_synthetic_read_only_and_unavailable_for_sale(): void
    {
        $dashboard = $this->build(DemoDashboardFixture::case());
        self::assertNotNull($dashboard);
        self::assertSame('synthetic_demo', $dashboard->source);
        self::assertFalse($dashboard->isRealTime());
        self::assertFalse($dashboard->canExecuteActions());
        self::assertFalse($dashboard->isForSale());
        self::assertTrue((new \ReflectionClass($dashboard))->isReadOnly());
        self::assertCount(3, $dashboard->metrics);
    }

    public function test_cross_organization_selected_context_is_denied(): void
    {
        $f = DemoDashboardFixture::case();
        self::assertNull($this->build($f, ['organization' => new OrganizationId('org-b')]));
        self::assertNull($this->build($f, ['member' => DemoDashboardFixture::case('org-b')['member']]));
    }

    public function test_missing_actor_organization_and_member_are_denied(): void
    {
        $f = DemoDashboardFixture::case();
        self::assertNull($this->build($f, ['actor' => null]));
        self::assertNull($this->build($f, ['organization' => null]));
        self::assertNull($this->build($f, ['member' => null]));
    }

    public function test_revoked_suspended_invited_and_unprivileged_members_are_denied(): void
    {
        $f = DemoDashboardFixture::case();
        foreach ([MembershipStatus::Revoked, MembershipStatus::Invited, MembershipStatus::Suspended] as $status) {
            $member = new OrganizationMembership($f['actor'], $f['organization'], OrganizationRole::Member, $status, [Permission::ViewServices]);
            self::assertNull($this->build($f, ['member' => $member]));
        }
        $viewerWithoutGrant = new OrganizationMembership(
            $f['actor'], $f['organization'], OrganizationRole::Member,
            MembershipStatus::Active, [Permission::ManageServices],
        );
        self::assertNull($this->build($f, ['member' => $viewerWithoutGrant]));
    }

    public function test_onboarding_must_match_proposal_id_organization_and_fingerprint(): void
    {
        $f = DemoDashboardFixture::case();
        $o = $f['onboarding'];
        $foreign = new DemoOnboardingDraft($o->id, new OrganizationId('org-b'), $o->proposalId, $o->proposalFingerprint,
            $o->requestingActorId, $o->goal, $o->adAccounts);
        self::assertNull($this->build($f, ['onboarding' => $foreign]));
        $wrongProposal = new DemoOnboardingDraft($o->id, $o->organizationId, new DemoProposalId('other'),
            $o->proposalFingerprint, $o->requestingActorId, $o->goal, $o->adAccounts);
        self::assertNull($this->build($f, ['onboarding' => $wrongProposal]));
        $wrongHash = new DemoOnboardingDraft($o->id, $o->organizationId, $o->proposalId,
            str_repeat('0', 64), $o->requestingActorId, $o->goal, $o->adAccounts);
        self::assertNull($this->build($f, ['onboarding' => $wrongHash]));
    }

    public function test_forged_channel_list_is_rejected_even_when_dto_constructed_directly(): void
    {
        $f = DemoDashboardFixture::case();
        $o = $f['onboarding'];
        $extraChannel = new DemoOnboardingDraft($o->id, $o->organizationId, $o->proposalId, $o->proposalFingerprint,
            $o->requestingActorId, DemoBusinessGoal::LeadGeneration,
            [new DemoAdAccount(PerformanceChannel::MetaAds, 'demo_meta_b')],
            DemoOnboardingStatus::AwaitingMockReview);
        self::assertNull($this->build($f, ['onboarding' => $extraChannel]));
    }

    public function test_foreign_campaign_and_foreign_approval_request_are_denied(): void
    {
        $f = DemoDashboardFixture::case();
        $foreign = new OrganizationId('org-b');
        $card = new DemoCampaignCard('demo_campaign_foreign', $foreign, PerformanceChannel::GoogleAds, 'Exemplo estrangeiro');
        $request = new DemoRequestCard('demo_request_foreign', $foreign, 'Aprovação estrangeira', DemoRequestStage::RequestedMock);
        self::assertNull($this->build($f, ['campaigns' => [$card]]));
        self::assertNull($this->build($f, ['requests' => [$request]]));
    }

    public function test_foreign_channel_and_duplicate_cards_are_denied(): void
    {
        $f = DemoDashboardFixture::case();
        $wrongChannel = new DemoCampaignCard('demo_campaign_meta', $f['organization'], PerformanceChannel::MetaAds, 'Canal não selecionado');
        self::assertNull($this->build($f, ['campaigns' => [$wrongChannel]]));
        self::assertNull($this->build($f, ['campaigns' => [$f['campaigns'][0], $f['campaigns'][0]]]));
        self::assertNull($this->build($f, ['requests' => [$f['requests'][0], $f['requests'][0]]]));
        self::assertNull($this->build($f, ['metrics' => [$f['metrics'][0], $f['metrics'][0]]]));
    }

    public function test_untyped_data_and_oversized_inputs_fail_closed(): void
    {
        $f = DemoDashboardFixture::case();
        self::assertNull($this->build($f, ['campaigns' => ['fake']]));
        self::assertNull($this->build($f, ['requests' => ['fake']]));
        self::assertNull($this->build($f, ['metrics' => ['fake']]));
        self::assertNull($this->build($f, ['campaigns' => array_fill(0, 11, $f['campaigns'][0])]));
        self::assertNull($this->build($f, ['requests' => array_fill(0, 11, $f['requests'][0])]));
    }

    public function test_synthetic_indicators_and_ids_reject_invalid_values(): void
    {
        self::assertNull(DemoMetricKey::tryFrom('real_revenue'));
        self::assertNull(DemoCampaignStage::tryFrom('active'));
        self::assertNull(DemoRequestStage::tryFrom('approved'));
        foreach ([-1, 1000001] as $value) {
            try {
                new DemoMetric(DemoMetricKey::Clicks, $value);
                self::fail('Invalid synthetic metric was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new DemoCampaignCard('real_ads_campaign', new OrganizationId('org-a'), PerformanceChannel::GoogleAds, 'Campanha real');
    }

    public function test_multiple_readers_may_view_same_demo_only_with_separate_active_permissions(): void
    {
        $f = DemoDashboardFixture::case();
        $viewer = new PortalUserId('demo-viewer-b');
        $member = new OrganizationMembership($viewer, $f['organization'], OrganizationRole::Member,
            MembershipStatus::Active, [Permission::ViewServices]);
        self::assertNotNull($this->build($f, ['actor' => $viewer, 'member' => $member]));
        self::assertNull($this->build($f, ['actor' => new PortalUserId('not-member'), 'member' => $member]));
    }
}
