<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\CommercialCatalogReview;
use Illuminate\Database\DatabaseManager;
use Tests\TestCase;

final class CommercialEditorialPreviewViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db=$this->createMock(DatabaseManager::class);
        $db->expects($this->never())->method('connection');
        $this->app->instance('db',$db);
    }
    public function test_preview_renders_as_internal_screen_without_http_route(): void
    {
        $before=array_map(fn($r)=>$r->uri(),iterator_to_array($this->app['router']->getRoutes()));
        $html=view('portal.demo.commercial-editorial')->render();
        self::assertStringContainsString('Publicação do site comercial', $html);
        self::assertStringContainsString('Tabela ilustrativa de comparação', $html);
        self::assertStringContainsString('Landing Pages comerciais revisadas', $html);
        self::assertStringContainsString('Sem conexão com clientes, CMS real ou publicação', $html);
        self::assertStringContainsString('name="robots" content="noindex,nofollow"', $html);
        foreach (['<form','<input','<button','<script','<iframe','action=','http://','https://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden,$html);
        }
        self::assertSame($before,array_map(fn($r)=>$r->uri(),iterator_to_array($this->app['router']->getRoutes())));
        self::assertFalse($this->app['session']->isStarted());
    }
    public function test_untrusted_text_is_escaped_in_the_read_only_editorial_preview(): void
    {
        $attack='"><img src=x onerror=alert(1)>';
        $original=[
            'schemaVersion'=>'1.0','releaseId'=>'release_aa_001',
            'items'=>[['id'=>'service_xss_01','slug'=>'onepage',
                'title'=>'Título anterior','description'=>'Texto anterior',
                'status'=>'published','version'=>1,'path'=>'/servicos/onepage/']],
        ];
        $next=$original;
        $next['releaseId']='release_aa_002';
        $next['items'][0]['title']=$attack;
        $next['items'][0]['version']=2;
        $review=CommercialCatalogReview::preview($original,$next);
        $html=view('portal.demo.commercial-editorial',compact('review'))->render();
        self::assertStringNotContainsString('<img src=x',$html);
        self::assertStringContainsString(e($attack),$html);
        self::assertStringNotContainsString('<script',$html);
    }
}
