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
use App\Domain\ServicePortfolio\UnavailableUniversityCommercialPolicyAuthority;
use App\Domain\ServicePortfolio\UnavailableUniversityServiceCoverageAuthority;
use App\Domain\ServicePortfolio\UniversityCommercialEligibilityPreflight;
use PHPUnit\Framework\TestCase;

final class UniversityCommercialWiringSafetyTest extends TestCase
{
    public function test_explicit_university_permissions_on_active_owner_still_never_grant_access(): void
    {
        $tenant = new OrganizationId('synthetic-company');
        $actor = new PortalUserId('synthetic-editor');
        $permissions = [Permission::ViewUniversity, Permission::ManageUniversity];
        $membership = new OrganizationMembership(
            $actor, $tenant, OrganizationRole::Owner, MembershipStatus::Active,
            $permissions, 9,
        );

        foreach ($permissions as $permission) {
            $context = new AuthorizationContext(
                $actor, $tenant, $tenant, $permission->value,
            );
            $decision = (new OrganizationAuthorizationService())
                ->decide($context, $membership);
            self::assertFalse($decision->allowed,
                'A client service contract cannot be inferred from an in-memory membership.');
        }
    }

    public function test_the_two_new_service_sources_are_unavailable_by_default(): void
    {
        $tenant = new OrganizationId('synthetic-company');
        self::assertNull((new UnavailableUniversityCommercialPolicyAuthority())->currentFor($tenant));
        self::assertNull((new UnavailableUniversityServiceCoverageAuthority())->currentFor($tenant));

        $decision = (new UniversityCommercialEligibilityPreflight())
            ->inspect($tenant, 1800000000);
        self::assertSame('source_unavailable', $decision->reason);
        self::assertFalse($decision->mayIssuePortalGrant());
        self::assertFalse($decision->provesPaidContract());
    }

    public function test_portal_application_has_no_commercial_grant_binding_or_issuer_route(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = file_get_contents($root.'/app/Providers/AppServiceProvider.php');
        $routes = file_get_contents($root.'/routes/web.php');

        self::assertIsString($provider);
        self::assertIsString($routes);
        self::assertStringNotContainsString(
            'UniversityCommercialPolicyAuthority::class', $provider,
        );
        self::assertStringNotContainsString(
            'UniversityServiceCoverageAuthority::class', $provider,
        );
        self::assertStringNotContainsString(
            'UniversityCommercialEligibilityPreflight::class', $provider,
        );
        self::assertStringNotContainsString('university:view', $routes);
        self::assertStringNotContainsString('university:manage', $routes);
    }
}
