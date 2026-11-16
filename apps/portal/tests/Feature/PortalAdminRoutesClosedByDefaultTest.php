<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class PortalAdminRoutesClosedByDefaultTest extends TestCase
{
    public function test_all_admin_paths_are_not_found_when_feature_is_disabled():void
    {
        config()->set('portal_admin.enabled',false);
        foreach([
            '/portal-admin','/portal-admin/login','/portal-admin/mfa'
        ] as $route)$this->get($route)->assertNotFound();

        foreach([
            '/portal-admin/login','/portal-admin/mfa',
            '/portal-admin/logout'
        ] as $route)$this->post($route,[])->assertNotFound();
    }

    public function test_admin_gate_does_not_unlock_customer_or_public_registration():void
    {
        config()->set('portal_admin.enabled',true);
        $this->get('/portal')->assertNotFound();
        $this->get('/register')->assertNotFound();
        $this->get('/api/seo/build')->assertNotFound();
    }
}
