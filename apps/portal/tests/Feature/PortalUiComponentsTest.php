<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalUiComponentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fail if rendering ever attempts to acquire a database connection.
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_all_five_unmounted_screens_render_semantic_demo_content(): void
    {
        foreach (['overview', 'services', 'available', 'contracts', 'modules'] as $screen) {
            $html = view('portal.demo.'.$screen)->render();
            self::assertStringContainsString('lang="pt-BR"', $html);
            self::assertStringContainsString('name="viewport"', $html);
            self::assertStringContainsString('<main id="pi-content"', $html);
            self::assertStringContainsString('href="#pi-content"', $html);
            self::assertStringContainsString('aria-current="page"', $html);
            self::assertStringContainsString('Informações exclusivamente fictícias', $html);
            self::assertStringContainsString('<nav aria-label=', $html);
            self::assertStringContainsString('<footer>', $html);
            self::assertStringNotContainsString('<form', $html);
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('<iframe', $html);
            self::assertStringNotContainsString('src=', $html);
            preg_match_all('/href="([^"]*)"/', $html, $links);
            self::assertSame(['#pi-content'], $links[1]);
        }
    }

    public function test_dynamic_component_text_is_escaped_in_every_data_surface(): void
    {
        $attack = '"><img src=x onerror=alert(1)>';
        $templates = [
            '<x-portal.header :title="$attack" :subtitle="$attack" />',
            '<x-portal.badge :label="$attack" :tone="$attack" />',
            '<x-portal.message :title="$attack" :message="$attack" :tone="$attack" />',
            '<x-portal.indicator :label="$attack" :value="$attack" :detail="$attack" />',
            '<x-portal.service-card :name="$attack" :description="$attack" :status="$attack" />',
            '<x-portal.empty-state :title="$attack" :message="$attack" />',
            '<x-portal.loading :label="$attack" />',
            '<x-portal.unavailable :feature="$attack" />',
            '<x-portal.list :label="$attack" :items="[$attack]" />',
            '<x-portal.table :caption="$attack" :columns="[$attack]" :rows="[[$attack]]" />',
            '<x-portal.panel :title="$attack">{{ $attack }}</x-portal.panel>',
            '<x-portal.layout :title="$attack" :current="$attack">{{ $attack }}</x-portal.layout>',
        ];
        foreach ($templates as $template) {
            $html = Blade::render($template, compact('attack'));
            self::assertStringContainsString(e($attack), $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString($attack, $html);
        }
    }

    public function test_table_keeps_semantic_columns_and_explains_horizontal_scrolling(): void
    {
        $html = view('portal.demo.contracts')->render();
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('tabindex="0"', $html);
        self::assertStringContainsString('role a tabela horizontalmente', $html);
        self::assertStringContainsString('<caption>Contratos fictícios', $html);
        self::assertSame(4, substr_count($html, 'scope="col"'));
        self::assertStringContainsString('Assinatura', $html);
    }

    public function test_status_palette_rejects_unrecognized_css_values(): void
    {
        $html = Blade::render('<x-portal.badge label="Exemplo" :tone="$tone" />', ['tone' => 'error injected']);
        self::assertStringContainsString('pi-tone-neutral', $html);
        self::assertStringNotContainsString('injected', $html);
        $html = Blade::render('<x-portal.message title="Aviso" message="Exemplo" tone="error" />');
        self::assertStringContainsString('role="alert"', $html);
    }

    public function test_empty_table_list_and_loading_have_readable_states(): void
    {
        $html = Blade::render('<x-portal.table caption="Exemplo" :columns="[\'Serviço\']" /><x-portal.list label="Exemplo" /><x-portal.loading />');
        self::assertStringContainsString('Nenhum registro demonstrativo', $html);
        self::assertStringContainsString('Nenhum item demonstrativo', $html);
        self::assertStringContainsString('scope="col"', $html);
        self::assertStringContainsString('role="region"', $html);
        self::assertStringContainsString('tabindex="0"', $html);
        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
    }

    public function test_crm_is_explicitly_independent_and_has_no_access_link(): void
    {
        $html = view('portal.demo.modules')->render();
        self::assertStringContainsString('Login e sessão próprios', $html);
        self::assertStringContainsString('sem compartilhamento de credenciais ou sessão com o Portal', $html);
        self::assertStringNotContainsString('https://', $html);
        self::assertStringNotContainsString('action=', $html);
    }

    public function test_rendering_does_not_change_routes_or_start_a_session(): void
    {
        $routesBefore = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $routesBefore[] = [$route->methods(), $route->uri(), $route->getActionName()];
        }
        foreach (['overview', 'services', 'available', 'contracts', 'modules'] as $screen) {
            view('portal.demo.'.$screen)->render();
        }
        $routesAfter = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $routesAfter[] = [$route->methods(), $route->uri(), $route->getActionName()];
        }
        self::assertSame($routesBefore, $routesAfter);
        // Existing framework routes: health-check and the local disk's signed file endpoint.
        // The filesystem already has serve=true; rendering must not add any routes.
        self::assertSame(['up', 'storage/{path}'], array_values(array_unique(array_column($routesAfter, 1))));
        self::assertFalse($this->app['session']->isStarted());
    }
}
