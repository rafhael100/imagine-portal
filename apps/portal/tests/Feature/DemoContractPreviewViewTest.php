<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class DemoContractPreviewViewTest extends TestCase
{
    public function test_static_contract_view_is_fictional_and_never_exposes_payment_forms(): void
    {
        $html = view('portal.demo.contracts')->render();
        foreach ([
            'DEMO-CTR-ONEPAGE',
            'DEMO-CTR-LANDING',
            'DEMO-CTR-PERFORMANCE',
            'Performance AI',
            'Não realizada',
            'sem validade jurídica',
            'Contratos independentes',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertSame(3, substr_count($html, 'Não realizada'));
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('noindex,nofollow', $html);
    }

    public function test_preview_changes_do_not_create_contract_or_payment_routes(): void
    {
        foreach (['/portal/contracts', '/contracts', '/checkout', '/payment',
            '/portal', '/login'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
