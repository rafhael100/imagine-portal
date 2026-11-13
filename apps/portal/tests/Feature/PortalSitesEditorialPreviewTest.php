<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\DemoSitesEditorialPreview;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Tests an immediately visible Sites editorial preview inside the already
 * existing DEMO requests page. No live HTTP request/order can be submitted.
 */
final class PortalSitesEditorialPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db=$this->createMock(DatabaseManager::class);
        $db->expects(self::never())->method('connection');
        $this->app->instance('db',$db);
    }

    public function test_onepage_and_landing_page_are_rendered_in_read_only_request_preview(): void
    {
        $html=view('portal.demo.requests')->render();
        self::assertStringContainsString('Onepage e Landing Page — revisão editorial fictícia',$html);
        self::assertStringContainsString('Conteúdos ilustrativos de Sites Imagine — sem aprovação real',$html);
        self::assertSame(1,substr_count($html,'<td>DEMO-SITE-REQ-ONEPAGE</td>'));
        self::assertSame(1,substr_count($html,'<td>DEMO-SITE-REQ-LANDING</td>'));
        self::assertStringContainsString('Uma presença digital para sua empresa',$html);
        self::assertStringContainsString('Transforme visitas em oportunidades',$html);
        self::assertStringContainsString('Aprovação simulada — não é aceite do cliente',$html);
        self::assertStringContainsString('Aguardando revisão fictícia',$html);
        self::assertStringContainsString('Briefing 1 · Conteúdo 2',$html);
        self::assertStringContainsString('Briefing 0 · Conteúdo 1',$html);
        self::assertStringContainsString('Esta tela não coleta dados, cobra nem publica',$html);
        self::assertStringContainsString('A abertura, edição, envio e anexação de solicitações',$html);
    }

    public function test_editorial_rows_are_made_only_of_fictional_local_immutable_candidates(): void
    {
        $entries=DemoSitesEditorialPreview::snapshots();
        self::assertCount(2,$entries);
        self::assertCount(2,DemoSitesEditorialPreview::rows());
        foreach ($entries as $entry) {
            $candidate=$entry['candidate'];
            self::assertSame('org-a',$candidate->brief->organizationId->value);
            self::assertStringStartsWith('DEMO-SITE-REQ-',$candidate->brief->reference);
            self::assertSame(64,strlen($candidate->fingerprint));
            self::assertFalse($candidate->canPublish());
            self::assertFalse($candidate->canCharge());
            self::assertFalse($candidate->brief->canSubmitToPortal());
            self::assertFalse($candidate->brief->canPublishSite());
            self::assertFalse($candidate->brief->canChargeOrContract());
            self::assertStringNotContainsString('@',$candidate->headline);
        }
        self::assertFalse(DemoSitesEditorialPreview::mayPublish());
        self::assertFalse(DemoSitesEditorialPreview::isAuthenticatedApproval());
    }

    public function test_adversarial_text_is_escaped_by_shared_portal_table_component(): void
    {
        $evil='"><img src=x onerror=alert(1)>';
        $html=Blade::render(
            '<x-portal.table caption="Revisão fictícia" :columns="[\'Título\']" :rows="[[$evil]]" />',
            ['evil'=>$evil],
        );
        self::assertStringContainsString(e($evil),$html);
        self::assertStringNotContainsString('<img',$html);
        self::assertStringNotContainsString($evil,$html);
        self::assertStringNotContainsString('<script',$html);
    }

    public function test_request_demo_remains_non_operational_with_no_new_routes_sessions_or_forms(): void
    {
        $before=[];
        foreach ($this->app['router']->getRoutes() as $route) {
            $before[]=[$route->uri(),$route->methods()];
        }
        $html=view('portal.demo.requests')->render();
        $after=[];
        foreach ($this->app['router']->getRoutes() as $route) {
            $after[]=[$route->uri(),$route->methods()];
        }
        self::assertSame($before,$after);
        foreach ([
            '<form','<button','<input','<script','<iframe',
            'action=','http://','https://','R$ ','checkout',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden,$html);
        }
        preg_match_all('/href="([^"]*)"/',$html,$links);
        self::assertSame(['#pi-content'],$links[1]);
        self::assertFalse($this->app['session']->isStarted());
    }

    public function test_repeated_renders_have_identical_illustrative_review_rows(): void
    {
        self::assertSame(DemoSitesEditorialPreview::rows(),DemoSitesEditorialPreview::rows());
        $first=view('portal.demo.requests')->render();
        $second=view('portal.demo.requests')->render();
        self::assertSame($first,$second);
        self::assertSame(2,substr_count($first,'<td>DEMO-SITE-REQ-'));
    }
}
