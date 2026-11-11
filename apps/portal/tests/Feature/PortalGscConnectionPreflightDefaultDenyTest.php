<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organizations\OrganizationId;
use App\Domain\PortalAccess\Authority\DenyingPortalAuthorities;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\PortalAccess\Authority\PortalOrganizationAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use App\Domain\SeoReview\GoogleSearchConsoleConnectionPreflight;
use Tests\TestCase;

/**
 * Operational Laravel binding test: real app container sources deny by
 * default. No OAuth start route or callback is introduced.
 */
final class PortalGscConnectionPreflightDefaultDenyTest extends TestCase
{
    public function test_real_portal_container_keeps_gsc_connect_authority_closed(): void
    {
        $sessions = app(PortalSessionAuthority::class);
        $memberships = app(PortalMembershipAuthority::class);
        $resources = app(PortalResourceAuthority::class);
        $organizations = app(PortalOrganizationAuthority::class);

        self::assertInstanceOf(DenyingPortalAuthorities::class, $sessions);
        self::assertInstanceOf(DenyingPortalAuthorities::class, $memberships);
        self::assertInstanceOf(DenyingPortalAuthorities::class, $resources);
        self::assertSame($sessions, $organizations);

        $service = new GoogleSearchConsoleConnectionPreflight(
            new PortalAuthorityBoundary($sessions, $memberships, $resources, $organizations),
        );
        $review = $service->inspect(
            'synthetic-browser-session',
            new OrganizationId('company-alpha'),
            'sc-domain:imaginecomunicacao.com.br',
            1750000000,
        );

        self::assertFalse($review->syntheticEligibilityPass);
        self::assertFalse($review->canStartOAuth());
        self::assertFalse($review->canReadGoogle());
        self::assertFalse($review->canStoreTokens());

        foreach ([
            '/oauth/google/start',
            '/oauth/google/callback',
            '/api/portal/seo/gsc/connect',
            '/api/portal/seo/gsc/properties',
        ] as $path) {
            $this->get($path)->assertNotFound();
            $this->post($path)->assertNotFound();
        }

        self::assertFalse(config('google_search_console.authorization_flow_enabled'));
        self::assertFalse(config('google_search_console.token_exchange_enabled'));
    }
}
