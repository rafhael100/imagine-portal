<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F99 checks the future Portal-specific cookie namespace only.
 * No login, user, MFA verifier, session persistence or CRM integration exists.
 */
final class PortalSessionCookieIsolationTest extends TestCase
{
    public function test_host_only_cookie_is_https_only_and_disjoint_from_crm(): void
    {
        self::assertSame('__Host-imagine-portal-session', config('session.cookie'));
        self::assertSame('/', config('session.path'));
        self::assertNull(config('session.domain'));
        self::assertTrue(config('session.secure'));
        self::assertTrue(config('session.http_only'));
        self::assertSame('lax', config('session.same_site'));
        self::assertFalse(config('session.partitioned'));

        self::assertNotSame('imagine_crm_session', config('session.cookie'));
        self::assertNotSame('laravel_session', config('session.cookie'));
    }

    public function test_no_customer_session_is_issued_by_health_or_unimplemented_routes(): void
    {
        $health = $this->get('/up')->assertOk();
        self::assertSame([], $health->headers->getCookies());

        foreach (['/login', '/portal', '/crm/sso', '/api/portal/session'] as $route) {
            $response = $this->get($route)->assertNotFound();
            self::assertSame([], $response->headers->getCookies());
        }
    }
}
