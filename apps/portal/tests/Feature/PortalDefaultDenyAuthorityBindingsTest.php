<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organizations\OrganizationId;
use App\Domain\PortalAccess\Authority\DenyingPortalAuthorities;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use Tests\TestCase;

/**
 * F98 is a fail-closed application COMPOSITION test only.
 * It does not authenticate a customer, connect a database, issue a session,
 * approve MFA, confirm resource ownership or authorize an HTTP request.
 */
final class PortalDefaultDenyAuthorityBindingsTest extends TestCase
{
    public function test_every_authority_contract_resolves_to_the_same_deny_all_source(): void
    {
        $sessions = $this->app->make(PortalSessionAuthority::class);
        $memberships = $this->app->make(PortalMembershipAuthority::class);
        $resources = $this->app->make(PortalResourceAuthority::class);

        self::assertInstanceOf(DenyingPortalAuthorities::class, $sessions);
        self::assertSame($sessions, $memberships);
        self::assertSame($sessions, $resources);
        self::assertSame($sessions, $this->app->make(DenyingPortalAuthorities::class));
    }

    public function test_default_resolvers_never_invent_session_membership_or_resource_ownership(): void
    {
        $sessions = $this->app->make(PortalSessionAuthority::class);
        $memberships = $this->app->make(PortalMembershipAuthority::class);
        $resources = $this->app->make(PortalResourceAuthority::class);

        $org = new OrganizationId('org-a');

        foreach (['portal-session', 'crm-session', 'synthetic-positive-test', ''] as $ref) {
            self::assertNull($sessions->find($ref));
            self::assertNull($resources->ownerOf($org, $ref));
        }

        $actor = new \App\Domain\Identity\PortalUserId('portal-user-a');
        self::assertNull($memberships->findFor($actor, $org));
        self::assertNull($memberships->findFor($actor, new OrganizationId('org-b')));
    }

    public function test_resolving_full_boundary_from_laravel_cannot_grant_any_access(): void
    {
        $boundary = $this->app->make(PortalAuthorityBoundary::class);

        foreach (['services.view', 'services.manage', '*', 'contracts.view'] as $permission) {
            $verdict = $boundary->inspect(
                'apparently-valid-session',
                new OrganizationId('org-a'),
                'apparently-valid-resource',
                $permission,
                1_700_000_000,
            );
            self::assertFalse($verdict->syntheticChecksPass);
            self::assertFalse($verdict->canAuthorizeHttp());
        }
    }

    public function test_default_bindings_do_not_open_any_laravel_business_route(): void
    {
        $paths = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $paths[] = $route->uri();
        }
        self::assertSame(['up'], $paths);

        foreach (['/login', '/mfa', '/crm/sso', '/portal', '/api/portal/services', '/checkout'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
    }
}
