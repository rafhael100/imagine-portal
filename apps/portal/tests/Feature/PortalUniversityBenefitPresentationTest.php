<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoUniversityBenefitRequirements;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalUniversityBenefitPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_available_catalog_explains_all_four_nonbinding_university_requirements(): void
    {
        $html = view('portal.demo.available')->render();

        self::assertStringContainsString('Universidade Imagine — critérios de benefício, não de venda', $html);
        self::assertStringContainsString('Acesso à Universidade não verificado', $html);
        self::assertStringContainsString('Nenhum benefício está ativo, habilitado ou autorizado', $html);
        self::assertCount(4, DemoUniversityBenefitRequirements::items());
        foreach (DemoUniversityBenefitRequirements::items() as $requirement) {
            self::assertStringContainsString(e($requirement), $html);
        }
        self::assertStringContainsString('serviço Imagine elegível e ativo', $html);
        self::assertStringContainsString('último serviço elegível', $html);
        self::assertStringContainsString('Não vender a Universidade Imagine isoladamente', $html);
    }

    public function test_benefit_panel_does_not_register_routes_or_access_or_link_to_crm(): void
    {
        $before = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $before[] = [$route->uri(), $route->methods()];
        }

        $html = view('portal.demo.available')->render();

        foreach (['<form', '<input', '<button', '<script', '<iframe',
            'action=', 'http://', 'https://', 'R$ '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        // One *pinned first-party* image is allowed; all additional img/src
        // surfaces remain forbidden even in this static demonstration.
        self::assertSame(1, substr_count($html, '<img '));
        self::assertSame(1, substr_count($html, 'src="/assets/logo-imagine.png"'));
        self::assertSame(1, substr_count($html, 'src='));
        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(['#pi-content'], $links[1]);
        $after = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $after[] = [$route->uri(), $route->methods()];
        }
        self::assertSame($before, $after);
        self::assertFalse($this->app['session']->isStarted());
    }
}
