<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalDemoUnifiedOverviewViewTest extends TestCase
{
    public function test_dashboard_derives_fictional_metrics_from_three_typed_sources(): void
    {
        $html = Blade::render("@include('portal.demo.overview')");
        foreach ([
            'Serviços no exemplo', 'Solicitações de exemplo', 'Contratos ilustrativos',
            'Resumo integrado fictício', 'Sites Imagine · Onepage',
            'Sites Imagine · Landing Page', 'Performance AI',
            'Aguardando informações do cliente', 'Revisão fictícia',
            'Nenhum dado parcial será apresentado',
        ] as $text) {
            // The final warning belongs only to the rejected branch.
            if ($text === 'Nenhum dado parcial será apresentado') {
                self::assertStringNotContainsString($text, $html);
                continue;
            }
            self::assertStringContainsString($text, $html);
        }
        self::assertStringContainsString('noindex,nofollow', $html);
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('scope="col"', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function test_dashboard_does_not_open_customer_payment_or_login_routes(): void
    {
        foreach (['/portal', '/portal/overview', '/portal/contracts', '/portal/requests',
                  '/login', '/mfa', '/checkout'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
