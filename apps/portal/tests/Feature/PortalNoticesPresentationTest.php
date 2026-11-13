<?php

namespace Tests\Feature;

use App\Presentation\ClientSection;
use App\Presentation\DemoNotice;
use App\Presentation\DemoNoticeCatalog;
use App\Presentation\NoticeCategory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalNoticesPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db', $database);
    }

    private function document(string $html): \DOMXPath
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

    public function test_multiple_categories_render_as_an_ordered_static_list_without_active_navigation(): void
    {
        $html = view('portal.demo.notices')->render();
        self::assertStringContainsString('Central de Avisos — DEMONSTRAÇÃO', $html);
        self::assertStringContainsString('Empresa Aurora fictícia', $html);
        self::assertStringContainsString('aria-label="Lista de avisos fictícios"', $html);
        self::assertStringNotContainsString('aria-current', $html);
        $xpath = $this->document($html);
        $titles = $xpath->query('//section[@aria-label="Lista de avisos fictícios"]//li/article/h3');
        $catalog = DemoNoticeCatalog::examples();
        self::assertSame(count($catalog->notices), $titles->length);
        foreach ($catalog->notices as $index => $notice) {
            self::assertSame($notice->title, trim($titles->item($index)->textContent));
            self::assertStringContainsString($notice->body, $html);
            self::assertStringContainsString($notice->category->label(), $html);
        }
    }

    public function test_malicious_titles_bodies_context_and_category_codes_are_safe(): void
    {
        $attack = '"><img src=x onerror=alert(1)>';
        $notice = new DemoNotice('demo_notice_xss', NoticeCategory::fromCode($attack), $attack, $attack);
        $catalog = new DemoNoticeCatalog([$notice]);
        $html = Blade::render('<x-portal.notice-list :catalog="$catalog" :title="$attack" /><x-portal.notice-context :title="$attack" :message="$attack" />', compact('catalog', 'attack'));
        self::assertGreaterThanOrEqual(5, substr_count($html, e($attack)));
        self::assertStringContainsString('Aviso geral ilustrativo', $html);
        self::assertStringContainsString('pi-tone-neutral', $html);
        self::assertStringNotContainsString($attack, $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function test_full_screen_supports_an_empty_catalog_without_delivery_or_read_claims(): void
    {
        $html = view('portal.demo.notices', ['catalog' => new DemoNoticeCatalog()])->render();
        self::assertStringContainsString('Nenhum aviso fictício', $html);
        self::assertStringContainsString('não representa uma caixa de notificações reais', $html);
        self::assertStringContainsString('Nenhum aviso foi enviado', $html);
        self::assertStringNotContainsString('<article', $html);
        self::assertStringNotContainsString('aria-pressed', $html);
        self::assertStringNotContainsString('<button', $html);
    }

    public function test_existing_views_and_notices_preserve_catalog_navigation_routes_and_session(): void
    {
        $snapshot = fn () => array_map(
            fn ($route) => [$route->methods(), $route->uri(), $route->getActionName()],
            iterator_to_array($this->app['router']->getRoutes()),
        );
        $routesBefore = $snapshot();
        $sectionsBefore = ClientSection::cases();
        $labels = array_map(fn ($section) => $section->label(), $sectionsBefore);
        foreach ([...array_column($sectionsBefore, 'value'), 'notices'] as $screen) {
            $html = view('portal.demo.'.$screen)->render();
            self::assertStringContainsString('<main id="pi-content"', $html);
            foreach (['<form', '<input', '<button', '<script', '<iframe', '<details', 'action=', 'http://', 'https://'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $html);
            }
            self::assertSame(1, substr_count($html, '<img '));
            self::assertSame(1, substr_count($html, 'src="/assets/logo-imagine.png"'));
            $xpath = $this->document($html);
            $items = $xpath->query('//nav/ul/li/span');
            self::assertSame(count($sectionsBefore), $items->length);
            self::assertSame($labels, array_map(fn ($node) => trim($node->textContent), iterator_to_array($items)));
            $active = $xpath->query('//nav//*[@aria-current]');
            if ($screen === 'notices') {
                self::assertSame(0, $active->length);
            } else {
                self::assertSame(1, $active->length);
                self::assertSame('page', $active->item(0)->getAttribute('aria-current'));
                self::assertSame(ClientSection::from($screen)->label(), trim($active->item(0)->textContent));
            }
            preg_match_all('/href="([^"]*)"/', $html, $links);
            self::assertSame(['#pi-content'], $links[1]);
        }
        self::assertSame($routesBefore, $snapshot());
        self::assertSame(['up'], array_values(array_unique(array_column($routesBefore, 1))));
        self::assertSame($sectionsBefore, ClientSection::cases());
        self::assertNull(ClientSection::tryFrom('notices'));
        self::assertNotContains('notices', array_column($sectionsBefore, 'value'));
        self::assertFalse($this->app['session']->isStarted());
    }

    public function test_product_boundaries_and_future_media_concept_have_no_operational_commitment(): void
    {
        $html = view('portal.demo.notices')->render();
        foreach (['Onepage é independente da Landing Page', 'motor de execução separado do Portal',
            'não há métricas reais ou conexão', 'identidade, login e sessão próprios, sem SSO',
            'benefício de clientes elegíveis, não assinatura avulsa', 'não determina elegibilidade nem concede acesso',
            'não replica notificações do CRM', 'sem envio ou estado de leitura persistido',
            'Nenhum aviso foi enviado', 'não há envio por SMS, WhatsApp ou e-mail',
        ] as $text) {
            self::assertStringContainsString($text, $html);
        }
        $media = array_values(array_filter(DemoNoticeCatalog::examples()->notices, fn ($notice) => $notice->category === NoticeCategory::MediaCredit));
        self::assertCount(1, $media);
        self::assertStringContainsString('conceito futuro de Performance AI', $media[0]->body);
        self::assertStringContainsString('Não representa pagamento, saldo, valor, calendário, recarga agendada ou disparo', $media[0]->body);
        self::assertStringContainsString('decisões próprias do produto', $media[0]->body);
        self::assertDoesNotMatchRegularExpression('/\\b\\d+\\s*dias\\b/ui', $media[0]->body);
        self::assertStringNotContainsString('R$', $html);
        self::assertStringNotContainsString('datetime=', $html);
    }
}
