<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoPreparationCards;
use App\Presentation\DemoServiceRequests;
use App\Presentation\DemoServicePreparation;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalOverviewNextStepsWaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->createMock(DatabaseManager::class);
        $db->expects(self::never())->method('connection');
        $this->app->instance('db', $db);
    }

    public function test_home_shows_linked_next_steps_without_activating_services(): void
    {
        $html = view('portal.demo.overview')->render();
        self::assertStringContainsString('Próximos passos dos serviços — exemplo', $html);
        self::assertStringContainsString('Próximos passos ilustrativos por serviço', $html);
        $cards = DemoPreparationCards::cards();
        self::assertCount(3, $cards);
        self::assertCount(3, DemoServicePreparation::rows());
        foreach ($cards as $card) {
            self::assertStringContainsString($card['product'], $html);
            self::assertStringContainsString($card['request'], $html);
            self::assertStringContainsString($card['next'], $html);
        }
        self::assertSame(1, substr_count($html, 'src="/assets/logo-imagine.png"'));
        self::assertStringContainsString('noindex,nofollow', $html);
        self::assertStringContainsString('--pi-orange:#f9941d', $html);
        foreach (['<form', '<input', '<script', '<iframe', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        self::assertFalse(DemoPreparationCards::authorizesServiceAction());
        $this->get('/portal')->assertNotFound();
        self::assertFalse($this->app['session']->isStarted());
    }

    public function test_malformed_request_reference_cannot_produce_home_followup_cards(): void
    {
        $rows = DemoServicePreparation::fromSources(
            DemoServiceRequests::services(),
            DemoServiceRequests::requests(),
        );
        self::assertCount(3, $rows);
        $rows[0][3] = 'DEMO-REQ-UNRELATED';
        self::assertSame([], DemoPreparationCards::fromRows($rows));
        $rows = DemoServicePreparation::rows();
        array_pop($rows);
        self::assertSame([], DemoPreparationCards::fromRows($rows));
    }
}
