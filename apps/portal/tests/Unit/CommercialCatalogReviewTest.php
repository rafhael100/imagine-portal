<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\CommercialCatalogReview;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CommercialCatalogReviewTest extends TestCase
{
    private function catalog(array $itemOverrides=[]): array
    {
        return [
            'schemaVersion'=>'1.0','releaseId'=>'release_demo_001',
            'publishedAt'=>'2026-11-09T03:00:00Z','items'=>[array_replace([
                'id'=>'service_site_01','slug'=>'criacao-de-sites',
                'title'=>'Criação de sites profissionais',
                'description'=>'Páginas para apresentar produtos, serviços e contatos da empresa.',
                'path'=>'/servicos/criacao-de-sites/','status'=>'published','version'=>1,
            ],$itemOverrides)],
        ];
    }
    public function test_identifies_edits_and_new_pages_but_never_authorizes_publication(): void
    {
        $prev=$this->catalog();
        $next=$this->catalog(['version'=>2,'title'=>'Criação de sites profissionais com SEO']);
        $next['releaseId']='release_demo_002';
        $next['items'][]=[
            'id'=>'service_onepage_01','slug'=>'onepage',
            'title'=>'Onepage institucional',
            'description'=>'Uma página simples para apresentar os principais dados de uma empresa.',
            'path'=>'/servicos/onepage/','status'=>'published','version'=>1,
        ];
        $report=CommercialCatalogReview::preview($prev,$next);
        self::assertSame(1,$report['counts']['updated']);
        self::assertSame(1,$report['counts']['added']);
        self::assertSame(0,$report['counts']['blocked']);
        self::assertSame('ready_for_human_review',$report['reviewState']);
        self::assertFalse($report['canPublish']);
        self::assertTrue($report['previewOnly']);
    }
    public function test_detects_slug_changes_missing_version_and_removals(): void
    {
        $prev=$this->catalog();
        $next=$this->catalog(['slug'=>'criar-sites','path'=>'/servicos/criar-sites/','version'=>2]);
        $report=CommercialCatalogReview::preview($prev,$next);
        self::assertSame('hold_for_review',$report['reviewState']);
        self::assertSame(1,$report['counts']['blocked']);
        self::assertStringContainsString('migração SEO',$report['flags'][0]['reason']);
        $noVersion=$this->catalog(['title'=>'Outro título sem aumentar a versão']);
        $report=CommercialCatalogReview::preview($prev,$noVersion);
        self::assertSame(1,$report['counts']['blocked']);
        self::assertStringContainsString('sem aumentar a versão',$report['flags'][0]['reason']);
        $removed=$this->catalog();
        $removed['items']=[];
        $report=CommercialCatalogReview::preview($prev,$removed);
        self::assertSame(1,$report['counts']['removed']);
        self::assertFalse($report['canPublish']);
    }
    public function test_drafts_cannot_be_published_and_require_review(): void
    {
        $prev=$this->catalog();
        $next=$this->catalog(['status'=>'in_review','version'=>2]);
        $report=CommercialCatalogReview::preview($prev,$next);
        self::assertSame('hold_for_review',$report['reviewState']);
        self::assertSame(1,$report['counts']['blocked']);
    }
    public function test_rejects_private_extra_fields_and_duplicate_urls(): void
    {
        $prev=$this->catalog();
        foreach ([['price'=>100],['customerEmail'=>'pessoa@example.org']] as $extra) {
            try {
                CommercialCatalogReview::preview($prev,$this->catalog($extra));
                self::fail('Private data allowed');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Private',$exception->getMessage());
            }
        }
        $next=$this->catalog();
        $next['items'][]=$next['items'][0];
        $this->expectException(InvalidArgumentException::class);
        CommercialCatalogReview::preview($prev,$next);
    }
    public function test_fictional_preview_contains_only_read_only_states(): void
    {
        $demo=CommercialCatalogReview::fictionalExample();
        self::assertSame(1,$demo['counts']['updated']);
        self::assertSame(1,$demo['counts']['blocked']);
        self::assertSame(3,count($demo['rows']));
        self::assertFalse($demo['canPublish']);
    }
}
