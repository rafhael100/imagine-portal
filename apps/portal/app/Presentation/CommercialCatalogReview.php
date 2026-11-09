<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/**
 * F4: diff editorial read-only, sem serviço de aprovação e sem publicação.
 * Este resultado NUNCA autoriza API, usuário, tenant, edição ou deploy.
 */
final class CommercialCatalogReview
{
    private const FIELDS = ['id','slug','title','description','path','status','version','offerRef'];
    private const SLUG = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    public static function preview(array $previous, array $candidate): array
    {
        $old = self::readItems($previous, true);
        $next = self::readItems($candidate, false);
        $rows = [];
        $flags = [];
        $counts = ['added'=>0,'updated'=>0,'unchanged'=>0,'blocked'=>0,'removed'=>0];
        foreach ($next as $id => $item) {
            $prior = $old[$id] ?? null;
            $problems = [];
            $change = 'unchanged';
            if ($item['status'] !== 'published') {
                $problems[] = 'Conteúdo ainda não publicado editorialmente';
            }
            if ($prior === null) {
                $change = 'added';
            } elseif ($item['slug'] !== $prior['slug'] || $item['path'] !== $prior['path']) {
                $change = 'blocked';
                $problems[] = 'URL modificada: exige plano de migração SEO';
            } elseif ($item['version'] < $prior['version']) {
                $change = 'blocked';
                $problems[] = 'Versão anterior à publicada';
            } elseif ($item['version'] === $prior['version']) {
                if ($item['title'] !== $prior['title'] || $item['description'] !== $prior['description'] ||
                    ($item['offerRef'] ?? null) !== ($prior['offerRef'] ?? null)) {
                    $change = 'blocked';
                    $problems[] = 'Conteúdo alterado sem aumentar a versão';
                }
            } else {
                $change = 'updated';
            }
            if ($problems !== []) {
                $change = 'blocked';
            }
            $counts[$change]++;
            foreach ($problems as $reason) {
                $flags[] = ['serviceId'=>$id,'reason'=>$reason];
            }
            $rows[] = [
                'id'=>$id,'slug'=>$item['slug'],'path'=>$item['path'],
                'title'=>$item['title'],'description'=>$item['description'],
                'version'=>$item['version'],'status'=>$item['status'],
                'change'=>$change,'flags'=>$problems
            ];
        }
        foreach ($old as $id => $item) {
            if (!isset($next[$id])) {
                $counts['removed']++;
                $flags[] = ['serviceId'=>$id,'reason'=>'Serviço removido: exige aprovação de arquivamento e SEO'];
                $rows[] = [
                    'id'=>$id,'slug'=>$item['slug'],'path'=>$item['path'],
                    'title'=>$item['title'],'description'=>$item['description'],
                    'version'=>$item['version'],'status'=>'removed',
                    'change'=>'removed','flags'=>['Sem equivalente aprovado'],
                ];
            }
        }
        usort($rows, fn (array $a,array $b) =>
            strcmp($a['slug'],$b['slug']) ?: strcmp($a['id'],$b['id'])
        );
        return [
            'previewOnly'=>true,'canPublish'=>false,
            'oldReleaseId'=>$previous['releaseId'],'candidateReleaseId'=>$candidate['releaseId'],
            'counts'=>$counts,'flags'=>$flags,'rows'=>$rows,
            'reviewState'=>$flags!==[] ? 'hold_for_review' : 'ready_for_human_review',
        ];
    }

    private static function readItems(array $snapshot, bool $previous): array
    {
        foreach (array_keys($snapshot) as $field) {
            if (!in_array($field,['schemaVersion','releaseId','publishedAt','items'],true)) {
                throw new InvalidArgumentException('Private or unknown catalog field');
            }
        }
        if (($snapshot['schemaVersion'] ?? null)!=='1.0' ||
            !is_string($snapshot['releaseId'] ?? null) ||
            preg_match('/\A[a-z][a-z0-9_-]{5,79}\z/D',$snapshot['releaseId'])!==1 ||
            !isset($snapshot['items']) || !is_array($snapshot['items']) ||
            !array_is_list($snapshot['items']) || count($snapshot['items'])>1000) {
            throw new InvalidArgumentException('Invalid read-only catalog snapshot');
        }
        $items=[];
        $paths=[];
        foreach ($snapshot['items'] as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new InvalidArgumentException('Catalog item must be object');
            }
            foreach (array_keys($entry) as $field) {
                if (!in_array($field,self::FIELDS,true)) {
                    throw new InvalidArgumentException('Private or unsupported service field');
                }
            }
            foreach (['id','slug','title','description','path','status'] as $field) {
                if (!is_string($entry[$field] ?? null)) {
                    throw new InvalidArgumentException('Missing public service field: '.$field);
                }
            }
            $id = $entry['id'];
            $slug = $entry['slug'];
            if (preg_match('/\A[a-z][a-z0-9_-]{5,79}\z/D',$id)!==1 ||
                strlen($slug)>80 || preg_match(self::SLUG,$slug)!==1 ||
                ($entry['path'] ?? null) !== '/servicos/'.$slug.'/' ||
                strlen($entry['title'])<1 || strlen($entry['title'])>200 ||
                strlen($entry['description'])<1 || strlen($entry['description'])>500 ||
                !in_array($entry['status'],['draft','in_review','published'],true) ||
                ($previous && $entry['status']!=='published') ||
                !is_int($entry['version'] ?? null) || $entry['version']<1 ||
                $id==='' || isset($items[$id]) || isset($paths[$entry['path']])) {
                throw new InvalidArgumentException('Unsafe or duplicate public service item');
            }
            if (array_key_exists('offerRef',$entry) &&
                (!is_string($entry['offerRef']) ||
                preg_match('/\Aoffer_[a-z0-9_-]{6,80}\z/D',$entry['offerRef'])!==1)) {
                throw new InvalidArgumentException('Unsafe public offer reference');
            }
            $items[$id] = $entry;
            $paths[$entry['path']] = true;
        }
        return $items;
    }

    public static function fictionalExample(): array
    {
        $services = [
            [
                'id'=>'service_site_01','slug'=>'criacao-de-sites',
                'title'=>'Criação de sites profissionais — exemplo',
                'description'=>'Páginas institucionais organizadas para explicar serviços, diferenciais e informações de contato.',
                'path'=>'/servicos/criacao-de-sites/','status'=>'published','version'=>1,
            ],
            [
                'id'=>'service_landing_01','slug'=>'landing-pages',
                'title'=>'Landing Pages para empresas — exemplo',
                'description'=>'Páginas focadas em campanhas, ofertas e contato comercial, com estrutura responsiva.',
                'path'=>'/servicos/landing-pages/','status'=>'published','version'=>1,
            ],
        ];
        $previous = [
            'schemaVersion'=>'1.0','releaseId'=>'release_demo_before_01',
            'publishedAt'=>'2026-11-09T03:00:00Z','items'=>$services,
        ];
        $next=$services;
        $next[1]['title']='Landing Pages comerciais revisadas — exemplo';
        $next[1]['version']=2;
        $next[]=[
            'id'=>'service_onepage_01','slug'=>'onepage',
            'title'=>'Onepage institucional — exemplo',
            'description'=>'Apresentação resumida do negócio em uma página, com navegação por seções.',
            'path'=>'/servicos/onepage/','status'=>'in_review','version'=>1,
        ];
        return self::preview($previous,[
            'schemaVersion'=>'1.0','releaseId'=>'release_demo_next_02',
            'publishedAt'=>'2026-11-09T04:00:00Z','items'=>$next,
        ]);
    }
}
