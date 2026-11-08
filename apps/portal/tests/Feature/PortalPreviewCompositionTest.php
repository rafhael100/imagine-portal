<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\PreviewArea;
use App\Presentation\PreviewText;
use App\View\Components\Portal\Preview\{Metric, NavItem, Section, ServiceCard, Shell};
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\{Blade, Http};
use InvalidArgumentException;
use Tests\TestCase;

final class PortalPreviewCompositionTest extends TestCase
{
    public function test_catalog_drives_menu_order_and_each_typed_section_destination(): void
    {
        $areas = PreviewArea::cases();
        self::assertSame(['inicio', 'servicos', 'performance', 'solicitacoes', 'universidade', 'financeiro'], array_column($areas, 'value'));
        $html = view('portal.preview.showcase')->render();
        $previous = -1;

        foreach ($areas as $area) {
            $position = strpos($html, 'href="#pv-'.$area->value.'"');
            self::assertNotFalse($position);
            self::assertGreaterThan($previous, $position);
            $previous = $position;
            self::assertStringContainsString($area->label(), $html);
            self::assertSame(1, substr_count($html, 'id="pv-'.$area->value.'"'));
            $fragment = Blade::render(
                '<x-portal.preview.section :anchor="$area" :title="$title" summary="Exemplo fictício"><x-portal.preview.metric label="Exemplo" value="00" /></x-portal.preview.section>',
                ['area' => $area, 'title' => $area->label()],
            );
            self::assertStringContainsString('id="pv-'.$area->value.'"', $fragment);
            self::assertStringContainsString('aria-labelledby="pv-heading-'.$area->value.'"', $fragment);
            self::assertStringContainsString('<article class="pv-card">', $fragment);
            self::assertSame($area, PreviewArea::resolve($area->value));
        }
    }

    public function test_shell_and_nested_slots_escape_dynamic_text_without_forwarding_arbitrary_attributes(): void
    {
        $attack = '\"><script>alert(1)</script><img src=x onerror=alert(1)>';
        $html = Blade::render(
            '<x-portal.preview.shell :title="$attack" :heading="$attack" :organization-label="$attack" onclick="evil()">'
            .'<x-portal.preview.section anchor="servicos" title="Fictício" summary="Somente exemplo" onclick="evil()"><p>{{ $attack }}</p>'
            .'<x-portal.preview.service-card :name="$attack" :description="$attack" :status="$attack" onclick="evil()" />'
            .'<x-portal.preview.metric :label="$attack" value="00" :detail="$attack" onclick="evil()" />'
            .'<x-portal.preview.nav-item anchor="https://invalid.example" :label="$attack" onclick="evil()" />'
            .'</x-portal.preview.section></x-portal.preview.shell>',
            compact('attack'),
        );
        self::assertSame(10, substr_count($html, e($attack)));
        foreach (['<script', '<img', 'onclick=', 'evil()', 'https://invalid.example'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $html);
        }
        self::assertStringContainsString('<p>'.e($attack).'</p>', $html);
        self::assertStringContainsString('href="#pv-inicio"', $html);
        self::assertStringContainsString('DADOS 100% FICTÍCIOS', $html);
    }

    public function test_each_component_rejects_invalid_display_props(): void
    {
        $factories = [
            static fn () => new Shell(title: ''),
            static fn () => new Shell(heading: '  '),
            static fn () => new Shell(organizationLabel: str_repeat('x', 241)),
            static fn () => new NavItem(PreviewArea::Overview, ''),
            static fn () => new Section(PreviewArea::Overview, '', 'Resumo'),
            static fn () => new Section('inicio', 'Título', str_repeat('x', 1601)),
            static fn () => new Section('inicio', 'Título', 'Resumo', ''),
            static fn () => new Metric('', '00'),
            static fn () => new Metric('Métrica', str_repeat('x', 81)),
            static fn () => new Metric('Métrica', '00', ''),
            static fn () => new ServiceCard('', 'Descrição'),
            static fn () => new ServiceCard('Serviço', ''),
            static fn () => new ServiceCard('Serviço', 'Descrição', str_repeat('x', 201)),
        ];
        foreach ($factories as $factory) {
            try {
                $factory();
                self::fail('Invalid component props must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Expected non-empty bounded preview text.', $exception->getMessage());
            }
        }
        self::assertSame('  Fictício  ', PreviewText::validated('  Fictício  ', 80));
        self::assertSame(str_repeat('x', 80), PreviewText::validated(str_repeat('x', 80), 80));
    }

    public function test_composition_never_connects_to_database_sends_http_or_starts_session_or_routes(): void
    {
        $database = $this->createMock(DatabaseManager::class);
        $database->expects(self::never())->method('connection');
        $this->app->instance('db', $database);
        Http::preventStrayRequests();
        Http::fake();
        $routes = array_map(static fn ($route) => [$route->methods(), $route->uri(), $route->getActionName()], iterator_to_array($this->app['router']->getRoutes()));
        foreach (PreviewArea::cases() as $area) {
            Blade::render('<x-portal.preview.section :anchor="$area" title="Fictício" summary="Demonstração">Conteúdo fictício</x-portal.preview.section>', compact('area'));
        }
        view('portal.preview.showcase')->render();
        Http::assertNothingSent();
        self::assertFalse($this->app['session']->isStarted());
        self::assertSame($routes, array_map(static fn ($route) => [$route->methods(), $route->uri(), $route->getActionName()], iterator_to_array($this->app['router']->getRoutes())));
    }
}
