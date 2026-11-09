<?php

declare(strict_types=1);

namespace App\Presentation\Preview;

use JsonException;

/**
 * PVIEW-PERF-06: Static, offline, strictly allowlisted PostgreSQL *demo*
 * contract only. It is not a client Portal -> Performance connection or a
 * real permission/billing/campaign status.
 */
final class PerformanceStoragePreview
{
    private const FIXTURE = 'preview/fixtures/performance-storage-preview-v1.json';

    /** @return array<string,mixed>|null */
    public static function loadStatic(
        string $expectedOrganization = 'org-demo-a',
        string $expectedTenant = 'perf-demo-a',
    ): ?array {
        $path=dirname(__DIR__,3).'/'.self::FIXTURE;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return null;
        }
        $size=filesize($path);
        if (!is_int($size) || $size<100 || $size>32768) {
            return null;
        }
        $raw=file_get_contents($path);
        return is_string($raw)
            ? self::parse($raw,$expectedOrganization,$expectedTenant)
            : null;
    }

    /** @return array<string,mixed>|null */
    public static function parse(
        string $json,
        string $organization,
        string $tenant
    ): ?array {
        if (strlen($json)<100 || strlen($json)>32768
            || $organization!== 'org-demo-a' || $tenant!=='perf-demo-a') {
            return null;
        }
        try {
            $data=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        } catch(JsonException) {
            return null;
        }
        if (!is_array($data) || !self::fields($data,[
            'schema_version','producer','source','portal_organization_ref',
            'performance_tenant_ref','portal_service_ref','read_only',
            'contracted','dispatch_enabled','commercial','metrics',
            'campaigns','approvals',
        ]) || $data['schema_version']!=='perf.portal.storage.synthetic.v1'
            || $data['producer']!=='imagine-performance-ai-lab'
            || $data['source']!=='synthetic_postgresql_only'
            || $data['portal_organization_ref']!==$organization
            || $data['performance_tenant_ref']!==$tenant
            || $data['portal_service_ref']!=='svc-demo-a'
            || $data['read_only']!==true
            || $data['contracted']!==false
            || $data['dispatch_enabled']!==false) {
            return null;
        }
        $commercial=$data['commercial'];
        if (!is_array($commercial) || !self::fields($commercial,[
            'capacity_state','price_state'
        ]) || $commercial['capacity_state']!=='unknown_not_for_sale_demo'
            || $commercial['price_state']!=='not_homologated') {
            return null;
        }
        $m=$data['metrics'];
        if (!is_array($m) || !self::fields($m,[
            'managed_campaigns','paused_campaigns','approvals_pending',
            'approvals_approved_internal','approvals_rejected','clicks','media_spend',
        ]) || $m['clicks']!==null || $m['media_spend']!==null) {
            return null;
        }
        foreach (['managed_campaigns','paused_campaigns','approvals_pending',
                  'approvals_approved_internal','approvals_rejected'] as $key) {
            if (!is_int($m[$key]) || $m[$key]<0 || $m[$key]>20) {
                return null;
            }
        }

        $campaigns=$data['campaigns'];
        if (!is_array($campaigns) || !array_is_list($campaigns)
            || count($campaigns)>20) {
            return null;
        }
        $known=[];$managed=0;$paused=0;
        foreach ($campaigns as $row) {
            if (!is_array($row) || !self::fields($row,[
                'ref','channel','title','state','managed'
            ]) || !is_string($row['ref'])
                || !preg_match('/^demo-cam-[a-z0-9-]{1,70}$/D',$row['ref'])
                || isset($known[$row['ref']])
                || !in_array($row['channel'],['google_ads','meta'],true)
                || !is_string($row['title'])
                || mb_strlen($row['title'])>120
                || !preg_match('/^[a-zA-Z0-9 -]{1,100} \(exemplo\)$/D',$row['title'])
                || !in_array($row['state'],[
                    'draft_demo','paused_demo','archived_demo'
                ],true)
                || $row['managed']!==true) {
                return null;
            }
            $known[$row['ref']]=true;
            ++$managed;
            if ($row['state']==='paused_demo') {
                ++$paused; // paused still counts as managed
            }
        }
        if ($managed!==$m['managed_campaigns']
            || $paused!==$m['paused_campaigns']) {
            return null;
        }

        $approvals=$data['approvals'];
        if (!is_array($approvals) || !array_is_list($approvals)
            || count($approvals)>20) {
            return null;
        }
        $knownApprovals=[];$pending=0;$approved=0;$rejected=0;
        foreach ($approvals as $row) {
            if (!is_array($row) || !self::fields($row,[
                'ref','campaign_ref','operation','status','manifest_version',
            ]) || !is_string($row['ref'])
                || !preg_match(
                    '/^demo-(?:req-demo|request-demo)-[a-z0-9-]{1,70}$/D',
                    $row['ref']
                ) || isset($knownApprovals[$row['ref']])
                || !is_string($row['campaign_ref'])
                || !isset($known[$row['campaign_ref']])
                || !in_array($row['operation'],[
                    'campaign.create','campaign.pause',
                    'campaign.replace','campaign.release'
                ],true)
                || !in_array($row['status'],[
                    'pending_human_demo',
                    'approved_demo_no_execution',
                    'rejected_demo'
                ],true)
                || $row['manifest_version']!=='demo-v1') {
                return null;
            }
            $knownApprovals[$row['ref']]=true;
            if ($row['status']==='pending_human_demo') {
                ++$pending;
            } elseif ($row['status']==='approved_demo_no_execution') {
                ++$approved;
            } else {
                ++$rejected;
            }
        }
        if ($pending!==$m['approvals_pending']
            || $approved!==$m['approvals_approved_internal']
            || $rejected!==$m['approvals_rejected']) {
            return null;
        }
        return $data;
    }

    /** Only known safe presentation values, never raw UI markup. */
    public static function channelLabel(string $channel): string
    {
        return match($channel) {
            'google_ads'=>'Google Ads',
            'meta'=>'Meta (Instagram e/ou Facebook)',
            default=>'Canal indisponível',
        };
    }

    public static function stateLabel(string $state): string
    {
        return match($state) {
            'draft_demo'=>'Rascunho (DEMO)',
            'paused_demo'=>'Pausada (DEMO)',
            'archived_demo'=>'Arquivada (DEMO)',
            default=>'Estado indisponível',
        };
    }

    public static function approvalLabel(string $state): string
    {
        return match($state) {
            'pending_human_demo'=>'Aguardando análise (DEMO)',
            'approved_demo_no_execution'=>'Aprovada internamente, sem execução',
            'rejected_demo'=>'Rejeitada (DEMO)',
            default=>'Estado indisponível',
        };
    }

    public static function operationLabel(string $name): string
    {
        return match($name) {
            'campaign.create'=>'Criar campanha',
            'campaign.pause'=>'Pausar campanha',
            'campaign.replace'=>'Substituir campanha',
            'campaign.release'=>'Liberar campanha',
            default=>'Ação indisponível',
        };
    }

    /** @param array<string,mixed> $data
     *  @param list<string> $expected */
    private static function fields(array $data,array $expected): bool
    {
        $found=array_keys($data);
        sort($found,SORT_STRING);
        sort($expected,SORT_STRING);
        return $found===$expected;
    }
}
