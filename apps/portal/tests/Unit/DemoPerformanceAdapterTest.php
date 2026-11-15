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
use App\Domain\PerformanceOnboarding\DemoPerformanceEvent;
use App\Domain\PerformanceOnboarding\InMemoryPerformanceMockAdapter;
use App\Domain\Proposals\DemoAcceptanceService;
use App\Domain\Proposals\DemoProposalId;
use App\Domain\Proposals\DemoProposalSimulator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoPerformanceAdapterTest extends TestCase
{
    private function member(string $org = 'org-a', MembershipStatus $status = MembershipStatus::Active): OrganizationMembership
    {
        return new OrganizationMembership(
            new PortalUserId('actor-a'), new OrganizationId($org),
            OrganizationRole::Member, $status, [Permission::ManageServices],
        );
    }

    private function case(string $org = 'org-a'): array
    {
        $offering = new PerformanceOffering(
            new OfferingId('demo-offer'), ProductCode::PerformanceAI, 'v0.1',
            'Demo account', OfferingStatus::NotForSale,
            PerformanceModality::SingleChannel, [PerformanceChannel::GoogleAds],
        );
        $proposal = (new DemoProposalSimulator())->present(
            new DemoProposalId('demo-proposal'), new OrganizationId($org), $offering, 'demo-v0.1',
        );
        $actor = new PortalUserId('actor-a');
        $member = $this->member($org);
        $ack = (new DemoAcceptanceService())->acknowledge(
            $proposal, $actor, new OrganizationId($org),
            $member, $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        );
        self::assertNotNull($ack);
        $draft = (new DemoOnboardingFactory())->create(
            new DemoOnboardingId('demo_onboard-a'), $proposal, $ack,
            $actor, new OrganizationId($org), $member, DemoBusinessGoal::LeadGeneration,
            [new DemoAdAccount(PerformanceChannel::GoogleAds, 'demo_google_a')],
        );
        self::assertNotNull($draft);
        $event = new DemoPerformanceEvent(
            'evt-demo-001', 'corr-demo-001', new OrganizationId($org),
            $draft->id, $draft->proposalFingerprint,
        );
        return [$draft, $event, $actor, $member];
    }

    public function test_synthetic_event_returns_only_review_receipt_never_provisions_or_marks_paid(): void
    {
        [$draft, $event, $actor, $member] = $this->case();
        $adapter = new InMemoryPerformanceMockAdapter();
        $receipt = $adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $member);
        self::assertNotNull($receipt);
        self::assertSame(DemoOnboardingStatus::AwaitingMockReview, $receipt->status);
        self::assertSame('synthetic_demo', $receipt->source);
        self::assertNull($receipt->performanceTenantId);
        self::assertNull($receipt->activatedServiceId);
        self::assertFalse($receipt->isProvisioned());
        self::assertFalse($receipt->isPaymentConfirmed());
        self::assertSame(1, $adapter->handledCount());
    }

    public function test_exact_duplicate_event_returns_same_receipt_and_does_not_repeat_processing(): void
    {
        [$draft, $event, $actor, $member] = $this->case();
        $adapter = new InMemoryPerformanceMockAdapter();
        $first = $adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $member);
        $second = $adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $member);
        self::assertSame($first, $second);
        self::assertSame(1, $adapter->handledCount());
    }

    public function test_same_event_id_with_modified_correlation_is_denied_without_overwriting_receipt(): void
    {
        [$draft, $event, $actor, $member] = $this->case();
        $adapter = new InMemoryPerformanceMockAdapter();
        $receipt = $adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $member);
        $changed = new DemoPerformanceEvent('evt-demo-001', 'corr-demo-002', new OrganizationId('org-a'),
            $draft->id, $draft->proposalFingerprint);
        self::assertNull($adapter->handle($draft, $changed, $actor, new OrganizationId('org-a'), $member));
        self::assertSame($receipt, $adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $member));
        self::assertSame(1, $adapter->handledCount());
    }

    public function test_second_event_id_for_same_draft_does_not_duplicate_mock_provisioning(): void
    {
        [$draft, $event, $actor, $member] = $this->case();
        $adapter = new InMemoryPerformanceMockAdapter();
        self::assertNotNull($adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $member));
        $next = new DemoPerformanceEvent('evt-demo-002', 'corr-demo-002', new OrganizationId('org-a'),
            $draft->id, $draft->proposalFingerprint);
        self::assertNull($adapter->handle($draft, $next, $actor, new OrganizationId('org-a'), $member));
        self::assertSame(1, $adapter->handledCount());
    }

    public function test_cross_tenant_draft_and_event_ids_are_rejected(): void
    {
        [$draft, $event, $actor, $member] = $this->case();
        $adapter = new InMemoryPerformanceMockAdapter();
        $foreign = new DemoPerformanceEvent('evt-demo-foreign', 'corr-demo-foreign', new OrganizationId('org-b'),
            $draft->id, $draft->proposalFingerprint);
        self::assertNull($adapter->handle($draft, $foreign, $actor, new OrganizationId('org-a'), $member));
        self::assertNull($adapter->handle($draft, $event, $actor, new OrganizationId('org-b'), $member));
        self::assertNull($adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $this->member('org-b')));
        self::assertSame(0, $adapter->handledCount());
    }

    public function test_stale_proposal_fingerprint_or_foreign_onboarding_id_is_denied(): void
    {
        [$draft, $event, $actor, $member] = $this->case();
        $adapter = new InMemoryPerformanceMockAdapter();
        $badHash = new DemoPerformanceEvent('evt-demo-badhash', 'corr-demo-badhash', new OrganizationId('org-a'),
            $draft->id, str_repeat('0', 64));
        $badId = new DemoPerformanceEvent('evt-demo-badid', 'corr-demo-badid', new OrganizationId('org-a'),
            new DemoOnboardingId('demo_else'), $draft->proposalFingerprint);
        self::assertNull($adapter->handle($draft, $badHash, $actor, new OrganizationId('org-a'), $member));
        self::assertNull($adapter->handle($draft, $badId, $actor, new OrganizationId('org-a'), $member));
        self::assertSame(0, $adapter->handledCount());
    }

    public function test_revoked_user_prevents_replay_even_if_event_was_previously_handled(): void
    {
        [$draft, $event, $actor, $member] = $this->case();
        $adapter = new InMemoryPerformanceMockAdapter();
        self::assertNotNull($adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $member));
        $revoked = $this->member(status: MembershipStatus::Revoked);
        self::assertNull($adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), $revoked));
        self::assertSame(1, $adapter->handledCount());
    }

    public function test_missing_actor_and_membership_denied(): void
    {
        [$draft, $event, $actor, $member] = $this->case();
        $adapter = new InMemoryPerformanceMockAdapter();
        self::assertNull($adapter->handle($draft, $event, null, new OrganizationId('org-a'), $member));
        self::assertNull($adapter->handle($draft, $event, $actor, null, $member));
        self::assertNull($adapter->handle($draft, $event, $actor, new OrganizationId('org-a'), null));
        self::assertSame(0, $adapter->handledCount());
    }

    public function test_non_demo_events_and_active_status_cannot_be_constructed(): void
    {
        self::assertNull(DemoOnboardingStatus::tryFrom('active'));
        self::assertSame('0.1-draft', DemoPerformanceEvent::SCHEMA_VERSION);
        self::assertSame('performance.onboarding.mock_requested', DemoPerformanceEvent::EVENT_TYPE);
        $this->expectException(InvalidArgumentException::class);
        new DemoPerformanceEvent('real-production-event', 'corr-demo-test', new OrganizationId('org-a'),
            new DemoOnboardingId('demo_onboard-a'), str_repeat('0', 64));
    }
}
