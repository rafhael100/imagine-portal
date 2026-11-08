<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\PviewSection;
use Tests\TestCase;

final class PviewNavigationPresentationTest extends TestCase
{
    public function test_sections_are_eight_unique_static_navigation_definitions(): void
    {
        $sections = PviewSection::cases();
        $values = array_map(static fn (PviewSection $s): string => $s->value, $sections);

        $this->assertCount(8, $sections);
        $this->assertSame($values, array_values(array_unique($values)));

        foreach ($sections as $section) {
            $this->assertMatchesRegularExpression('/^[a-z]+$/D', $section->value);
            $this->assertNotSame('', trim($section->label()));
            $this->assertNotSame('', trim($section->description()));
        }
    }

    public function test_blade_preview_renders_links_with_section_targets_and_no_operational_ui(): void
    {
        $html = view('portal.demo.pview-navigation')->render();

        $this->assertStringContainsString('DEMONSTRAÇÃO OFFLINE / LARAVEL', $html);
        $this->assertStringContainsString('Empresa Aurora fictícia', $html);
        $this->assertStringContainsString('connect-src &#039;none&#039;', $html);
        $this->assertStringContainsString('href="#pview-main"', $html);

        foreach (PviewSection::cases() as $section) {
            $this->assertStringContainsString('href="#pview-'.$section->value.'"', $html);
            $this->assertStringContainsString('id="pview-'.$section->value.'"', $html);
            $this->assertStringContainsString($section->label(), $html);
        }

        foreach (['<script', '<form', '<input', '<iframe', 'http://', 'https://', 'fetch(', 'localStorage', 'WebSocket'] as $dangerous) {
            $this->assertStringNotContainsString($dangerous, $html);
        }
    }

    public function test_preview_does_not_expose_private_portal_routes(): void
    {
        foreach (['/login', '/portal', '/portal/services', '/performance', '/contracts'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
