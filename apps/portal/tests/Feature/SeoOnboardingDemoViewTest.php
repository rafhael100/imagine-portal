<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class SeoOnboardingDemoViewTest extends TestCase
{
    protected function setUp():void
    {
        parent::setUp();
        $database=$this->createMock(DatabaseManager::class);
        $database->expects($this->never())->method('connection');
        $this->app->instance('db',$database);
    }

    public function test_seo_preview_is_standalone_and_never_claims_live_data():void
    {
        $html=view('seo.demo-onboarding')->render();

        foreach([
            'lang="pt-BR"',
            '<meta name="robots" content="noindex,nofollow,noarchive">',
            'SEO Engine',
            'DEMONSTRAÇÃO INTERNA',
            'Dados fictícios',
            'sem conexão com Google Search Console, Analytics ou CRM',
            'Não conectado',
            'Aguardando homologação',
            'Metodologia Imagine',
            'Visitas → Leads → Clientes',
            'Cliques de busca não equivalem a visitas',
            'CRM Imagine',
            'sem compartilhamento de login',
            'Cliques e impressões do Google Search Console',
            'Não contém resultados reais',
            '--orange:#F9941D',
            'Sem dados reais e sem acesso a produção'
        ] as $needle) self::assertStringContainsString($needle,$html);

        self::assertSame(3,substr_count($html,'class="is-funnel"')+
            substr_count($html,'<h3>Visitas</h3>')+
            substr_count($html,'<h3>Leads</h3>')+
            substr_count($html,'<h3>Clientes</h3>')-1);
        self::assertSame(6,substr_count($html,'>N/D</div>'));
        self::assertStringNotContainsString('<form',$html);
        self::assertStringNotContainsString('<button',$html);
        self::assertStringNotContainsString('<script',$html);
        self::assertStringNotContainsString('href=',$html);
        self::assertStringNotContainsString('onclick=',$html);
        self::assertStringNotContainsString('type="submit"',$html);
        self::assertStringNotContainsString('oauth2.googleapis.com',$html);
        self::assertStringNotContainsString('token_exchange',$html);
    }

    public function test_demo_label_is_escaped_and_not_a_security_claim():void
    {
        $injected='"><img src=x onerror=alert(1)>';
        $html=view('seo.demo-onboarding',[
            'companyLabel'=>$injected
        ])->render();
        self::assertStringContainsString(e($injected),$html);
        self::assertStringNotContainsString('<img src=x',$html);
        self::assertStringNotContainsString($injected,$html);
        self::assertStringContainsString('N/D',$html);
    }

    public function test_funnel_has_three_accessible_stages_all_unavailable():void
    {
        $html=view('seo.demo-onboarding')->render();
        $doc=new \DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath=new \DOMXPath($doc);
        $cards=$xpath->query('//div[contains(@class,"is-funnel")]/article');
        self::assertSame(3,$cards->length);
        foreach(['Visitas','Leads','Clientes'] as $i=>$expected){
            $card=$cards->item($i);
            self::assertNotNull($card);
            self::assertStringContainsString($expected,$card->textContent);
            $numbers=$xpath->query('.//div[contains(@class,"is-number")]', $card);
            self::assertSame(1,$numbers->length);
            self::assertSame('N/D',trim($numbers->item(0)->textContent));
            self::assertStringContainsString('indisponíveis',
                $numbers->item(0)->getAttribute('aria-label'));
        }
    }

    public function test_no_oauth_connect_or_customer_seo_http_route_is_exposed():void
    {
        foreach([
            '/seo','/seo/connect','/portal/seo','/portal/seo/metrics',
            '/oauth/google/callback','/integrations/google/search-console/callback',
            '/api/seo','/api/gsc'
        ] as $uri)$this->get($uri)->assertNotFound();
    }
}
