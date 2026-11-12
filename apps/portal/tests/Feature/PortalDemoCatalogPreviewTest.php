<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalDemoCatalogPreviewTest extends TestCase
{
    public function test_catalog_shows_five_distinct_offerings_without_customer_access(): void
    {
        $html = Blade::render("@include('portal.demo.available')");
        foreach ([
            'Sites Imagine · Onepage', 'Sites Imagine · Landing Page',
            'Performance AI', 'Imagine CRM', 'Universidade Imagine',
            'Não contratável', 'sem SSO', 'não assinatura avulsa',
        ] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertSame(5, substr_count($html, 'Não contratável'));
        self::assertStringContainsString('Nenhum acesso ou direito é concedido', $html);
        self::assertStringContainsString('noindex,nofollow', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function test_service_sale_and_university_entitlement_routes_remain_closed(): void
    {
        foreach ([
            '/portal/catalog', '/portal/checkout', '/portal/university',
            '/portal/services', '/portal/billing', '/login', '/mfa',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
