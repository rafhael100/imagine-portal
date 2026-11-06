<?php

namespace Tests\Unit;

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
use App\Domain\PerformanceOnboarding\DemoAdAccount;
use App\Domain\PerformanceOnboarding\DemoBusinessGoal;
use App\Domain\PerformanceOnboarding\DemoOnboardingFactory;
use App\Domain\PerformanceOnboarding\DemoOnboardingId;
use App\Domain\PerformanceOnboarding\DemoOnboardingStatus;
use App\Domain\Proposals\DemoAcceptanceService;
use App\Domain\Proposals\DemoProposalId;
use App\Domain\Proposals\DemoProposalSimulator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoPerformanceOnboardingTest extends TestCase
{
    private function member(
        string $actor = 'actor-a',
        string $org = 'org-a',
        MembershipStatus $status = MembershipStatus::Active,
        array $permissions = [Permission::ManageServices],
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor), new OrganizationId($org),
            OrganizationRole::Member, $status, $permissions,
        );
    }

    private function scenario(
        string $org = 'org-a',
        PerformanceModality $modality = PerformanceModality::SingleChannel,
        array $channels = [PerformanceChannel::GoogleAds],
    ): array {
        $offering = new PerformanceOffering(
            new OfferingId('offer-demo'), ProductCode::PerformanceAI, 'v0.1',
            'Demo sem oferta comercial', OfferingStatus::NotForSale, $modality, $channels,
        );
        $proposal = (new DemoProposalSimulator())->present(
            new DemoProposalId('proposal-a'), new OrganizationId($org), $offering, 'demo-v0.1',
        );
        $actor = new PortalUserId('actor-a');
        $member = $this->member(org: $org);
        $ack = (new DemoAcceptanceService())->acknowledge(
            $proposal, $actor, new OrganizationId($org), $member,
            $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        );
        self::assertNotNull($ack);
        return [$proposal, $ack, $actor, $member];
    }

    private function draft(
        array $scenario,
        ?PortalUserId $actor = null,
        ?OrganizationId $org = null,
        ?OrganizationMembership $member = null,
        ?array $accounts = null,
    ) {
        [$proposal, $ack, $originalActor, $originalMember] = $scenario;
        return (new DemoOnboardingFactory())->create(
            new DemoOnboardingId('demo_onboard-a'), $proposal, $ack,
            $actor ?? $originalActor, $org ?? $proposal->organizationId,
            $member ?? $originalMember, DemoBusinessGoal::LeadGeneration,
            $accounts ?? [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_google_account_a')],
        );
    }

    public function test_valid_demo_onboarding_never_connects_an_account_or_provisions_service(): void
    {
        $draft = $this->draft($this->scenario());
        self::assertNotNull($draft);
        self::assertSame(DemoOnboardingStatus::AwaitingMockReview, $draft->status);
        self::assertSame(DemoBusinessGoal::LeadGeneration, $draft->goal);
        self::assertFalse($draft->isProvisioned());
        self::assertFalse($draft->isExternallyConnected());
        self::assertTrue((new \ReflectionClass($draft))->isReadOnly());
    }

    public function test_missing_or_unmatched_explicit_acknowledgement_denies_onboarding(): void
    {
        [$proposal, $ack, $actor, $member] = $this->scenario();
        $factory = new DemoOnboardingFactory();
        $foreign = $this->scenario(org: 'org-b')[1];
        self::assertNull($factory->create(
            new DemoOnboardingId('demo_onboard-a'), $proposal, $foreign,
            $actor, new OrganizationId('org-a'), $member, DemoBusinessGoal::Sales,
            [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g1')],
        ));
        self::assertNull($factory->create(
            new DemoOnboardingId('demo_onboard-a'), $proposal, $ack,
            new PortalUserId('other'), new OrganizationId('org-a'), $member,
            DemoBusinessGoal::Sales, [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g1')],
        ));
    }

    public function test_cross_tenant_actor_and_selected_organization_are_denied(): void
    {
        [$proposal, $ack, $actor, $member] = $this->scenario();
        $factory = new DemoOnboardingFactory();
        self::assertNull($factory->create(
            new DemoOnboardingId('demo_onboard-a'), $proposal, $ack,
            $actor, new OrganizationId('org-b'), $member, DemoBusinessGoal::Sales,
            [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g1')],
        ));
        self::assertNull($factory->create(
            new DemoOnboardingId('demo_onboard-a'), $proposal, $ack,
            $actor, new OrganizationId('org-a'), $this->member(org: 'org-b'),
            DemoBusinessGoal::Sales, [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g1')],
        ));
    }

    public function test_revoked_or_view_only_memberships_are_denied(): void
    {
        [$proposal, $ack, $actor] = $this->scenario();
        foreach ([MembershipStatus::Invited, MembershipStatus::Revoked, MembershipStatus::Suspended] as $status) {
            self::assertNull((new DemoOnboardingFactory())->create(
                new DemoOnboardingId('demo_onboard-a'), $proposal, $ack, $actor,
                new OrganizationId('org-a'), $this->member(status: $status),
                DemoBusinessGoal::Sales, [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g1')],
            ));
        }
        self::assertNull((new DemoOnboardingFactory())->create(
            new DemoOnboardingId('demo_onboard-a'), $proposal, $ack, $actor,
            new OrganizationId('org-a'), $this->member(permissions: [Permission::ViewServices]),
            DemoBusinessGoal::Sales, [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g1')],
        ));
    }

    public function test_missing_actor_org_or_membership_fail_closed(): void
    {
        [$proposal, $ack, $actor, $member] = $this->scenario();
        $f = new DemoOnboardingFactory();
        $args = [new DemoOnboardingId('demo_onboard-a'), $proposal, $ack];
        $accounts = [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g1')];
        self::assertNull($f->create(...[...$args, null, new OrganizationId('org-a'), $member, DemoBusinessGoal::Sales, $accounts]));
        self::assertNull($f->create(...[...$args, $actor, null, $member, DemoBusinessGoal::Sales, $accounts]));
        self::assertNull($f->create(...[...$args, $actor, new OrganizationId('org-a'), null, DemoBusinessGoal::Sales, $accounts]));
    }

    public function test_single_channel_cannot_receive_another_channel_or_two_accounts(): void
    {
        $s = $this->scenario();
        self::assertNull($this->draft($s, accounts: [
            new DemoAdAccount(PerformanceChannel::MetaAds, 'demo_meta_account'),
        ]));
        self::assertNull($this->draft($s, accounts: [
            new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_google_a'),
            new DemoAdAccount(PerformanceChannel::MetaAds, 'demo_meta_a'),
        ]));
    }

    public function test_multi_channel_requires_one_synthetic_account_for_each_channel(): void
    {
        $s = $this->scenario(modality: PerformanceModality::Multichannel, channels: [
            PerformanceChannel::MetaAds, PerformanceChannel::GoogleAds,
        ]);
        self::assertNull($this->draft($s));
        self::assertNull($this->draft($s, accounts: [
            new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g1'),
            new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_g2'),
        ]));
        $draft = $this->draft($s, accounts: [
            new DemoAdAccount(PerformanceChannel::MetaAds, 'demo_meta_a'),
            new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_google_a'),
        ]);
        self::assertNotNull($draft);
        self::assertCount(2, $draft->adAccounts);
    }

    public function test_real_account_reference_is_rejected_by_constructor(): void
    {
        foreach (['123-456-7890', 'act_123', 'oauth-secret', 'gclid-123', ''] as $ref) {
            try {
                new DemoAdAccount(PerformanceChannel::GoogleAds, $ref);
                self::fail('Real-looking identifiers must fail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_onboarding_id_and_goal_vocabulary_are_synthetic_only(): void
    {
        self::assertNull(DemoBusinessGoal::tryFrom('sales'));
        self::assertNull(DemoOnboardingStatus::tryFrom('active'));
        $this->expectException(InvalidArgumentException::class);
        new DemoOnboardingId('real_tenant_1');
    }
}
