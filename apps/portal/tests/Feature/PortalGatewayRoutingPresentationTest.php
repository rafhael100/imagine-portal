<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalGatewayRoutingPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->createMock(DatabaseManager::class);
        $db->expects($this->never())->method('connection');
        $this->app->instance('db', $db);
    }

    public function test_readonly_gateway_preview_uses_synthetic_fees_only(): void
    {
        $html = view('portal.demo.gateway-routing')->render();
        self::assertStringContainsString('Somente prévia fictícia', $html);
        self::assertStringContainsString('Asaas', $html);
        self::assertStringContainsString('C6 Bank', $html);
        self::assertStringContainsString('Não ativado', $html);
        self::assertStringContainsString('R$ 2,00', $html);
        self::assertStringContainsString('R$ 0,90', $html);
        self::assertStringContainsString('R$ 99,10', $html);
        self::assertStringContainsString('DEMO-C6-01', $html);
        self::assertStringContainsString('nunca migram automaticamente', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('action=', $html);
        self::assertStringNotContainsString('https://api', $html);
        self::assertStringNotContainsString('src=', $html);
    }

    public function test_rendering_gateway_preview_creates_no_payment_routes_or_session(): void
    {
        $routes = static fn(): array => array_map(
            static fn($r) => [$r->methods(), $r->uri(), $r->getActionName()],
            iterator_to_array($this->app['router']->getRoutes()),
        );
        $before = $routes();
        view('portal.demo.gateway-routing')->render();
        self::assertSame($before, $routes());
        self::assertSame(['up'], array_values(array_unique(array_column($before, 1))));
        self::assertFalse($this->app['session']->isStarted());
    }
}
