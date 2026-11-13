<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Organizations\OrganizationId;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PostgreSQL 18 -> the SAME five-column layout as the fictional
 * preparation table. Laboratory only; no real customer read path.
 */
final readonly class DisposablePg18ProjectPreparationReader
{
    // Names shown only to the reviewer of the synthetic offline preview.
    // Product/checkpoint codes in the PostgreSQL schema remain unchanged.
    private const CHECKPOINT_LABELS = [
        'briefing' => 'Briefing',
        'content' => 'Conteúdo',
        'domain' => 'Domínio',
        'consent' => 'Consentimento',
        'goals' => 'Objetivos',
        'ad_accounts' => 'Contas de anúncios',
        'creatives' => 'Criativos',
        'budget' => 'Orçamento',
    ];

    private const PRODUCTS = [
        'sites_onepage' => [
            'Sites Imagine · Onepage',
            ['briefing','content','domain'],
        ],
        'sites_landing_page' => [
            'Sites Imagine · Landing Page',
            ['briefing','content','consent'],
        ],
        'performance_ai' => [
            'Performance AI',
            ['goals','ad_accounts','creatives','budget'],
        ],
    ];

    public function __construct(private PDO $db)
    {
        if (getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_BROWSER_PG_LAB')!=='DISPOSABLE_PG18'
            || getenv('PGHOST')!=='127.0.0.1'
            || getenv('PGPORT')!=='5432'
            || getenv('PGDATABASE')!=='portal_browser_lab'
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'
            || $db->inTransaction()
            || $db->query('SELECT current_database()')->fetchColumn()!=='portal_browser_lab'
            || (int)$db->query(
                "SELECT current_setting('server_version_num')::integer/10000"
            )->fetchColumn()!==18) {
            throw new RuntimeException('PG18 preparation projection is disposable CI only.');
        }
    }

    public function project(OrganizationId $organization): DisposablePg18ProjectPreparationResult
    {
        if (!preg_match(
            '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',
            $organization->value
        ) || $this->db->inTransaction()) {
            return DisposablePg18ProjectPreparationResult::rejected();
        }
        try {
            $this->db->beginTransaction();
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $org=$this->db->prepare(
                'SELECT status FROM public.portal_organizations
                  WHERE id=CAST(? AS uuid)'
            );
            $org->execute([$organization->value]);
            if ($org->fetchColumn()!=='active') {
                $this->db->rollBack();
                return DisposablePg18ProjectPreparationResult::rejected();
            }
            $sql=<<<'SQL'
SELECT p.id,p.project_ref,p.product_code,p.state,
       p.source_claim_id,c.state AS claim_state
  FROM public.portal_service_projects p
  LEFT JOIN public.portal_service_resource_claims c
    ON c.id=p.source_claim_id
   AND c.organization_id=p.organization_id
   AND c.product_code=p.product_code
 WHERE p.organization_id=CAST(? AS uuid)
 ORDER BY p.project_ref
 LIMIT 11
SQL;
            $q=$this->db->prepare($sql);
            $q->execute([$organization->value]);
            $projects=$q->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($projects) || count($projects)===0 || count($projects)>10) {
                $this->db->rollBack();
                return DisposablePg18ProjectPreparationResult::rejected();
            }

            $byId=[];
            foreach ($projects as $p) {
                if (isset($byId[$p['id']])
                    || !isset(self::PRODUCTS[$p['product_code']])
                    || !in_array($p['state'],['draft','review'],true)
                    || ($p['source_claim_id']!==null && $p['claim_state']!=='candidate')
                    || ($p['state']==='review' && $p['source_claim_id']===null)) {
                    $this->db->rollBack();
                    return DisposablePg18ProjectPreparationResult::rejected();
                }
                $byId[$p['id']] = [
                    'project'=>$p,'items'=>[],
                ];
            }
            $placeholders=implode(',',array_fill(0,count($projects),'CAST(? AS uuid)'));
            $checks=$this->db->prepare(
                'SELECT project_id,organization_id,checkpoint_code,state
                   FROM public.portal_service_project_checkpoints
                  WHERE project_id IN ('.$placeholders.')
                  ORDER BY project_id,checkpoint_code
                  LIMIT 41'
            );
            $checks->execute(array_keys($byId));
            $rows=$checks->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows)>40) {
                $this->db->rollBack();
                return DisposablePg18ProjectPreparationResult::rejected();
            }
            foreach ($rows as $r) {
                $id=$r['project_id'];
                if (!isset($byId[$id])
                    || $r['organization_id']!==$organization->value
                    || !in_array(
                        $r['checkpoint_code'],
                        self::PRODUCTS[$byId[$id]['project']['product_code']][1],
                        true
                    )
                    || !in_array($r['state'],['pending','reviewed'],true)
                    || isset($byId[$id]['items'][$r['checkpoint_code']])) {
                    $this->db->rollBack();
                    return DisposablePg18ProjectPreparationResult::rejected();
                }
                $byId[$id]['items'][$r['checkpoint_code']]=$r['state'];
            }

            $table=[];
            foreach ($byId as $entry) {
                $p=$entry['project'];
                [$label,$required]=self::PRODUCTS[$p['product_code']];
                $missing=[];
                $reviewed=0;
                foreach ($required as $code) {
                    if (($entry['items'][$code]??null)==='reviewed') {
                        $reviewed++;
                    } else {
                        $missing[]=$code;
                    }
                }
                if ($p['state']==='review' && $missing!==[]) {
                    $this->db->rollBack();
                    return DisposablePg18ProjectPreparationResult::rejected();
                }
                $table[]=[
                    $label,
                    $p['project_ref'],
                    $p['state']==='review'
                        ? 'Revisão interna fictícia' : 'Rascunho de exemplo',
                    $reviewed.'/'.count($required).' itens revisados',
                    $missing===[] ? 'Sem itens pendentes no exemplo'
                        : 'Pendente no exemplo: '.implode(', ',array_map(
                            static fn (string $code): string => self::CHECKPOINT_LABELS[$code],
                            $missing,
                        )),
                ];
            }
            $this->db->commit();
            return DisposablePg18ProjectPreparationResult::prepared($table);
        } catch (Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return DisposablePg18ProjectPreparationResult::rejected();
        }
    }

    public static function authorizesCustomerAccess(): bool { return false; }
    public static function authorizesReview(): bool { return false; }
}
