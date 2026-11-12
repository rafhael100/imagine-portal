<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalDemoRequestInboxPresentationTest extends TestCase
{
    public function test_request_inbox_and_status_count_render_without_exposing_any_operations(): void
    {
        $html = Blade::render("@include('portal.demo.requests')");
        foreach ([
            'Solicitações de exemplo', 'Resumo ilustrativo por situação',
            'DEMO-REQ-LANDING', 'DEMO-REQ-ONEPAGE', 'DEMO-REQ-PERFORMANCE',
            'Distribuição da fila de exemplo', 'Aguardando informações do cliente',
            'Em análise', 'Resolvida', 'Nenhum chamado foi aberto ou enviado',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('tabindex="0"', $html);
        self::assertStringContainsString('scope="col"', $html);
        self::assertStringContainsString('noindex,nofollow', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function test_request_submission_and_customer_access_stay_unavailable(): void
    {
        foreach (['/portal/requests', '/portal/request/new', '/portal/request/DEMO-REQ-ONEPAGE',
                  '/portal', '/login', '/mfa'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
