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
        foreach (['<form', '<button', '<input', '<script', '<iframe', 'action=', 'src=', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(['#pi-content'], $links[1]);
    }

    public function test_workflow_preview_is_readable_without_enabling_mutations_or_routes(): void
    {
        $html = view('portal.demo.requests')->render();
        self::assertStringContainsString('Etapas de atendimento — apenas exemplo', $html);
        self::assertStringContainsString('Estados ilustrativos do atendimento', $html);
        self::assertStringContainsString('não salva dados, abre chamados ou concede permissões', $html);
        foreach (\App\Domain\ServiceRequests\ServiceRequestStatus::cases() as $status) {
            self::assertStringContainsString($status->label(), $html);
        }
        foreach (['<form', '<button', '<input', 'action=', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_request_status_overview_matches_the_same_fictional_inbox_without_creating_actions(): void
    {
        $html = view('portal.demo.requests')->render();
        self::assertStringContainsString('Resumo por situação — apenas exemplos', $html);
        self::assertStringContainsString('Se o conjunto estiver incompleto, o resumo não será apresentado', $html);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//table[caption[contains(.,"Distribuição de solicitações fictícias")]]/tbody/tr');
        self::assertNotFalse($rows);
        self::assertSame(3, $rows->length);
        $pairs = [];
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);
            self::assertNotFalse($cells);
            self::assertSame(2, $cells->length);
            $pairs[] = [
                trim($cells->item(0)->textContent),
                trim($cells->item(1)->textContent),
            ];
        }
        self::assertSame([
            ['Em análise', '1'],
            ['Aguardando informações do cliente', '1'],
            ['Resolvida', '1'],
        ], $pairs);
        foreach (['<form', '<button', '<input', '<script', 'https://', 'http://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_synthetic_request_inbox_is_displayed_without_leaking_live_data_or_enabling_actions(): void
    {
        $html = view('portal.demo.requests')->render();
        self::assertStringContainsString('Fila de solicitações — somente demonstração', $html);
        self::assertStringContainsString('Fila fictícia de solicitações — sem dados reais', $html);
        self::assertStringContainsString('nenhum filtro autoriza acesso a dados reais', $html);
        foreach (['DEMO-REQ-LANDING', 'DEMO-REQ-ONEPAGE', 'DEMO-REQ-PERFORMANCE'] as $id) {
            self::assertSame(1, substr_count($html, '<td>'.$id.'</td>'));
        }
        foreach (['Aguardando informações do cliente', 'Em análise', 'Resolvida'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        foreach (['<form', '<button', '<input', '<script', 'action=', 'http://', 'https://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        preg_match_all('/href="([^"]*)"/', $html, $links);
        self::assertSame(['#pi-content'], $links[1]);
    }
}
