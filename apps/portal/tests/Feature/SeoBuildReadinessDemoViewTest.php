<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SeoBuildReadinessDemoViewTest extends TestCase
{
    private function preview():array
    {
        return [
            'contractVersion'=>'portal-seo-f118-static-build-lab-v1',
            'sourceType'=>'UNVERIFIED_OFFLINE_BUILD_CLAIM',
            'reviewStatus'=>'REVIEW_BLOCKED',
            'releaseStatus'=>'HOLD_REAL_SOURCE_AUTHENTICATION_AND_STAGING',
            'customerDisplayAuthorized'=>false,
            'publicationAuthorized'=>false,
            'realSiteVerified'=>false,
            'counts'=>[
                'localBuildPages'=>1250,
                'municipalPagesPresent'=>1200,
                'municipalPagesMissing'=>12,
                'wrongLanguagePages'=>4,
                'technicalErrors'=>18,
                'privateDraftLeaks'=>2
            ]
        ];
    }

    public function test_view_renders_only_sanitized_counts_not_customer_claims():void
    {
        $html=view('seo.demo-build-readiness',[
            'readiness'=>$this->preview()
        ])->render();
        foreach([
            'lang="pt-BR"',
            'noindex,nofollow,noarchive',
            '#F9941D','DEMONSTRAÇÃO INTERNA',
            'Revisão técnica bloqueada',
            'Publicação não autorizada',
            'Páginas HTML analisadas',
            'Páginas municipais ausentes',
            'Idiomas divergentes',
            'Vazamentos de prévias privadas',
            '>1.250</div>',
            '>12</div>','>4</div>','>18</div>','>2</div>',
            'Visitas → Leads → Clientes'
        ] as $expected)
            self::assertStringContainsString($expected,$html,$expected);
        foreach([
            '<script','<form','<button','href=',
            'onclick=','oauth2.googleapis.com','<iframe'
        ] as $forbidden)
            self::assertStringNotContainsString($forbidden,$html);
    }

    public function test_missing_or_forged_authority_has_no_trusted_counts():void
    {
        $none=view('seo.demo-build-readiness')->render();
        self::assertStringContainsString('Sem relatório verificado',$none);
        self::assertSame(6,substr_count($none,'>N/D</div>'));

        $dto=$this->preview();
        $dto['customerDisplayAuthorized']=true;
        $dto['counts']['technicalErrors']=9999999;
        $dto['counts']['userHtml']='<img src=x onerror=alert("XSS")>';
        $html=view('seo.demo-build-readiness',[
            'readiness'=>$dto
        ])->render();
        self::assertStringContainsString('Sem relatório verificado',$html);
        self::assertSame(6,substr_count($html,'>N/D</div>'));
        self::assertStringNotContainsString('<img src=x',$html);
        self::assertStringNotContainsString('9999999',$html);
    }

    public function test_demo_does_not_create_any_seo_or_oauth_http_routes():void
    {
        foreach([
            '/seo/build','/portal/seo/build',
            '/portal/seo/readiness','/api/seo/build',
            '/portal/google/oauth/connect'
        ] as $uri) $this->get($uri)->assertNotFound();
    }
}
