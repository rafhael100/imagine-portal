<?php

namespace Tests\Unit;

use App\Domain\Authorization\Permission;
use App\Domain\Catalog\CatalogAccessPolicy;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use PHPUnit\Framework\TestCase;

final class PerformanceCatalogAccessTest extends TestCase
{
    private function member(
        string $actor,
        string $organization,
        MembershipStatus $status = MembershipStatus::Active,
        array $permissions = [Permission::ViewServices],
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor),
            new OrganizationId($organization),
            OrganizationRole::Member,
            $status,
            $permissions,
        );
    }

    public function test_active_membership_with_explicit_service_permission_can_view(): void
    {
        self::assertTrue((new CatalogAccessPolicy())->canView(
            new PortalUserId('actor-a'),
            new OrganizationId('org-a'),
            new OrganizationId('org-a'),
            $this->member('actor-a', 'org-a'),
        ));
    }

    public function test_cross_organization_resource_is_never_authorized(): void
    {
        self::assertFalse((new CatalogAccessPolicy())->canView(
            new PortalUserId('actor-a'),
            new OrganizationId('org-a'),
            new OrganizationId('org-b'),
            $this->member('actor-a', 'org-a'),
        ));
    }

    public function test_missing_foreign_revoked_or_unprivileged_memberships_are_denied(): void
    {
        $policy = new CatalogAccessPolicy();
        $actor = new PortalUserId('actor-a');
        $organization = new OrganizationId('org-a');
        $members = [
            null,
            $this->member('actor-b', 'org-a'),
            $this->member('actor-a', 'org-b'),
            $this->member('actor-a', 'org-a', MembershipStatus::Revoked),
            $this->member('actor-a', 'org-a', MembershipStatus::Suspended),
            $this->member('actor-a', 'org-a', MembershipStatus::Invited),
            $this->member('actor-a', 'org-a', MembershipStatus::Active, [Permission::ViewBilling]),
        ];

        foreach ($members as $member) {
            self::assertFalse($policy->canView($actor, $organization, $organization, $member));
        }
    }

    public function test_missing_actor_selected_organization_or_owner_fail_closed(): void
    {
        $policy = new CatalogAccessPolicy();
        $actor = new PortalUserId('actor-a');
        $organization = new OrganizationId('org-a');
        $member = $this->member('actor-a', 'org-a');

        self::assertFalse($policy->canView(null, $organization, $organization, $member));
        self::assertFalse($policy->canView($actor, null, $organization, $member));
        self::assertFalse($policy->canView($actor, $organization, null, $member));
    }
}
