<?php

namespace Tests\Feature;

use App\Presentation\ClientSection;
use App\Presentation\JourneyState;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalJourneyPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = $this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db', $database);
    }

    public function test_catalog_order_labels_and_exactly_one_active_item_match_all_six_views(): void
    {
        self::assertSame(['overview', 'services', 'available', 'contracts', 'modules', 'journey'], array_column(ClientSection::cases(), 'value'));
        foreach (ClientSection::cases() as $section) {
            $html = view('portal.demo.'.$section->value)->render();
            self::assertSame(1, substr_count($html, 'aria-current="page"'));
            self::assertStringContainsString('aria-current="page">'.$section->label().'</span>', $html);
            $position = -1;
            foreach (ClientSection::cases() as $item) {
                $next = strpos($html, '>'.$item->label().'</span>');
                self::assertNotFalse($next);
                self::assertGreaterThan($position, $next);
                $position = $next;
            }
            self::assertStringContainsString('<title>'.$section->label().' · Portal Imagine</title>', $html);
        }
    }

    public function test_unknown_section_is_neutral_without_links_or_access_claims(): void
    {
        $html = Blade::render('<x-portal.sidebar current="administrator" />');
        self::assertStringNotContainsString('aria-current', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString('administrator', $html);
        self::assertNull(ClientSection::tryFrom('administrator'));
    }

    public function test_closed_state_catalog_has_safe_fallback_in_every_component(): void
    {
        self::assertSame(['example', 'illustrated', 'preparing', 'waiting', 'next'], array_column(JourneyState::cases(), 'value'));
        foreach (JourneyState::cases() as $state) {
            $html = Blade::render('<x-portal.service-status :state="$code" />', ['code' => $state->value]);
            self::assertStringContainsString($state->label(), $html);
            self::assertStringContainsString('pi-tone-'.$state->tone(), $html);
        }
        foreach (['<x-portal.service-status :state="$code" />',
            '<x-portal.next-stage title="Exemplo" description="Fictício" :state="$code" />',
            '<x-portal.pending-notice :state="$code" />',
            '<x-portal.journey :steps="[[\'title\' => \'Exemplo\', \'description\' => \'Fictício\', \'state\' => $code]]" />',
        ] as $template) {
            $html = Blade::render($template, ['code' => 'paid administrator injected']);
            self::assertStringContainsString('Exemplo ilustrativo', $html);
            self::assertStringContainsString('pi-tone-neutral', $html);
            self::assertStringNotContainsString('injected', $html);
        }
    }

    public function test_new_components_escape_all_dynamic_text_and_state_inputs(): void
    {
        $attack = '"><img src=x onerror=alert(1)>';
        foreach ([
            '<x-portal.journey :title="$attack" :steps="[[\'title\' => $attack, \'description\' => $attack, \'state\' => $attack]]" />',
            '<x-portal.next-stage :title="$attack" :description="$attack" :state="$attack" />',
            '<x-portal.pending-notice :title="$attack" :items="[$attack]" :state="$attack" />',
        ] as $template) {
            $html = Blade::render($template, compact('attack'));
            self::assertStringContainsString(e($attack), $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString($attack, $html);
        }
    }

    public function test_timeline_progress_empty_state_and_product_boundaries_are_explicit(): void
    {
        $html = view('portal.demo.journey')->render();
        foreach (['DEMONSTRAÇÃO — dados fictícios', 'Onepage', 'Landing Page', 'Execução externa não implementada',
            'Google Ads e Meta', 'Login e sessão próprios', 'sem SSO', 'Benefício de clientes elegíveis',
            'não uma assinatura avulsa', 'Nenhum serviço, contrato, campanha ou módulo está ativo',
            'value="1" max="4"', '<ol class="pi-journey">',
        ] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('<button', $html);
        $empty = Blade::render('<x-portal.journey />');
        self::assertStringContainsString('Nenhuma etapa ilustrativa', $empty);
        self::assertStringNotContainsString('<progress', $empty);
        self::assertStringContainsString('Nenhum item demonstrativo', Blade::render('<x-portal.pending-notice />'));
    }
}
