<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalMultiGatewayAdminPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->createMock(DatabaseManager::class);
        $db->expects($this->never())->method('connection');
        $this->app->instance('db', $db);
    }

    private function screen(array $overrides = []): string
    {
        return view('portal.preview.gateway-routing', array_merge([
            'selectionMode' => 'manual',
            'preferredGateway' => 'asaas',
            'gatewayRows' => [
                ['label' => 'Asaas', 'enabled' => true, 'methods' => [
                    ['label' => 'Pix', 'status' => 'Simulação fictícia'],
                ]],
                ['label' => 'C6 Bank', 'enabled' => false, 'methods' => []],
            ],
        ], $overrides))->render();
    }

    public function test_gateway_screen_is_read_only_and_explicitly_non_operational(): void
    {
        $html = $this->screen();
        self::assertStringContainsString('Gerenciador multi-gateway', $html);
        self::assertStringContainsString('Asaas', $html);
        self::assertStringContainsString('C6 Bank', $html);
        self::assertStringContainsString('SEM ATIVAÇÃO FINANCEIRA', $html);
        self::assertStringContainsString('Bloqueado / não homologado', $html);
        self::assertStringContainsString('#F9941D', $html);
        foreach (['<form', '<input', '<button', '<script', 'fetch(', 'action='] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_untrusted_method_and_gateway_labels_are_escaped(): void
    {
        $attack = '\"><img src=x onerror=alert(1)>';
        $html = $this->screen(['gatewayRows' => [['label' => $attack, 'enabled' => false, 'methods' => [
            ['label' => $attack, 'status' => $attack],
        ]]]]);
        self::assertGreaterThanOrEqual(3, substr_count($html, e($attack)));
        self::assertStringNotContainsString('<img', $html);
    }

    public function test_view_never_creates_routes_or_exposes_checkout(): void
    {
        $routes = $this->app['router']->getRoutes();
        $before = array_map(static fn ($r) => $r->uri(), iterator_to_array($routes));
        $this->screen();
        $after = array_map(static fn ($r) => $r->uri(), iterator_to_array($this->app['router']->getRoutes()));
        self::assertSame($before, $after);
        self::assertSame(['up'], array_values(array_unique($after)));
    }
}
