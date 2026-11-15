<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    public function test_bootstrap_health_check_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_customer_and_billing_routes_are_not_enabled(): void
    {
        $this->get('/')->assertNotFound();
        $this->get('/login')->assertNotFound();
        $this->get('/checkout')->assertNotFound();
        $this->get('/api/leads')->assertNotFound();
    }
}
