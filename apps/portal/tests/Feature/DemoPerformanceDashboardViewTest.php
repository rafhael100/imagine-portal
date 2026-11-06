<?php

namespace Tests\Feature;

use App\Domain\Catalog\PerformanceChannel;
use App\Domain\PerformanceDashboard\DemoCampaignCard;
use App\Domain\PerformanceDashboard\DemoCampaignStage;
use App\Domain\PerformanceDashboard\DemoDashboardFactory;
use Tests\Support\DemoDashboardFixture;
use Tests\TestCase;

final class DemoPerformanceDashboardViewTest extends TestCase
{
    private function dashboard(array $changes = [])
    {
        $f = DemoDashboardFixture::case();
        return (new DemoDashboardFactory())->build(
            $f['proposal'], $f['onboarding'], $f['actor'], $f['organization'], $f['member'],
            $f['metrics'], $changes['campaigns'] ?? $f['campaigns'], $changes['requests'] ?? $f['requests'],
        );
    }

    public function test_unmounted_blade_view_renders_accessible_demo_dashboard_with_explicit_warning(): void
    {
        $dashboard = $this->dashboard();
        self::assertNotNull($dashboard);
        $html = view('performance.demo-dashboard', compact('dashboard'))->render();

        self::assertStringContainsString('lang="pt-BR"', $html);
        self::assertStringContainsString('Dados totalmente simulados', $html);
        self::assertStringContainsString('sem conexão com Google Ads ou Meta', $html);
        self::assertStringContainsString('N/D', $html);
        self::assertStringContainsString('Preço ainda não homologado', $html);
        self::assertStringContainsString('Exemplo de campanha', $html);
        self::assertStringContainsString('Aprovação simulada pendente', $html);
        self::assertStringContainsString('1.280', $html);
        self::assertStringContainsString('synthetic_demo', $html);
        self::assertStringContainsString('aria-labelledby="pd-campaigns-title"', $html);
        self::assertStringNotContainsString('type="submit"', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('href="', $html);
    }

    public function test_html_escaping_prevents_demo_campaign_title_injection(): void
    {
        $f = DemoDashboardFixture::case();
        $unsafe = '<img src=x onerror=alert(1)>';
        $campaign = new DemoCampaignCard('demo_campaign_xss', $f['organization'], PerformanceChannel::GoogleAds,
            $unsafe, DemoCampaignStage::MockDraft);
        $dashboard = $this->dashboard(['campaigns' => [$campaign]]);
        self::assertNotNull($dashboard);
        $html = view('performance.demo-dashboard', compact('dashboard'))->render();
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringNotContainsString($unsafe, $html);
    }

    public function test_empty_demo_tables_show_accessible_empty_states(): void
    {
        $dashboard = $this->dashboard(['campaigns' => [], 'requests' => []]);
        self::assertNotNull($dashboard);
        $html = view('performance.demo-dashboard', compact('dashboard'))->render();
        self::assertStringContainsString('Nenhuma campanha demonstrativa', $html);
        self::assertStringContainsString('Nenhuma solicitação demonstrativa', $html);
    }

    public function test_no_dashboard_login_campaigns_or_checkout_http_routes_are_exposed(): void
    {
        $this->get('/performance')->assertNotFound();
        $this->get('/performance/dashboard')->assertNotFound();
        $this->get('/performance/campaigns')->assertNotFound();
        $this->get('/performance/approvals')->assertNotFound();
        $this->get('/checkout')->assertNotFound();
        $this->get('/login')->assertNotFound();
    }
}
