<?php
namespace Tests\Feature;

use Tests\TestCase;

final class PortalAuthenticationRoutesClosedTest extends TestCase
{
    public function test_no_login_mfa_customer_portal_or_performance_route_is_enabled_yet(): void
    {
        foreach ([
            '/login', '/logout', '/register', '/forgot-password', '/reset-password',
            '/mfa', '/mfa/challenge', '/portal', '/portal/services',
            '/performance', '/performance/dashboard', '/performance/approvals',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
