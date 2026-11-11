<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authorization\Permission;
use App\Domain\CommercialDeliveryReview\CommercialDeliveryReviewPreflight;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\PortalAccess\Authority\PortalOrganizationAuthority;
use App\Domain\PortalAccess\Authority\PortalOrganizationSnapshot;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionSnapshot;
use Tests\TestCase;

final class CommercialDeliveryReviewPreflightDefaultDenyTest extends TestCase
{
    public const SUBMISSION = 'e4e4e4e4-e4e4-4e4e-8e4e-e4e4e4e4e4e4';

    private function synthetic(
        array $permissions,
        string $membershipOrg = 'org-a',
        string $ownerOrg = 'org-a',
        bool $mfa = true,
        bool $active = true,
        string $sessionOrg = 'org-a',
        int $sessionMembershipRevision = 1,
        int $currentMembershipRevision = 1,
        int $sessionOrganizationRevision = 1,
        bool $organizationActive = true,
        int $organizationRevision = 1,
        string $organizationStateTenant = 'org-a',
    ): CommercialDeliveryReviewPreflight {
        $session = new PortalSessionSnapshot(
            new PortalUserId('actor-a'),$active,$mfa,1000,2000,7,7,
            new OrganizationId($sessionOrg),
            $sessionMembershipRevision,
            $sessionOrganizationRevision,
        );
        $membership = new OrganizationMembership(
            new PortalUserId('actor-a'),
            new OrganizationId($membershipOrg),
            OrganizationRole::Member,
            MembershipStatus::Active,
            $permissions,
            $currentMembershipRevision,
        );
        $sessionSource = new class($session) implements PortalSessionAuthority {
            public function __construct(private PortalSessionSnapshot $item) {}
            public function find(string $opaqueReference): ?PortalSessionSnapshot
            {
                return $opaqueReference==='portal-fixture-session'?$this->item:null;
            }
        };
        $membershipSource = new class($membership) implements PortalMembershipAuthority {
            public function __construct(private OrganizationMembership $item) {}
            public function findFor(PortalUserId $actor,OrganizationId $organization): ?OrganizationMembership
            {
                return $this->item;
            }
        };
        $resourceSource = new class($ownerOrg) implements PortalResourceAuthority {
            public function __construct(private string $ownerOrg) {}
            public function ownerOf(OrganizationId $organization,string $resource): ?OrganizationId
            {
                return $resource === CommercialDeliveryReviewPreflightDefaultDenyTest::SUBMISSION
                    ? new OrganizationId($this->ownerOrg)
                    : null;
            }
        };
        $organizationSource = new class($organizationActive,$organizationRevision,$organizationStateTenant)
            implements PortalOrganizationAuthority {
            public function __construct(
                private bool $active, private int $revision, private string $tenant
            ) {}
            public function lookup(OrganizationId $selected): ?PortalOrganizationSnapshot
            {
                return new PortalOrganizationSnapshot(
                    new OrganizationId($this->tenant), $this->active, $this->revision
                );
            }
        };
        return new CommercialDeliveryReviewPreflight(
            new PortalAuthorityBoundary($sessionSource,$membershipSource,$resourceSource,$organizationSource)
        );
    }

    public function test_laravel_default_sources_cannot_read_reconciliation_queue(): void
    {
        $preflight=$this->app->make(CommercialDeliveryReviewPreflight::class);
        $verdict=$preflight->inspect(
            'plausible-portal-session',new OrganizationId('org-a'),
            self::SUBMISSION,1_800_000_000,
        );
        self::assertFalse($verdict->syntheticChecksPass);
        self::assertFalse($verdict->canReadReviewQueue());
        self::assertFalse($verdict->canReleaseRetry());
        self::assertFalse($verdict->canAcknowledgeVisitor());
        self::assertFalse($verdict->canAuthorizeHttp());
    }

    public function test_even_a_positive_synthetic_review_candidate_cannot_authorize_any_operation(): void
    {
        $preflight=$this->synthetic([Permission::ViewCommercialDeliveryReview]);
        $verdict=$preflight->inspect('portal-fixture-session',
            new OrganizationId('org-a'),self::SUBMISSION,1500);
        self::assertTrue($verdict->syntheticChecksPass);
        self::assertFalse($verdict->canReadReviewQueue());
        self::assertFalse($verdict->canReleaseRetry());
        self::assertFalse($verdict->canAcknowledgeVisitor());
        self::assertFalse($verdict->canAuthorizeHttp());
    }

    public function test_unrelated_permission_cannot_substitute_for_review_grant(): void
    {
        foreach ([[],[Permission::ViewServices],[Permission::ManageServices],
            [Permission::ViewBilling],[Permission::ManageMembers]] as $grants) {
            $verdict=$this->synthetic($grants)->inspect(
                'portal-fixture-session',new OrganizationId('org-a'),
                self::SUBMISSION,1500,
            );
            self::assertFalse($verdict->syntheticChecksPass);
            self::assertFalse($verdict->canReadReviewQueue());
        }
        self::assertSame('commercial.delivery-review.view',
            Permission::ViewCommercialDeliveryReview->value);
    }

    public function test_wrong_tenant_or_untrusted_opaque_inputs_denied(): void
    {
        foreach ([
            $this->synthetic([Permission::ViewCommercialDeliveryReview],
                membershipOrg:'org-b'),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],
                ownerOrg:'org-b'),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],
                sessionOrg:'org-b'),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],
                sessionMembershipRevision:1, currentMembershipRevision:2),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],
                organizationActive:false),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],
                organizationRevision:2),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],
                organizationStateTenant:'org-b'),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],
                sessionOrganizationRevision:2),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],mfa:false),
            $this->synthetic([Permission::ViewCommercialDeliveryReview],active:false),
        ] as $preflight) {
            self::assertFalse($preflight->inspect('portal-fixture-session',
                new OrganizationId('org-a'),self::SUBMISSION,1500)->syntheticChecksPass);
        }
        $preflight=$this->synthetic([Permission::ViewCommercialDeliveryReview]);
        foreach ([null,'','../../../crm','not-a-uuid',
            '00000000-0000-0000-0000-000000000000'] as $resource) {
            self::assertFalse($preflight->inspect('portal-fixture-session',
                new OrganizationId('org-a'),$resource,1500)->syntheticChecksPass);
        }
        self::assertFalse($preflight->inspect('crm-cookie',
            new OrganizationId('org-a'),self::SUBMISSION,1500)->syntheticChecksPass);
        self::assertFalse($preflight->inspect('portal-fixture-session',
            new OrganizationId('org-a'),self::SUBMISSION,2000)->syntheticChecksPass);
    }

    public function test_reconciliation_routes_and_crm_bridge_remain_closed(): void
    {
        $urls=[];
        foreach ($this->app['router']->getRoutes() as $route) {
            $urls[]=$route->uri();
        }
        self::assertSame(['up'],$urls);
        foreach ([
            '/api/portal/commercial/deliveries/review',
            '/api/portal/commercial/deliveries/retry',
            '/api/portal/commercial/deliveries/ack',
            '/__lab/crm/lookup',
        ] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
