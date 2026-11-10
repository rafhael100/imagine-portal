<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoContractOverview;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalContractsPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->createMock(DatabaseManager::class);
        $db->expects($this->never())->method('connection');
        $this->app->instance('db', $db);
    }

    public function test_contract_preview_renders_each_independent_service_without_claiming_signature(): void
    {
        $html = view('portal.demo.contracts')->render();

        foreach (DemoContractOverview::rows() as $row) {
            foreach ($row as $cell) {
                self::assertStringContainsString(e($cell), $html);
            }
        }
        self::assertStringContainsString('sem validade jurídica', $html);
        self::assertStringContainsString('não comprova compra, assinatura', $html);
        self::assertStringContainsString('A assinatura de contratos e os pagamentos', $html);
        self::assertSame(4, substr_count($html, 'scope="col"'));
        self::assertSame(3, substr_count($html, 'Não realizada'));
    }

    public function test_contract_preview_does_not_open_any_write_or_navigation_surface(): void
    {
        $html = view('portal.demo.contracts')->render();
        foreach (['<form', '<button', '<input', '<script', '<iframe', 'action=', 'src=', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(['#pi-content'], $links[1]);
    }
}
