<?php

namespace Tests\Unit;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalActor;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\CustomerOrganization;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OrganizationDomainBoundariesTest extends TestCase
{
    public function test_owner_role_does_not_automatically_grant_sensitive_permissions(): void
    {
        $actor = new PortalUserId('portal-owner-a');
        $org = new OrganizationId('org-a');
        $membership = new OrganizationMembership($actor, $org, OrganizationRole::Owner, MembershipStatus::Active);
        $decision = (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext($actor, $org, $org, Permission::ManageMembers->value),
            $membership,
        );

        self::assertFalse($decision->allowed);
    }

    public function test_staff_without_explicit_membership_has_no_cross_company_override(): void
    {
        $staff = new PortalUserId('imagine-staff-example');
        $org = new OrganizationId('org-a');
        $decision = (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext($staff, $org, $org, Permission::ViewBilling->value),
            null,
        );

        self::assertFalse($decision->allowed);
    }

    public function test_crm_named_identifier_cannot_reuse_another_portal_membership(): void
    {
        $portalActor = new PortalUserId('portal-actor-a');
        $crmNamedActor = new PortalUserId('crm-actor-a');
        $org = new OrganizationId('org-a');
        $membership = new OrganizationMembership(
            $portalActor, $org, OrganizationRole::Member, MembershipStatus::Active,
            [Permission::ViewServices],
        );

        self::assertFalse((new OrganizationAuthorizationService())->decide(
            new AuthorizationContext($crmNamedActor, $org, $org, Permission::ViewServices->value),
            $membership,
        )->allowed);
    }

    public function test_references_have_only_explicit_ids_without_email_cnpj_or_login_state(): void
    {
        $actor = new PortalActor(new PortalUserId('portal-actor-a'));
        $company = new CustomerOrganization(new OrganizationId('org-a'));
        self::assertSame('portal-actor-a', $actor->id->value);
        self::assertSame('org-a', $company->id->value);
        self::assertSame([], array_diff(array_keys(get_object_vars($actor)), ['id']));
        self::assertSame([], array_diff(array_keys(get_object_vars($company)), ['id']));
    }

    public function test_membership_rejects_untyped_permission_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OrganizationMembership(
            new PortalUserId('portal-actor-a'), new OrganizationId('org-a'),
            OrganizationRole::Member, MembershipStatus::Active, ['services.view'],
        );
    }

    public function test_empty_portal_user_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PortalUserId('');
    }

    public function test_email_is_not_accepted_as_an_opaque_portal_user_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PortalUserId('person@example.test');
    }

    public function test_invalid_organization_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OrganizationId(" org-a ");
    }

    public function test_same_actor_with_membership_from_other_company_is_denied(): void
    {
        $actor = new PortalUserId('portal-actor-a');
        $selected = new OrganizationId('org-a');
        $foreign = new OrganizationId('org-b');
        $foreignMember = new OrganizationMembership(
            $actor, $foreign, OrganizationRole::Manager, MembershipStatus::Active,
            [Permission::ViewServices],
        );

        self::assertFalse((new OrganizationAuthorizationService())->decide(
            new AuthorizationContext($actor, $selected, $selected, Permission::ViewServices->value),
            $foreignMember,
        )->allowed);
    }
}
