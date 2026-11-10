<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\SeoReview\GoogleSearchConsoleAuthorizationUrl;
use Tests\TestCase;

/**
 * Pure URL generation MUST NOT become a production auth route.
 * Real OAuth requires a separately-reviewed trusted Portal issuer, MFA,
 * transactional tenant grant, PKCE ledger, Google app and secret vault.
 */
final class PortalGscOAuthRoutesClosedTest extends TestCase
{
    public function test_google_auth_start_callback_and_seo_endpoints_remain_closed(): void
    {
        foreach ([
            '/oauth/google',
            '/oauth/google/start',
            '/oauth/google/callback',
            '/api/portal/seo/gsc/connect',
            '/api/portal/seo/gsc/callback',
            '/api/portal/seo/gsc/properties',
            '/api/portal/seo/gsc/disconnect',
        ] as $path) {
            $this->get($path)->assertNotFound();
            $this->post($path)->assertNotFound();
        }
    }

    public function test_google_url_builder_does_not_grant_portal_authorization(): void
    {
        self::assertFalse(GoogleSearchConsoleAuthorizationUrl::authorizesOperationalOAuth());
        self::assertSame(['up'], array_map(
            static fn ($route): string => $route->uri(),
            iterator_to_array($this->app['router']->getRoutes()),
        ));
    }
}
