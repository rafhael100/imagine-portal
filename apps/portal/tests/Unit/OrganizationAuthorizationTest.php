<?php

namespace Tests\Unit;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use PHPUnit\Framework\TestCase;

final class OrganizationAuthorizationTest extends TestCase
{
    private function member(
        string $actor,
        string $org,
        MembershipStatus $status = MembershipStatus::Active,
        array $permissions = [Permission::ViewServices],
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor),
            new OrganizationId($org),
            OrganizationRole::Member,
            $status,
            $permissions,
        );
    }

    private function allowed(
        ?PortalUserId $actor,
        ?OrganizationId $org,
        ?OrganizationId $resource,
        string $permission,
        ?OrganizationMembership $member,
    ): bool {
        return (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext($actor, $org, $resource, $permission),
            $member,
        )->allowed;
    }

    public function test_explicit_active_membership_and_matching_scope_allow_access(): void
    {
        self::assertTrue($this->allowed(
            new PortalUserId('actor-a'), new OrganizationId('org-a'),
            new OrganizationId('org-a'), Permission::ViewServices->value,
            $this->member('actor-a', 'org-a'),
        ));
    }

    public function test_one_user_in_two_organizations_has_separate_permissions(): void
    {
        $a = $this->member('actor-a', 'org-a');
        $b = $this->member('actor-a', 'org-b', MembershipStatus::Active, [Permission::ViewBilling]);

        self::assertTrue($this->allowed(new PortalUserId('actor-a'), new OrganizationId('org-a'),
            new OrganizationId('org-a'), 'services.view', $a));
        self::assertFalse($this->allowed(new PortalUserId('actor-a'), new OrganizationId('org-b'),
            new OrganizationId('org-b'), 'services.view', $b));
        self::assertTrue($this->allowed(new PortalUserId('actor-a'), new OrganizationId('org-b'),
            new OrganizationId('org-b'), 'billing.view', $b));
        self::assertFalse($this->allowed(new PortalUserId('actor-a'), new OrganizationId('org-a'),
            new OrganizationId('org-a'), 'billing.view', $a));
    }

    public function test_cross_company_resource_denied_even_with_active_membership(): void
    {
        self::assertFalse($this->allowed(
            new PortalUserId('actor-a'), new OrganizationId('org-a'),
            new OrganizationId('org-b'), 'services.view', $this->member('actor-a', 'org-a'),
        ));
    }

    public function test_missing_and_foreign_memberships_are_denied(): void
    {
        $a = new PortalUserId('actor-a');
        $org = new OrganizationId('org-a');

        self::assertFalse($this->allowed($a, $org, $org, 'services.view', null));
        self::assertFalse($this->allowed($a, $org, $org, 'services.view', $this->member('actor-b', 'org-a')));
        self::assertFalse($this->allowed($a, $org, $org, 'services.view', $this->member('actor-a', 'org-b')));
    }

    public function test_invited_suspended_and_revoked_memberships_are_denied(): void
    {
        foreach ([MembershipStatus::Invited, MembershipStatus::Suspended, MembershipStatus::Revoked] as $status) {
            self::assertFalse($this->allowed(
                new PortalUserId('actor-a'), new OrganizationId('org-a'),
                new OrganizationId('org-a'), 'services.view', $this->member('actor-a', 'org-a', $status),
            ), $status->value);
        }
    }

    public function test_unknown_missing_and_wildcard_permissions_fail_closed(): void
    {
        $a = new PortalUserId('actor-a');
        $org = new OrganizationId('org-a');
        $member = $this->member('actor-a', 'org-a', MembershipStatus::Active, []);

        foreach (['services.view', 'other.action', '', '*'] as $requested) {
            self::assertFalse($this->allowed($a, $org, $org, $requested, $member));
        }
    }

    public function test_missing_actor_company_or_resource_are_denied(): void
    {
        $a = new PortalUserId('actor-a');
        $org = new OrganizationId('org-a');
        $member = $this->member('actor-a', 'org-a');

        self::assertFalse($this->allowed(null, $org, $org, 'services.view', $member));
        self::assertFalse($this->allowed($a, null, $org, 'services.view', $member));
        self::assertFalse($this->allowed($a, $org, null, 'services.view', $member));
    }
}
