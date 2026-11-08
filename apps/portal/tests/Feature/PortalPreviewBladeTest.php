<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Blade-only client portal preview: no route, session, identity, DB or API.
 * Verifies rendered HTML rather than pretending to exercise real UX flows.
 */
final class PortalPreviewBladeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = $this->createMock(DatabaseManager::class);
        $database->expects(self::never())->method('connection');
        $this->app->instance('db', $database);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }

    public function test_showcase_renders_six_navigable_and_accessible_static_sections(): void
    {
        $html = view('portal.preview.showcase')->render();
        $xpath = $this->xpath($html);

        self::assertStringContainsString('lang="pt-BR"', $html);
        self::assertStringContainsString('DADOS 100% FICTÍCIOS', $html);
        self::assertStringContainsString('Empresa Aurora · fictícia', $html);
        self::assertStringContainsString('href="#pv-content"', $html);

        $sections = ['inicio', 'servicos', 'performance', 'solicitacoes', 'universidade', 'financeiro'];
        $links = $xpath->query('//nav[@aria-label="Navegação entre seções da prévia"]/a');
        $panels = $xpath->query('//main/section[contains(concat(" ", normalize-space(@class), " "), " pv-section ")]');

        self::assertCount(count($sections), iterator_to_array($links));
        self::assertCount(count($sections), iterator_to_array($panels));

        foreach ($sections as $index => $section) {
            self::assertSame('#pv-'.$section, $links->item($index)->getAttribute('href'));
            self::assertSame('pv-'.$section, $panels->item($index)->getAttribute('id'));
            self::assertSame('pv-heading-'.$section, $panels->item($index)->getAttribute('aria-labelledby'));
            self::assertSame(1, $xpath->query('//h1[@id="pv-heading-'.$section.'"]')->length);
        }
    }

    public function test_rendering_does_not_enable_live_financial_ads_or_customer_capabilities(): void
    {
        $html = view('portal.preview.showcase')->render();

        foreach (['<script', '<form', '<input', '<iframe', 'fetch(', 'XMLHttpRequest',
            'localStorage', 'sessionStorage', 'WebSocket', '<img', 'https://', 'http://'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $html);
        }

        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(
            ['#pv-content', '#pv-inicio', '#pv-servicos', '#pv-performance', '#pv-solicitacoes', '#pv-universidade', '#pv-financeiro'],
            $links[1]
        );

        self::assertStringContainsString("connect-src 'none'", $html);
        self::assertStringContainsString("form-action 'none'", $html);
        self::assertStringContainsString("script-src 'none'", $html);
        self::assertStringContainsString('Sem transações', $html);
        self::assertStringContainsString('Sem SSO ou acesso ao CRM', $html);
        self::assertStringContainsString('Nenhuma conta de anúncios está conectada', $html);
        self::assertStringContainsString('Nenhuma inscrição ou elegibilidade confirmada', $html);
    }

    public function test_dynamic_component_text_is_escaped_and_anchor_allowlist_is_closed(): void
    {
        $attack = '"><img src=x onerror=alert(1)>';
        $html = Blade::render(
            '<x-portal.preview.nav-item :anchor="$attack" :label="$attack" />'
            .'<x-portal.preview.metric :label="$attack" :value="$attack" :detail="$attack" />'
            .'<x-portal.preview.service-card :name="$attack" :description="$attack" :status="$attack" />'
            .'<x-portal.preview.section :anchor="$attack" :title="$attack" :summary="$attack" :tag="$attack">{{ $attack }}</x-portal.preview.section>',
            compact('attack'),
        );

        self::assertGreaterThanOrEqual(10, substr_count($html, e($attack)));
        self::assertStringNotContainsString($attack, $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('href="#pv-inicio"', $html);
        self::assertStringContainsString('id="pv-inicio"', $html);
        self::assertStringNotContainsString('pv-'.e($attack), $html);
    }

    public function test_new_blade_preview_does_not_change_existing_routes_or_start_a_session(): void
    {
        $snapshot = static fn ($router): array => array_map(
            static fn ($route) => [$route->methods(), $route->uri(), $route->getActionName()],
            iterator_to_array($router->getRoutes()),
        );

        $before = $snapshot($this->app['router']);

        view('portal.preview.showcase')->render();

        self::assertSame($before, $snapshot($this->app['router']));
        self::assertSame(['up', 'storage/{path}'], array_values(array_unique(array_column($before, 1))));
        self::assertFalse($this->app['session']->isStarted());
    }

    public function test_showcase_uses_only_static_blade_components_and_no_customer_actions(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/portal/preview/showcase.blade.php'
        );

        foreach (['@auth', '@guest', '@can', 'route(', 'url(', 'action=', 'method=', 'onclick=', '@csrf'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }

        foreach (['preview.shell', 'preview.section', 'preview.metric', 'preview.service-card'] as $component) {
            self::assertStringContainsString('<x-portal.'.$component, $source);
        }

        self::assertSame(6, substr_count($source, '<x-portal.preview.section '));
    }
}
