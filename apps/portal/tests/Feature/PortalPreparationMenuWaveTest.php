<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\ClientSection;
use App\Presentation\DemoServicePreparation;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalPreparationMenuWaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->createMock(DatabaseManager::class);
        $db->expects(self::never())->method('connection');
        $this->app->instance('db', $db);
    }

    public function test_preparation_has_its_own_selected_menu_and_illustrative_checklist(): void
    {
        self::assertSame('Preparação dos serviços', ClientSection::Preparation->label());
        self::assertCount(8, ClientSection::cases());
        $html = view('portal.demo.preparation')->render();

        foreach ([
            'Preparação dos serviços',
            'Checklist de preparação dos serviços',
            'Preparação por serviço',
            'Sites Imagine · Onepage',
            'Sites Imagine · Landing Page',
            'Performance AI',
            'Consentimento',
            'Google Ads e Meta',
            'CRM Imagine utiliza login e sessão próprios',
            'Universidade Imagine depende de serviço elegível ativo',
            'DEMO-REQ-ONEPAGE',
            'DEMO-REQ-LANDING',
            'DEMO-REQ-PERFORMANCE',
        ] as $copy) {
            self::assertStringContainsString($copy, $html);
        }

        self::assertSame(8, substr_count($html, 'data-preview-section="'));
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('data-preview-section="preparation"', $html);
        self::assertStringContainsString('src="/assets/logo-imagine.png"', $html);
        self::assertStringContainsString('--pi-orange:#f9941d', $html);
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('tabindex="0"', $html);
        self::assertStringContainsString('noindex,nofollow', $html);
        self::assertCount(3, DemoServicePreparation::rows());
        foreach (['<script', '<form', '<input', '<iframe', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_preparation_is_unmounted_and_cannot_start_a_session(): void
    {
        $routesBefore = count($this->app['router']->getRoutes());
        view('portal.demo.preparation')->render();
        self::assertSame($routesBefore, count($this->app['router']->getRoutes()));
        foreach (['/portal/preparation', '/portal/services/activate', '/portal/performance/launch'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
        self::assertFalse($this->app['session']->isStarted());
        self::assertFalse(DemoServicePreparation::authorizesPublication());
        self::assertFalse(DemoServicePreparation::authorizesAds());
        self::assertFalse(DemoServicePreparation::authorizesCustomerAccess());
    }
}
