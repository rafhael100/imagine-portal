<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\ServicePortfolio\PortfolioPreviewItem;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Presentation\DemoServicePortfolio;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalServicePortfolioPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_catalog_renders_all_five_product_cards_with_a_closed_checkout(): void
    {
        $html = view('portal.demo.available')->render();

        self::assertSame(5, substr_count($html, 'class="pi-panel pi-service"'));
        foreach (DemoServicePortfolio::items() as $item) {
            self::assertStringContainsString('<h2>'.e($item->product->label()).'</h2>', $html);
            self::assertStringContainsString(e($item->description), $html);
            self::assertStringContainsString(e($item->statusDetail()), $html);
        }
        self::assertSame(5, substr_count($html, 'Não contratável'));
        self::assertStringContainsString('DEMONSTRAÇÃO — catálogo de serviços fictício', $html);
        self::assertStringContainsString('A contratação de serviços', $html);
        self::assertStringContainsString('sem SSO com o Portal', $html);
        self::assertStringContainsString('não assinatura avulsa', $html);
        self::assertStringContainsString('Nenhum acesso ou direito é concedido', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function test_catalog_is_not_an_order_form_web_api_or_active_service(): void
    {
        $html = view('portal.demo.available')->render();
        foreach (['<form', '<input', '<button', '<script', '<iframe', 'action=',
            'http://', 'https://', 'R$ ', 'checkout', 'window.location'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        // One *pinned first-party* image is allowed; all additional img/src
        // surfaces remain forbidden even in this static demonstration.
        self::assertSame(1, substr_count($html, '<img '));
        self::assertSame(1, substr_count($html, 'src="/assets/logo-imagine.png"'));
        self::assertSame(1, substr_count($html, 'src='));
        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(['#pi-content'], $links[1]);
        self::assertFalse($this->app['session']->isStarted());
    }

    public function test_dynamic_descriptions_are_html_escaped_even_when_attacker_controlled(): void
    {
        $attack = '"><img src=x onerror=alert(1)>';
        $item = new PortfolioPreviewItem(PortfolioProduct::Onepage, $attack);
        $html = Blade::render(
            '<x-portal.service-card :name="$item->product->label()" :description="$item->description" :status="$item->statusLabel()" />',
            compact('item'),
        );
        self::assertStringContainsString(e($attack), $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString($attack, $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function test_catalog_is_rendered_without_registering_new_routes_or_sessions(): void
    {
        $before = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $before[] = [$route->uri(), $route->methods()];
        }

        view('portal.demo.available')->render();

        $after = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $after[] = [$route->uri(), $route->methods()];
        }
        self::assertSame($before, $after);
        self::assertFalse($this->app['session']->isStarted());
    }
}
