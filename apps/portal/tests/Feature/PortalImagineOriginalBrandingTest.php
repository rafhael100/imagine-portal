<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The approved Node-era branding is an input to the Laravel demo, NOT a
 * new placeholder logo. The original binary is a byte-for-byte Git blob.
 */
final class PortalImagineOriginalBrandingTest extends TestCase
{
    public function test_imagine_original_logo_binary_is_exactly_preserved(): void
    {
        $file = public_path('assets/logo-imagine.png');
        self::assertFileExists($file);
        $bytes = file_get_contents($file);
        self::assertIsString($bytes);
        self::assertSame(5594, strlen($bytes));
        self::assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
        self::assertSame(
            '678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77',
            sha1('blob '.strlen($bytes)."\0".$bytes),
            'Logo must match main/assets/logo-imagine.png exactly, not an AI recreation.',
        );
        $image = getimagesize($file);
        self::assertIsArray($image);
        self::assertSame('image/png', $image['mime']);
    }

    public function test_layout_uses_logo_and_approved_orange_graphite_light_theme(): void
    {
        $html = Blade::render(
            '<x-portal.layout current="overview"><p>EXEMPLO-IMAGINE</p></x-portal.layout>'
        );
        self::assertStringContainsString('EXEMPLO-IMAGINE', $html);
        self::assertStringContainsString('alt="Imagine Comunicação"', $html);
        self::assertStringContainsString('assets/logo-imagine.png', $html);
        self::assertSame(1, substr_count($html, 'alt="Imagine Comunicação"'));
        self::assertStringContainsString('--pi-orange:#f9941d', $html);
        self::assertStringContainsString('--pi-ink:#171b19', $html);
        self::assertStringContainsString('--pi-bg:#f7f7f8', $html);
        self::assertStringContainsString('--pi-nav:#fff', $html);
        self::assertStringContainsString('var(--pi-orange-soft)', $html);
        self::assertStringContainsString("img-src 'self' data:", html_entity_decode($html, ENT_QUOTES));
        self::assertStringContainsString('name="theme-color" content="#171b19"', $html);
        self::assertStringContainsString('role="region"', Blade::render(
            '<x-portal.table caption="TABELA-FICTICIA" :columns="[\'Código\']" :rows="[[\'DEMO-1\']]" />'
        ));
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function test_branding_does_not_open_customer_routes(): void
    {
        foreach (['/portal', '/login', '/portal/overview', '/portal/requests', '/mfa'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
