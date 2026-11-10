<?php

declare(strict_types=1);

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

/**
 * F115: alignment of the application permission vocabulary with F113.
 *
 * These are PURE synthetic decisions, not an authenticated Laravel
 * session, a Google property check, a token grant or an HTTP capability.
 */
final class PortalGscPermissionContractTest extends TestCase
{
    private function has(
        ?OrganizationMembership $membership,
        string $actor = 'actor-a',
        string $selected = 'org-a',
        string $resourceOwner = 'org-a',
        string $permission = 'seo.gsc.connect',
    ): bool {
        return (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext(
                new PortalUserId($actor),
                new OrganizationId($selected),
                new OrganizationId($resourceOwner),
                $permission,
            ),
            $membership,
        )->allowed;
    }

    /** @param list<Permission> $permissions */
    private function membership(
        string $actor = 'actor-a',
        string $organization = 'org-a',
        OrganizationRole $role = OrganizationRole::Owner,
        MembershipStatus $status = MembershipStatus::Active,
        array $permissions = [],
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor),
            new OrganizationId($organization),
            $role,
            $status,
            $permissions,
        );
    }

    public function test_seo_permission_is_exact_and_matches_the_migration(): void
    {
        self::assertSame('seo.gsc.connect', Permission::ConnectGoogleSearchConsole->value);
        self::assertSame(
            ['services.view', 'services.manage', 'billing.view', 'members.manage', 'seo.gsc.connect'],
            array_map(static fn (Permission $p): string => $p->value, Permission::cases()),
        );

        $migration = file_get_contents(__DIR__
            .'/../../database/migrations/2026_11_10_000007_allow_seo_gsc_connect_grant.php');
        self::assertIsString($migration);
        self::assertStringContainsString("'seo.gsc.connect'", $migration);
        self::assertStringNotContainsString("'seo.gsc.manage'", $migration);
    }

    public function test_owner_does_not_implicitly_receive_search_console_connection(): void
    {
        self::assertFalse($this->has($this->membership()));
        self::assertFalse($this->has($this->membership(
            permissions: [Permission::ViewServices, Permission::ManageMembers],
        )));
    }

    public function test_explicit_seo_permission_is_tenant_and_actor_scoped(): void
    {
        $member = $this->membership(
            role: OrganizationRole::Member,
            permissions: [Permission::ConnectGoogleSearchConsole],
        );

        self::assertTrue($this->has($member));
        self::assertFalse($this->has($member, selected: 'org-b', resourceOwner: 'org-b'));
        self::assertFalse($this->has($member, resourceOwner: 'org-b'));
        self::assertFalse($this->has($member, actor: 'actor-b'));
        self::assertFalse($this->has(null));
    }

    public function test_invited_suspended_or_revoked_seo_member_is_denied(): void
    {
        foreach ([
            MembershipStatus::Invited,
            MembershipStatus::Suspended,
            MembershipStatus::Revoked,
        ] as $status) {
            self::assertFalse($this->has($this->membership(
                status: $status,
                permissions: [Permission::ConnectGoogleSearchConsole],
            )), $status->value);
        }
    }

    public function test_scope_escalation_and_wildcards_do_not_match(): void
    {
        $membership = $this->membership(
            permissions: [Permission::ConnectGoogleSearchConsole],
        );

        foreach ([
            'seo.gsc.manage',
            'seo.gsc.connect ',
            'seo.gsc.*',
            'webmasters',
            '*',
            '',
            'services.manage',
        ] as $invalid) {
            self::assertFalse($this->has($membership, permission: $invalid), $invalid);
        }
    }
}
