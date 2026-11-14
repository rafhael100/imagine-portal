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
 * F110 — pure domain evaluation only; an allow result is NOT a real
 * authenticated Portal session or customer GSC display capability.
 */
final class PortalGscViewPermissionIsolationTest extends TestCase
{
    private function evaluate(
        array $grants,
        string $organization='company-a',
        string $resourceOrganization='company-a',
        string $actor='user-a',
        string $memberActor='user-a',
        string $memberOrganization='company-a',
        MembershipStatus $status=MembershipStatus::Active,
        string $requested='seo.gsc.view',
    ): bool {
        $membership=new OrganizationMembership(
            new PortalUserId($memberActor),
            new OrganizationId($memberOrganization),
            OrganizationRole::Owner,
            $status,
            $grants,
        );
        return (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext(
                new PortalUserId($actor),
                new OrganizationId($organization),
                new OrganizationId($resourceOrganization),
                $requested,
            ),
            $membership,
        )->allowed;
    }

    public function test_gsc_connect_does_not_imply_metrics_view_even_for_owner(): void
    {
        self::assertSame('seo.gsc.connect',Permission::ConnectGoogleSearchConsole->value);
        self::assertSame('seo.gsc.view',Permission::ViewGoogleSearchConsoleMetrics->value);
        self::assertFalse($this->evaluate([]));
        self::assertFalse($this->evaluate([Permission::ConnectGoogleSearchConsole]));
        self::assertTrue($this->evaluate([Permission::ViewGoogleSearchConsoleMetrics]));
        self::assertFalse($this->evaluate(
            [Permission::ViewGoogleSearchConsoleMetrics],
            requested:'seo.gsc.connect'
        ));
        self::assertTrue($this->evaluate(
            [Permission::ConnectGoogleSearchConsole],
            requested:'seo.gsc.connect'
        ));
    }

    public function test_membership_identity_organization_and_resource_isolation(): void
    {
        $view=[Permission::ViewGoogleSearchConsoleMetrics];
        self::assertFalse($this->evaluate($view,resourceOrganization:'company-b'));
        self::assertFalse($this->evaluate($view,organization:'company-b'));
        self::assertFalse($this->evaluate($view,memberOrganization:'company-b'));
        self::assertFalse($this->evaluate($view,memberActor:'user-b'));
        self::assertFalse($this->evaluate($view,actor:'user-b'));
        foreach([MembershipStatus::Invited,MembershipStatus::Suspended,
            MembershipStatus::Revoked] as $status) {
            self::assertFalse($this->evaluate($view,status:$status),$status->value);
        }
    }

    public function test_view_is_not_inferred_from_wildcards_or_other_permissions(): void
    {
        self::assertFalse($this->evaluate([Permission::ViewServices]));
        self::assertFalse($this->evaluate([],requested:'seo.gsc.*'));
        self::assertFalse($this->evaluate([],requested:'*'));
        self::assertFalse($this->evaluate([],requested:''));
        self::assertFalse($this->evaluate([],requested:'seo.gsc.metrics.read'));
    }
}
