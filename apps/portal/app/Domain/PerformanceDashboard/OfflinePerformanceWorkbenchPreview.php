<?php
declare(strict_types=1);

namespace App\Domain\PerformanceDashboard;

/**
 * PORTAL-F59: read-only, offline, test-only consumer for the Performance F84
 * candidate DTO. Does not authenticate sessions or grant commercial access.
 * It deliberately refuses to present Performance states as Portal stages.
 */
final readonly class OfflinePerformanceWorkbenchPreview
{
    private function __construct(
        public string $organizationRef,
        public string $serviceRef,
        public array $counts,
        public array $campaigns,
        public array $requests,
    ) {}

    public static function fromDto(mixed $dto): ?self
    {
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_F59_WORKBENCH_LAB')!=='1') return null;
        if (!is_array($dto) || array_keys($dto)!==[
            'schema_version','organization_ref','tenant_ref','service_ref',
            'channel_contract','portal_stage_mapping_ready',
            'portal_stage_mapping_status','read_only','synthetic_only',
            'contracted','portal_identity_authenticated','ready_for_production',
            'dispatch_enabled','billing_enabled','counts','campaigns','requests'
        ] || $dto['schema_version']!=='perf.portal.workbench.offline.synthetic.v1'
          || $dto['organization_ref']!=='org-demo-a'
          || $dto['tenant_ref']!=='perf-demo-a'
          || $dto['service_ref']!=='svc-demo-a'
          || $dto['channel_contract']!=='perf.portal.channel-map.synthetic.v1'
          || $dto['portal_stage_mapping_ready']!==false
          || $dto['portal_stage_mapping_status']!=='pending_explicit_enum_alignment'
          || $dto['read_only']!==true || $dto['synthetic_only']!==true
          || $dto['contracted']!==false || $dto['portal_identity_authenticated']!==false
          || $dto['ready_for_production']!==false || $dto['dispatch_enabled']!==false
          || $dto['billing_enabled']!==false
          || !is_array($dto['counts'])
          || array_keys($dto['counts'])!==[
              'managed_campaigns','paused_campaigns','approvals_pending',
              'approvals_approved_internal','approvals_rejected'
          ] || !is_array($dto['campaigns']) || !array_is_list($dto['campaigns'])
          || !is_array($dto['requests']) || !array_is_list($dto['requests'])
          || count($dto['campaigns'])>20 || count($dto['requests'])>20) return null;
        foreach ($dto['counts'] as $v) {
            if(!is_int($v) || $v<0 || $v>20) return null;
        }
        $refs=[];$paused=0;
        foreach($dto['campaigns'] as $item) {
            if(!is_array($item) || array_keys($item)!==[
                'ref','title','portal_channel','performance_state','portal_stage'
            ] || !is_string($item['ref'])
              || preg_match('/^demo-cam-[a-z0-9-]{1,70}$/D',$item['ref'])!==1
              || !is_string($item['title'])
              || preg_match('/^[a-zA-Z0-9 ()-]{1,120}$/D',$item['title'])!==1
              || !in_array($item['portal_channel'],['google_ads','meta_ads'],true)
              || PerformanceCampaignObservationState::tryFrom($item['performance_state'])===null
              || $item['portal_stage']!==null || isset($refs[$item['ref']])) return null;
            $refs[$item['ref']]=true;
            if($item['performance_state']==='paused_demo')++$paused;
        }
        $requestIds=[];$statusCount=[
            'pending_human_demo'=>0,'approved_demo_no_execution'=>0,'rejected_demo'=>0
        ];
        foreach($dto['requests'] as $item) {
            if(!is_array($item) || array_keys($item)!==[
                'ref','campaign_ref','operation','performance_status','portal_stage'
            ] || !is_string($item['ref'])
              || preg_match('/^demo-(?:req-demo|request-demo)-[a-z0-9-]{1,70}$/D',$item['ref'])!==1
              || !is_string($item['campaign_ref'])
              || !isset($refs[$item['campaign_ref']])
              || !in_array($item['operation'],[
                  'campaign.create','campaign.pause',
                  'campaign.replace','campaign.release'
              ],true)
              || !is_string($item['performance_status'])
              || PerformanceReviewObservationState::tryFrom($item['performance_status'])===null
              || $item['portal_stage']!==null || isset($requestIds[$item['ref']])) return null;
            $requestIds[$item['ref']]=true;
            ++$statusCount[$item['performance_status']];
        }
        if ($dto['counts']['managed_campaigns']!==count($dto['campaigns'])
            || $dto['counts']['paused_campaigns']!==$paused
            || $dto['counts']['approvals_pending']!==$statusCount['pending_human_demo']
            || $dto['counts']['approvals_approved_internal']!==$statusCount['approved_demo_no_execution']
            || $dto['counts']['approvals_rejected']!==$statusCount['rejected_demo']) return null;

        return new self(
            $dto['organization_ref'],$dto['service_ref'],
            $dto['counts'],$dto['campaigns'],$dto['requests']
        );
    }

    public function isRealTime(): bool { return false; }
    public function isAuthenticated(): bool { return false; }
    public function canExecuteActions(): bool { return false; }
    public function isForSale(): bool { return false; }
}
