<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoActivityFeed;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalActivityOverviewViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->createMock(DatabaseManager::class);
        $db->expects(self::never())->method('connection');
        $this->app->instance('db', $db);
    }

    public function test_original_imagine_dashboard_shows_contextual_steps_not_fake_recent_events(): void
    {
        $html = view('portal.demo.overview')->render();
        self::assertStringContainsString('Próximas etapas — acompanhamento ilustrativo', $html);
        self::assertStringContainsString('Etapas de exemplo por serviço — sem envio ou prazo real', $html);
        self::assertStringContainsString('DEMO-REQ-LANDING', $html);
        self::assertStringContainsString('Complementar informações de exemplo', $html);
        self::assertStringContainsString('Conferir resolução ilustrativa', $html);
        self::assertStringContainsString('Acompanhar revisão fictícia', $html);
        self::assertStringContainsString('não por data', $html);
        self::assertStringContainsString('--pi-orange:#f9941d', $html);
        self::assertStringContainsString('src="/assets/logo-imagine.png"', $html);
        self::assertStringNotContainsString('Atividades recentes', $html);
        self::assertStringNotContainsString('Nenhuma atividade demonstrativa', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertSame(3, count(DemoActivityFeed::rows()));
    }

    public function test_table_is_labelled_keyboard_accessible_and_does_not_add_routes(): void
    {
        $routesBefore = count($this->app['router']->getRoutes());
        $html=view('portal.demo.overview')->render();
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('tabindex="0"', $html);
        self::assertStringContainsString('<caption>Etapas de exemplo por serviço', $html);
        self::assertStringContainsString('scope="col"', $html);
        self::assertSame($routesBefore, count($this->app['router']->getRoutes()));
        foreach (['/portal', '/portal/overview', '/portal/activity', '/login'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
