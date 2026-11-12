<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoSitesEditorialPreview;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class PortalDemoSitesEditorialViewTest extends TestCase
{
    public function test_two_site_products_have_separate_fictional_editorial_revisions(): void
    {
        self::assertCount(2, DemoSitesEditorialPreview::candidates());
        $rows = DemoSitesEditorialPreview::rows();
        self::assertCount(2, $rows);
        self::assertStringStartsWith('DEMO-SITE-REQ-ONEPAGE', $rows[0][0]);
        self::assertStringStartsWith('DEMO-SITE-REQ-LANDING', $rows[1][0]);
        self::assertSame('Sites Imagine · Onepage', $rows[0][1]);
        self::assertSame('Sites Imagine · Landing Page', $rows[1][1]);
        self::assertStringContainsString('Aprovação simulada', $rows[0][4]);
        self::assertStringContainsString('Aguardando revisão fictícia', $rows[1][4]);
        self::assertFalse(DemoSitesEditorialPreview::mayPublish());
        self::assertFalse(DemoSitesEditorialPreview::isAuthenticatedApproval());
    }

    public function test_requests_view_renders_review_only_without_forms_or_real_access(): void
    {
        $html = Blade::render("@include('portal.demo.requests')");
        foreach ([
            'Onepage e Landing Page — revisão editorial fictícia',
            'DEMO-SITE-REQ-ONEPAGE', 'DEMO-SITE-REQ-LANDING',
            'Aprovação simulada', 'Aguardando revisão fictícia',
            'sem aprovação real', 'Alterar briefing ou conteúdo exige nova revisão',
            'noindex,nofollow',
        ] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('scope="col"', $html);
        self::assertStringContainsString('role="region"', $html);
    }

    public function test_sites_editorial_and_payment_actions_are_still_unavailable(): void
    {
        foreach ([
            '/portal/sites/approve', '/portal/sites/publish',
            '/portal/sites/briefing', '/portal/checkout', '/login',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
