<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoClientOverview;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class PortalOverviewConsistencyPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_overview_uses_consistent_three_three_three_counts(): void
    {
        $html = view('portal.demo.overview')->render();
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($doc);
        foreach (['Serviços no exemplo', 'Solicitações de exemplo', 'Contratos ilustrativos'] as $label) {
            $query = '//h2[normalize-space(.)="'.$label.'"]/following-sibling::p[contains(@class, "pi-number")][1]';
            $nodes = $xpath->query($query);
            self::assertCount(1, $nodes);
            self::assertSame('3', trim($nodes->item(0)->textContent));
        }
        self::assertStringContainsString('DEMONSTRAÇÃO — registros fictícios', $html);
        self::assertStringContainsString('Acompanhamento unificado — somente exemplo', $html);
        self::assertStringContainsString('Resumo integrado fictício — sem vínculo operacional', $html);
        self::assertStringNotContainsString('Resumo ilustrativo indisponível', $html);
    }

    public function test_overview_renders_each_service_once_in_the_unified_table(): void
    {
        $html = view('portal.demo.overview')->render();

        foreach (DemoClientOverview::rows() as $row) {
            foreach ($row as $cell) {
                self::assertStringContainsString(e($cell), $html);
            }
            self::assertSame(1, substr_count($html, '<td>'.$row[0].'</td>'));
        }
        self::assertSame(4, substr_count($html, 'scope="col"'));
        self::assertStringContainsString('Sites Imagine · Onepage', $html);
        self::assertStringContainsString('Sites Imagine · Landing Page', $html);
        self::assertStringContainsString('Performance AI', $html);
    }

    public function test_aggregation_creates_no_customer_or_contract_operations(): void
    {
        $html = view('portal.demo.overview')->render();

        foreach (['<form', '<input', '<button', '<script', '<iframe', 'action=', 'src=', 'http://', 'https://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(['#pi-content'], $links[1]);
        self::assertFalse($this->app['session']->isStarted());
        self::assertFalse(DemoClientOverview::summary()->authorizesServiceAccess());
        self::assertFalse(DemoClientOverview::summary()->authorizesContractOrPayment());
    }
}
