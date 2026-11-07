<?php

namespace Tests\Feature;

use App\Presentation\ClientSection;
use App\Presentation\DemoHelpCatalog;
use App\Presentation\HelpCategory;
use App\Presentation\HelpTopic;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalHelpPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_help_renders_semantic_static_guidance_without_registering_a_navigation_item(): void
    {
        $html = view('portal.demo.help')->render();
        self::assertStringContainsString('Central de Ajuda · DEMONSTRAÇÃO', $html);
        self::assertStringContainsString('lang="pt-BR"', $html);
        self::assertStringContainsString('aria-label="Tópicos demonstrativos de ajuda"', $html);
        self::assertStringContainsString('Informações exclusivamente fictícias', $html);
        self::assertStringContainsString('Empresa Aurora fictícia', $html);
        self::assertStringNotContainsString('aria-current="page"', $html);
        foreach (DemoHelpCatalog::examples()->topics as $topic) {
            self::assertStringContainsString('<h3>'.$topic->title.'</h3>', $html);
            self::assertStringContainsString($topic->guidance, $html);
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $items = $xpath->query('//nav/ul/li/span');
        self::assertSame(6, $items->length);
        foreach (ClientSection::cases() as $index => $section) {
            self::assertSame($section->label(), trim($items->item($index)->textContent));
        }
        self::assertNull(ClientSection::tryFrom('help'));
    }

    public function test_malicious_text_is_escaped_and_unknown_category_is_neutral(): void
    {
        $attack = '"><img src=x onerror=alert(1)>';
        $topic = new HelpTopic('demo_help_xss', HelpCategory::fromCode($attack), $attack, $attack);
        $catalog = new DemoHelpCatalog([$topic]);
        $html = Blade::render('<x-portal.help-topic-list :catalog="$catalog" :title="$attack" /><x-portal.help-context :title="$attack" :message="$attack" />', compact('catalog', 'attack'));
        self::assertGreaterThanOrEqual(5, substr_count($html, e($attack)));
        self::assertStringContainsString('Orientação geral', $html);
        self::assertStringContainsString('pi-tone-neutral', $html);
        self::assertStringNotContainsString($attack, $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function test_empty_catalog_has_readable_non_operational_state(): void
    {
        $html = Blade::render('<x-portal.help-topic-list :catalog="$catalog" />', ['catalog' => new DemoHelpCatalog()]);
        self::assertStringContainsString('Nenhuma orientação fictícia', $html);
        self::assertStringContainsString('Nenhum atendimento está disponível', $html);
        self::assertStringNotContainsString('<article', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('href=', $html);
    }

    public function test_six_existing_views_and_help_keep_routes_navigation_and_session_unchanged(): void
    {
        $snapshot = fn () => array_map(
            fn ($route) => [$route->methods(), $route->uri(), $route->getActionName()],
            iterator_to_array($this->app['router']->getRoutes()),
        );
        $routesBefore = $snapshot();
        $sectionsBefore = ClientSection::cases();
        foreach ([...array_column($sectionsBefore, 'value'), 'help'] as $screen) {
            $html = view('portal.demo.'.$screen)->render();
            self::assertStringContainsString('<main id="pi-content"', $html);
            foreach (['<form', '<input', '<button', '<script', '<iframe', '<details', 'src=', 'action=', 'http://', 'https://'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $html);
            }
            preg_match_all('/href="([^"]*)"/', $html, $links);
            self::assertSame(['#pi-content'], $links[1]);
        }
        self::assertSame($routesBefore, $snapshot());
        self::assertSame(['up', 'storage/{path}'], array_values(array_unique(array_column($routesBefore, 1))));
        self::assertSame($sectionsBefore, ClientSection::cases());
        self::assertCount(6, ClientSection::cases());
        self::assertFalse($this->app['session']->isStarted());
    }

    public function test_product_boundaries_and_illustrative_only_answers_are_explicit(): void
    {
        $html = view('portal.demo.help')->render();
        foreach ([
            'Onepage é um serviço independente da Landing Page',
            'Performance AI é um serviço próprio, com motor de execução separado',
            'não há métricas reais, GA4',
            'identidade, login e sessão próprios, sem SSO',
            'benefício de clientes elegíveis, não uma assinatura avulsa',
            'A Central de Ajuda do Portal não é um ambiente de cursos',
            'não determina elegibilidade nem concede acesso',
            'Não são respostas oficiais nem atendimento disponível',
            'Nenhum atendimento, resposta ou SLA é prometido',
            'Nenhum contrato, assinatura, preço, pagamento, reembolso ou direito é confirmado',
            'Ler uma orientação não comprova acesso, estado de serviço ou elegibilidade',
        ] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }
}
