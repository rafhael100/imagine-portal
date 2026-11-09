<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class PviewPerformanceDetailTest extends TestCase
{
    public function test_performance_view_is_synthetic_and_explicitly_not_operational(): void
    {
        $html = view('portal.demo.pview-navigation')->render();

        foreach ([
            'data-pview-perf="offline-demo"',
            'DEMONSTRAÇÃO OFFLINE / LARAVEL',
            'Campanhas sob gestão — simulação',
            'Google Ads',
            'Meta (Instagram e/ou Facebook)',
            'Pausada (DEMO)',
            'Capacidade: indefinida',
            'Central de aprovações ilustrativa',
            'Sem execução',
            'Relatórios ilustrativos',
            'Números fictícios',
            'Nenhuma campanha conectada',
        ] as $text) {
            // The old placeholder is intentionally replaced, so one assertion
            // checks replacement separately below rather than here.
            if ($text === 'Nenhuma campanha conectada') {
                $this->assertStringNotContainsString($text, $html);
                continue;
            }
            $this->assertStringContainsString($text, $html);
        }

        $this->assertSame(8, preg_match_all('/data-screen="[a-z]+"/', $html));
        $this->assertSame(8, preg_match_all('/data-nav="[a-z]+"/', $html));
        $this->assertSame(1, substr_count($html, 'data-pview-perf="offline-demo"'));
        $this->assertSame(2, substr_count($html, '<details'));
    }

    public function test_performance_preview_does_not_add_actions_or_network_access(): void
    {
        $html = view('portal.demo.pview-navigation')->render();

        foreach (['<form', '<input', '<button', '<iframe', '<script src',
            'fetch(', 'XMLHttpRequest', 'WebSocket', 'navigator.sendBeacon',
            'Google Ads API', 'Meta Marketing API'] as $notAllowed) {
            $this->assertStringNotContainsString($notAllowed, $html);
        }

        $this->get('/performance')->assertNotFound();
        $this->get('/portal/performance')->assertNotFound();
    }
}
