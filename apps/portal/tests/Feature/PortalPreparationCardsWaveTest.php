<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoPreparationCards;
use App\Presentation\DemoServicePreparation;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalPreparationCardsWaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createMock(DatabaseManager::class);
        $database->expects(self::never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_all_cards_are_reconciled_with_the_existing_preparation_table(): void
    {
        $rows = DemoServicePreparation::rows();
        $cards = DemoPreparationCards::cards();
        self::assertCount(3, $cards);
        self::assertSame(array_column($rows, 0), array_column($cards, 'product'));
        self::assertSame(array_column($rows, 1), array_column($cards, 'service'));
        self::assertSame(array_column($rows, 3), array_column($cards, 'request'));
        foreach ($cards as $card) {
            self::assertCount(3, $card['requirements']);
            self::assertStringContainsString('exemplo', $card['next']);
        }
    }

    public function test_preparation_page_renders_three_readable_service_cards_without_actions(): void
    {
        $html = view('portal.demo.preparation')->render();
        self::assertStringContainsString('Fichas individuais de preparação fictícia', $html);
        self::assertStringContainsString('Etapas e requisitos por serviço', $html);
        self::assertSame(3, substr_count($html, 'SERVIÇO DE EXEMPLO'));
        foreach ([
            'Sites Imagine · Onepage', 'Sites Imagine · Landing Page',
            'Performance AI', 'Próximo passo ilustrativo:',
            'Conferir objetivo, formulário e consentimento',
            'Validar contas, criativos e orçamento',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringContainsString('noindex,nofollow', $html);
        self::assertStringContainsString('src="/assets/logo-imagine.png"', $html);
        foreach (['<form', '<input', '<script', '<iframe', 'http://', 'https://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        self::assertFalse(DemoPreparationCards::authorizesServiceAction());
        $this->get('/portal/preparation')->assertNotFound();
        self::assertFalse($this->app['session']->isStarted());
    }

    public function test_duplicate_or_foreign_service_rows_fail_closed(): void
    {
        $rows = DemoServicePreparation::rows();
        $rows[1] = $rows[0];
        self::assertSame([], DemoPreparationCards::fromRows($rows));
        $rows = DemoServicePreparation::rows();
        $rows[2][1] = 'DEMO-SVC-OUTSIDER';
        self::assertSame([], DemoPreparationCards::fromRows($rows));
    }

    public function test_wrong_request_reference_and_unknown_status_fail_closed(): void
    {
        $rows = DemoServicePreparation::rows();
        $rows[1][3] = 'DEMO-REQ-ONEPAGE';
        self::assertSame([], DemoPreparationCards::fromRows($rows));
        $rows = DemoServicePreparation::rows();
        $rows[1][4] = 'Campanha ativada';
        self::assertSame([], DemoPreparationCards::fromRows($rows));
    }

    public function test_malformed_requirements_and_partial_inventory_fail_closed(): void
    {
        $rows = DemoServicePreparation::rows();
        $rows[2][5] = 'Objetivos; ; Orçamento';
        self::assertSame([], DemoPreparationCards::fromRows($rows));
        $rows = DemoServicePreparation::rows();
        array_pop($rows);
        self::assertSame([], DemoPreparationCards::fromRows($rows));
        self::assertSame([], DemoPreparationCards::fromRows([1 => $rows[0]]));
    }
}
