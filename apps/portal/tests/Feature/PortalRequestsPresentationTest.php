<?php

namespace Tests\Feature;

use App\Presentation\DemoServiceRequests;
use App\Presentation\IllustrativeUpdate;
use App\Presentation\JourneyState;
use App\Presentation\RequestSummary;
use App\Presentation\ServiceFollowUp;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalRequestsPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_services_and_requests_render_multiple_typed_fictional_records(): void
    {
        $services = view('portal.demo.services')->render();
        $requests = view('portal.demo.requests')->render();
        foreach (DemoServiceRequests::services() as $service) {
            self::assertStringContainsString($service->reference, $services);
            self::assertStringContainsString($service->product, $services);
            self::assertStringContainsString($service->nextUpdate, $services);
            self::assertStringContainsString($service->state->label(), $services);
        }
        foreach (DemoServiceRequests::requests() as $request) {
            self::assertStringContainsString($request->reference, $requests);
            self::assertStringContainsString($request->serviceReference, $requests);
            self::assertStringContainsString($request->title, $requests);
            self::assertStringContainsString($request->state->label(), $requests);
        }
        self::assertStringContainsString('Próxima atualização ilustrativa', $services);
        self::assertStringContainsString('Sem contratação, elegibilidade, prazo ou SLA real.', $services);
        self::assertStringContainsString('Empresa Aurora fictícia', $requests);
        self::assertStringContainsString('Nenhum chamado foi aberto ou enviado', $requests);
    }

    public function test_all_new_text_surfaces_escape_html_including_malicious_request_titles(): void
    {
        $attack = '"><img src=x onerror=alert(1)>';
        $update = new IllustrativeUpdate($attack, $attack, $attack);
        $service = new ServiceFollowUp('DEMO-SVC-XSS', $attack, JourneyState::Example, $attack, $attack);
        $request = new RequestSummary('DEMO-REQ-XSS', 'DEMO-SVC-XSS', $attack, $attack, JourneyState::Waiting, [$update]);
        $html = Blade::render(
            '<x-portal.service-tracking :title="$attack" :services="[$service]" /><x-portal.request-list :title="$attack" :requests="[$request]" />',
            compact('attack', 'service', 'request'),
        );
        self::assertGreaterThanOrEqual(10, substr_count($html, e($attack)));
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString($attack, $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function test_empty_service_request_and_history_states_are_readable_without_progress_claims(): void
    {
        $html = Blade::render('<x-portal.service-tracking /><x-portal.request-list /><x-portal.update-history />');
        foreach (['Nenhum serviço de exemplo', 'Nenhuma solicitação de exemplo', 'Nenhuma atualização fictícia'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('<progress', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('href=', $html);
    }

    public function test_request_history_has_ordered_semantics_without_duplicating_the_main_journey(): void
    {
        $html = view('portal.demo.requests')->render();
        self::assertStringContainsString('<ul class="pi-request-list">', $html);
        self::assertStringContainsString('<ol class="pi-updates">', $html);
        self::assertStringContainsString('aria-label="Histórico fictício de atualizações"', $html);
        self::assertStringContainsString('Nenhuma atualização fictícia', $html);
        self::assertStringContainsString('sem eventos reais ou registro de auditoria', $html);
        self::assertLessThan(strpos($html, 'Registro ilustrativo 2'), strpos($html, 'Registro ilustrativo 1'));
        self::assertStringNotContainsString('<progress', $html);
        self::assertStringNotContainsString('class="pi-journey"', $html);
        self::assertStringNotContainsString('datetime=', $html);
    }

    public function test_product_boundaries_and_unavailable_operations_remain_explicit(): void
    {
        $html = view('portal.demo.requests')->render();
        foreach (['Onepage e Landing Page são serviços independentes', 'motor de execução separado',
            'identidade, login e sessão próprios, sem SSO', 'benefício de clientes elegíveis, não assinatura avulsa',
            'nenhuma elegibilidade é concedida', 'sem abertura, edição, envio, anexos ou aprovação',
        ] as $text) {
            self::assertStringContainsString($text, $html);
        }
        foreach (['<form', '<button', '<input', '<script', '<iframe', 'action=', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        self::assertSame(1, substr_count($html, 'src="/assets/logo-imagine.png"'));
        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(['#pi-content'], $links[1]);
    }
}
