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

    public function test_blade_preview_renders_eight_visual_screens_without_real_services(): void
    {
        $html = view('portal.demo.pview-navigation')->render();

        $this->assertStringContainsString('DEMONSTRAÇÃO OFFLINE / LARAVEL', $html);
        $this->assertStringContainsString('Empresa Aurora', $html);
        $this->assertStringContainsString("connect-src 'none'", $html);
        $this->assertStringContainsString("form-action 'none'", $html);
        $this->assertStringContainsString('href="#pview-main"', $html);
        $this->assertStringContainsString('data-screen=', $html);
        $this->assertStringContainsString('aria-current', $html);
        $this->assertStringContainsString('Sem login', $html);

        $this->assertSame(8, preg_match_all('/data-screen="[a-z]+"/', $html));
        $this->assertSame(8, preg_match_all('/data-nav="[a-z]+"/', $html));

        foreach (PviewSection::cases() as $section) {
            $this->assertStringContainsString('href="#pview-'.$section->value.'"', $html);
            $this->assertStringContainsString('id="pview-'.$section->value.'"', $html);
            $this->assertStringContainsString('data-screen="'.$section->value.'"', $html);
            $this->assertStringContainsString('data-nav="'.$section->value.'"', $html);
            $this->assertStringContainsString($section->label(), $html);
        }

        // Exactly one bounded, inline-only navigator. This demo CSP is not a
        // production security policy and MUST NOT be copied to operational UI.
        $this->assertSame(1, substr_count($html, '<script>'));
        foreach (['<form', '<input', '<iframe', '<script src', 'http://', 'https://',
                  'fetch(', 'XMLHttpRequest', 'WebSocket', 'localStorage',
                  'sessionStorage', 'navigator.sendBeacon'] as $dangerous) {
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
