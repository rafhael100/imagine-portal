<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Invitations\DenyingPortalInvitationIdentitySource;
use App\Domain\Identity\Invitations\PortalInvitationIdentitySource;
use App\Domain\Identity\Invitations\PortalInvitationPreflight;
use App\Domain\Identity\Invitations\PortalInvitationSecret;
use App\Domain\Organizations\OrganizationId;
use Tests\TestCase;

final class PortalInvitationPreflightBindingsTest extends TestCase
{
    public function test_laravel_resolves_denial_only_identity_authority_by_default(): void
    {
        $source = $this->app->make(PortalInvitationIdentitySource::class);

        self::assertInstanceOf(DenyingPortalInvitationIdentitySource::class, $source);
        self::assertSame(
            $this->app->make(DenyingPortalInvitationIdentitySource::class),
            $source,
        );

        $bearer = PortalInvitationSecret::mint()->takeDeliverySecretOnce();
        self::assertIsString($bearer);
        $verdict = $this->app->make(PortalInvitationPreflight::class)->inspect(
            'apparently-valid-portal-session',
            $bearer,
            new OrganizationId('org-a'),
            1_700_000_200,
        );

        self::assertFalse($verdict->syntheticInputsPlausible);
        self::assertFalse($verdict->canConsumeInvitation());
        self::assertFalse($verdict->canIssueSession());
        self::assertFalse($verdict->canAuthorizeHttp());
    }

    public function test_portal_business_routes_remain_closed_despite_new_preflight_contract(): void
    {
        $routes = array_map(
            static fn ($route): string => $route->uri(),
            iterator_to_array($this->app['router']->getRoutes()),
        );
        self::assertSame(['up'], $routes);

        foreach ([
            '/portal/invitations/accept',
            '/api/portal/invitations/consume',
            '/api/portal/identity',
            '/login',
            '/crm/sso',
        ] as $route) {
            $response = $this->get($route)->assertNotFound();
            self::assertSame([], $response->headers->getCookies());
        }
    }
}
