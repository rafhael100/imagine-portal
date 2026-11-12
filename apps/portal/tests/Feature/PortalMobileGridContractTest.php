<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Browser QA on the former CSS proved the 390px layout was 912px wide:
 * CSS Grid's bare 1fr kept the sidebar's horizontal menu min-content width.
 * This regression test requires a zero-minimum track in the mobile grid.
 * Pixel-level evidence is separately produced by qa_portable_preview.py.
 */
final class PortalMobileGridContractTest extends TestCase
{
    public function test_tablet_and_mobile_layout_allow_zero_minimum_grid_width(): void
    {
        $html = Blade::render(
            '<x-portal.layout current="overview"><p>DEMO RESPONSIVO</p></x-portal.layout>'
        );

        self::assertStringContainsString(
            '@media(max-width:1050px){.pi-layout{grid-template-columns:minmax(0,1fr);',
            $html
        );
        self::assertStringNotContainsString(
            '@media(max-width:1050px){.pi-layout{grid-template-columns:1fr;',
            $html
        );
        self::assertStringContainsString(
            '.pi-sidebar nav{max-width:100%;overflow-x:auto;',
            $html
        );
        self::assertStringContainsString('grid-auto-flow:column', $html);
        self::assertStringContainsString('.pi-table-wrap{overflow-x:auto;', $html);
        self::assertStringContainsString('tabindex="-1"', $html);
        self::assertStringContainsString('href="#pi-content"', $html);
        self::assertStringContainsString('noindex,nofollow', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function test_other_preview_pages_still_preserve_semantics_after_grid_fix(): void
    {
        foreach ([
            'overview', 'services', 'available', 'contracts',
            'modules', 'journey', 'requests', 'help', 'notices',
        ] as $name) {
            $html = view('portal.demo.'.$name)->render();
            self::assertStringContainsString(
                'grid-template-columns:minmax(0,1fr)', $html, $name
            );
            self::assertSame(1, substr_count($html, 'class="pi-sidebar"'));
            self::assertSame(1, substr_count($html, 'id="pi-content"'));
            self::assertSame(1, substr_count($html, 'src="/assets/logo-imagine.png"'));
        }
    }
}
