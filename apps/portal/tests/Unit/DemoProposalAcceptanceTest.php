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
use App\Domain\Proposals\DemoAcceptanceService;
use App\Domain\Proposals\DemoProposalId;
use App\Domain\Proposals\DemoProposalSimulator;
use App\Domain\Proposals\DemoProposalSnapshot;
use PHPUnit\Framework\TestCase;

final class DemoProposalAcceptanceTest extends TestCase
{
    private function proposal(string $org = 'org-a'): DemoProposalSnapshot
    {
        return (new DemoProposalSimulator())->present(
            new DemoProposalId('prop-a'),
            new OrganizationId($org),
            new PerformanceOffering(
                new OfferingId('offer-demo'),
                ProductCode::PerformanceAI,
                'v0.1',
                'Oferta de demonstração não contratável',
                OfferingStatus::NotForSale,
                PerformanceModality::SingleChannel,
                [PerformanceChannel::MetaAds],
            ),
            'demo-v0.1',
        );
    }

    /** @param list<Permission> $permissions */
    private function member(
        string $org = 'org-a',
        string $actor = 'actor-a',
        MembershipStatus $status = MembershipStatus::Active,
        array $permissions = [Permission::ManageServices],
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor),
            new OrganizationId($org),
            OrganizationRole::Member,
            $status,
            $permissions,
        );
    }

    public function test_explicit_fictitious_acceptance_requires_manage_permission_and_version_match(): void
    {
        $proposal = $this->proposal();
        $ack = (new DemoAcceptanceService())->acknowledge(
            $proposal,
            new PortalUserId('actor-a'),
            new OrganizationId('org-a'),
            $this->member(),
            $proposal->fingerprint(),
            $proposal->demoTermsVersion,
            true,
        );

        self::assertNotNull($ack);
        self::assertSame('prop-a', $ack->proposalId->value);
        self::assertSame('actor-a', $ack->acknowledgingActorId->value);
        self::assertSame('demo-v0.1', $ack->acknowledgedDemoTermsVersion);
        self::assertFalse($ack->isLegallyBinding());
        self::assertFalse($ack->triggersBilling());
        self::assertFalse($ack->triggersProvisioning());
    }

    public function test_absence_of_express_acknowledgment_is_denied(): void
    {
        $proposal = $this->proposal();
        self::assertNull((new DemoAcceptanceService())->acknowledge(
            $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'),
            $this->member(), $proposal->fingerprint(), $proposal->demoTermsVersion, false,
        ));
    }

    public function test_wrong_offering_snapshot_or_terms_version_is_denied(): void
    {
        $proposal = $this->proposal();
        $svc = new DemoAcceptanceService();
        self::assertNull($svc->acknowledge(
            $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'), $this->member(),
            str_repeat('0', 64), $proposal->demoTermsVersion, true,
        ));
        self::assertNull($svc->acknowledge(
            $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'), $this->member(),
            $proposal->fingerprint(), 'demo-v0.2', true,
        ));
    }

    public function test_cross_organization_proposal_is_denied_even_with_active_membership(): void
    {
        $proposal = $this->proposal('org-b');
        self::assertNull((new DemoAcceptanceService())->acknowledge(
            $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'),
            $this->member('org-a'), $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        ));
    }

    public function test_foreign_actor_or_organization_membership_is_denied(): void
    {
        $proposal = $this->proposal();
        $svc = new DemoAcceptanceService();
        foreach ([$this->member('org-a', 'actor-b'), $this->member('org-b', 'actor-a')] as $membership) {
            self::assertNull($svc->acknowledge(
                $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'),
                $membership, $proposal->fingerprint(), $proposal->demoTermsVersion, true,
            ));
        }
    }

    public function test_invited_suspended_revoked_and_view_only_memberships_are_denied(): void
    {
        $proposal = $this->proposal();
        $svc = new DemoAcceptanceService();
        foreach ([MembershipStatus::Invited, MembershipStatus::Suspended, MembershipStatus::Revoked] as $status) {
            self::assertNull($svc->acknowledge(
                $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'),
                $this->member(status: $status), $proposal->fingerprint(), $proposal->demoTermsVersion, true,
            ));
        }

        self::assertNull($svc->acknowledge(
            $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'),
            $this->member(permissions: [Permission::ViewServices]),
            $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        ));
    }

    public function test_missing_identity_org_or_membership_is_denied(): void
    {
        $proposal = $this->proposal();
        $svc = new DemoAcceptanceService();
        self::assertNull($svc->acknowledge(
            $proposal, null, new OrganizationId('org-a'), $this->member(),
            $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        ));
        self::assertNull($svc->acknowledge(
            $proposal, new PortalUserId('actor-a'), null, $this->member(),
            $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        ));
        self::assertNull($svc->acknowledge(
            $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'), null,
            $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        ));
    }

    public function test_acknowledgment_is_immutable_and_nonbinding(): void
    {
        $proposal = $this->proposal();
        $ack = (new DemoAcceptanceService())->acknowledge(
            $proposal, new PortalUserId('actor-a'), new OrganizationId('org-a'), $this->member(),
            $proposal->fingerprint(), $proposal->demoTermsVersion, true,
        );
        self::assertNotNull($ack);
        self::assertTrue((new \ReflectionClass($ack))->isReadOnly());
        self::assertFalse($proposal->isCheckoutEligible());
    }
}
