<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoServiceRelationPreview;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalServiceRelationPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->createMock(DatabaseManager::class);
        $db->expects($this->never())->method('connection');
        $this->app->instance('db', $db);
    }

    public function test_service_page_shows_full_validated_fixture_relationship_counts(): void
    {
        $html = view('portal.demo.services')->render();

        self::assertStringContainsString(
            'Relações entre serviços, solicitações e contratos — exemplo', $html,
        );
        self::assertStringContainsString(
            'Projeção ilustrativa que permite vários registros por serviço', $html,
        );
        self::assertStringContainsString(
            'Quantidades vinculadas aos serviços fictícios', $html,
        );
        foreach (DemoServiceRelationPreview::lines() as $line) {
            self::assertStringContainsString(e($line), $html);
        }
        self::assertCount(3, DemoServiceRelationPreview::lines());
        self::assertStringNotContainsString('Relações fictícias indisponíveis', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function test_relation_preview_does_not_enable_any_query_or_mutation_surface(): void
    {
        $before = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $before[] = [$route->methods(), $route->uri()];
        }

        $html = view('portal.demo.services')->render();

        foreach (['<form', '<input', '<button', '<script', '<iframe',
            'action=', 'src=', 'http://', 'https://', 'checkout'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(['#pi-content'], $links[1]);

        $after = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $after[] = [$route->methods(), $route->uri()];
        }
        self::assertSame($before, $after);
        self::assertFalse($this->app['session']->isStarted());
        self::assertFalse(DemoServiceRelationPreview::firstPage()->mayExposeCustomerData());
        self::assertFalse(DemoServiceRelationPreview::firstPage()->grantsUniversityBenefit());
    }
}
