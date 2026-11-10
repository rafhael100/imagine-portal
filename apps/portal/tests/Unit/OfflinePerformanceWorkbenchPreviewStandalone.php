<?php
declare(strict_types=1);
putenv('APP_ENV=testing');
putenv('PORTAL_F59_WORKBENCH_LAB=1');
require_once dirname(__DIR__,2).'/app/Domain/PerformanceDashboard/OfflinePerformanceWorkbenchPreview.php';
use App\Domain\PerformanceDashboard\OfflinePerformanceWorkbenchPreview as Preview;

$sample=[
 'schema_version'=>'perf.portal.workbench.offline.synthetic.v1',
 'organization_ref'=>'org-demo-a','tenant_ref'=>'perf-demo-a',
 'service_ref'=>'svc-demo-a',
 'channel_contract'=>'perf.portal.channel-map.synthetic.v1',
 'portal_stage_mapping_ready'=>false,
 'portal_stage_mapping_status'=>'pending_explicit_enum_alignment',
 'read_only'=>true,'synthetic_only'=>true,'contracted'=>false,
 'portal_identity_authenticated'=>false,'ready_for_production'=>false,
 'dispatch_enabled'=>false,'billing_enabled'=>false,
 'counts'=>['managed_campaigns'=>1,'paused_campaigns'=>1,
   'approvals_pending'=>0,'approvals_approved_internal'=>1,'approvals_rejected'=>0],
 'campaigns'=>[['ref'=>'demo-cam-test','title'=>'Demo paused (exemplo)',
   'portal_channel'=>'meta_ads','performance_state'=>'paused_demo','portal_stage'=>null]],
 'requests'=>[['ref'=>'demo-req-demo-test','campaign_ref'=>'demo-cam-test',
   'operation'=>'campaign.pause','performance_status'=>'approved_demo_no_execution','portal_stage'=>null]],
];
$n=0;
$ok=static function(bool $state,string $label)use(&$n):void{
    if(!$state)throw new RuntimeException('PORTAL-F59 '.$label);
    ++$n;
};
$check=static function(mixed $data, bool $good) use($ok):void{
    $result=Preview::fromDto($data);
    $ok(($result instanceof Preview)===$good,'DTO accepted/denied');
    if($result instanceof Preview) {
        $ok($result->isRealTime()===false,'not realtime');
        $ok($result->isAuthenticated()===false,'not authenticated');
        $ok($result->canExecuteActions()===false,'no actions');
        $ok($result->isForSale()===false,'not commercial');
    }
};
$check($sample,true);
foreach([
 ['fake contracted',['contracted'=>true]],
 ['fake enabled',['dispatch_enabled'=>true]],
 ['billing enabled',['billing_enabled'=>true]],
 ['real tenant',['tenant_ref'=>'customer_123']],
 ['wrong org',['organization_ref'=>'org-other']],
 ['version change',['schema_version'=>'perf.portal.workbench.v2']],
 ['mapping falsely ready',['portal_stage_mapping_ready'=>true]],
 ['extra key',['access_token'=>'secret']],
 ['incorrect counts',['counts'=>array_replace($sample['counts'],['managed_campaigns'=>2])]],
 ['states wrongly remapped',['campaigns'=>[array_replace($sample['campaigns'][0],['portal_stage'=>'mock_draft'])]]],
 ['requests wrongly remapped',['requests'=>[array_replace($sample['requests'][0],['portal_stage'=>'awaiting_mock_review'])]]],
 ['unknown channel',['campaigns'=>[array_replace($sample['campaigns'][0],['portal_channel'=>'meta'])]]],
 ['unknown status',['requests'=>[array_replace($sample['requests'][0],['performance_status'=>'published'])]]],
 ['unmatched campaign',['requests'=>[array_replace($sample['requests'][0],['campaign_ref'=>'demo-cam-unknown'])]]],
] as [$label,$change]) {
    $data=array_replace($sample,$change);
    $ok(Preview::fromDto($data)===null,"reject $label");
}
$dup=$sample;
$dup['campaigns'][]=$dup['campaigns'][0];
$dup['counts']['managed_campaigns']=2;
$ok(Preview::fromDto($dup)===null,'duplicate campaign');
$dup=$sample;
$dup['requests'][]=$dup['requests'][0];
$dup['counts']['approvals_approved_internal']=2;
$ok(Preview::fromDto($dup)===null,'duplicate request');
if(($file=$argv[1]??null)!==null){
    $parsed=json_decode((string)file_get_contents($file),true,24,JSON_THROW_ON_ERROR);
    $bridge=Preview::fromDto($parsed);
    $ok($bridge instanceof Preview,'real PostgreSQL-generated Performance F84 DTO');
    $ok(count($bridge->campaigns)===2,'actual PG campaigns');
    $ok(count($bridge->requests)===6,'actual PG reviews');
    $ok($bridge->canExecuteActions()===false,'cannot actuate from cross-repo data');
}
putenv('PORTAL_F59_WORKBENCH_LAB=0');
$ok(Preview::fromDto($sample)===null,'flag missing');
putenv('PORTAL_F59_WORKBENCH_LAB=1');
putenv('APP_ENV=production');
$ok(Preview::fromDto($sample)===null,'production disabled');
echo "PASS PORTAL-F59 $n assertions (offline-only)\n";
