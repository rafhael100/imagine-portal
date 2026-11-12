<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Browser policy on the INTERNAL HTML demo only.
 * The Portal still exposes no customer login or private HTML routes.
 * A CSP <meta> is not a substitute for a response header on future routes.
 */
final class PortalDemoBrowserPolicyTest extends TestCase
{
    private function renderSyntheticPage(): string
    {
        return Blade::render(
            '<x-portal.layout current="overview"><p>CONTEUDO-FICTICIO</p></x-portal.layout>'
        );
    }

    public function test_internal_demo_renders_strict_browser_policy_and_nonindexing(): void
    {
        $html = $this->renderSyntheticPage();

        self::assertStringContainsString('CONTEUDO-FICTICIO', $html);
        self::assertStringContainsString('lang="pt-BR"', $html);
        self::assertStringContainsString('name="robots" content="noindex,nofollow"', $html);
        self::assertStringContainsString('name="referrer" content="no-referrer"', $html);
        self::assertSame(1, substr_count($html, 'http-equiv="Content-Security-Policy"'));

        foreach ([
            "default-src 'none'",
            "style-src 'unsafe-inline'",
            "img-src data:",
            "font-src 'none'",
            "connect-src 'none'",
            "form-action 'none'",
            "base-uri 'none'",
            "object-src 'none'",
        ] as $required) {
            self::assertStringContainsString($required, $html);
        }

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('href="#pi-content"', $html);
        self::assertStringContainsString('id="pi-content"', $html);
    }

    public function test_browser_policy_does_not_turn_demo_into_an_http_page(): void
    {
        foreach (['/login', '/portal', '/portal/services', '/mfa', '/performance'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
