<?php
declare(strict_types=1);

putenv('APP_ENV=testing');
putenv('PORTAL_F62_CREDENTIAL_WORKBENCH_LAB=1');
$root=dirname(__DIR__,2);
require_once $root.'/app/Domain/Catalog/PerformanceChannel.php';
require_once $root.'/app/Domain/PerformanceDashboard/PerformanceCampaignObservationState.php';
require_once $root.'/app/Domain/PerformanceDashboard/PerformanceReviewObservationState.php';
require_once $root.'/app/Domain/PerformanceDashboard/CredentialBoundPerformanceWorkbenchPreview.php';

use App\Domain\PerformanceDashboard\CredentialBoundPerformanceWorkbenchPreview as Preview;

$fixture=[
 'schema_version'=>'perf.portal.credential-bound-workbench.synthetic.v1',
 'status'=>'credential_scoped_demo_workbench_only',
 'synthetic_only'=>true,'read_only'=>true,'credential_binding_confirmed'=>true,
 'portal_identity_authenticated'=>false,'real_human_approval_verified'=>false,
 'provider_connected'=>false,'authorization_eligible'=>false,
 'ready_for_production'=>false,'dispatch_enabled'=>false,'billing_enabled'=>false,
 'counts'=>[
    'account_candidates'=>2,'linked_campaigns'=>1,'visible_reviews'=>1,
    'review_pending'=>0,'review_internal_no_execution'=>1,'review_rejected'=>0
 ],
 'accounts'=>[
    ['portal_channel'=>'google_ads','account_ref'=>'demo_google_a',
      'connection_state'=>'unverified_demo'],
    ['portal_channel'=>'meta_ads','account_ref'=>'demo_meta_a',
      'connection_state'=>'unverified_demo']
 ],
 'campaigns'=>[[
    'campaign_ref'=>'cam-demo-f89-a','campaign_title'=>'F89 demo campaign A',
    'portal_channel'=>'meta_ads','campaign_observation_state'=>'draft_demo',
    'account_ref'=>'demo_meta_a','account_state'=>'unverified_demo',
    'portal_stage'=>null
 ]],
 'reviews'=>[[
    'campaign_ref'=>'cam-demo-f89-a','request_ref'=>'req-demo-f97-a-approved',
    'operation'=>'campaign.create','portal_channel'=>'meta_ads',
    'review_observation_state'=>'approved_demo_no_execution','journaled'=>true,
    'portal_stage'=>null
 ]],
];
$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{
    if(!$ok)throw new RuntimeException('FAIL PORTAL-F62 '.$label);
    ++$n;
};
$preview=Preview::fromDto($fixture);
$check($preview instanceof Preview,'accept strict synthetic candidate');
$check($preview->counts['account_candidates']===2,'counts');
$check($preview->counts['review_internal_no_execution']===1,'approval remains internal');
$check(!$preview->hasPortalIdentity(),'no Portal IAM');
$check(!$preview->canPublishAds(),'no external actions');
$check(!$preview->canBill(),'no billing');
$check(!$preview->isRealTime(),'offline-only');
$check($preview->reviews[0]['portal_stage']===null,'no forged workflow stage');
$mutate=static function(array $changes)use($fixture, $check):void {
    $copy=array_replace($fixture,$changes);
    $check(Preview::fromDto($copy)===null,'reject malformed/privileged candidate');
};
foreach([
  ['schema_version'=>'perf.portal.credential-bound-workbench.v2'],
  ['status'=>'authenticated_operational'],
  ['synthetic_only'=>false],
  ['read_only'=>false],
  ['credential_binding_confirmed'=>false],
  ['portal_identity_authenticated'=>true],
  ['real_human_approval_verified'=>true],
  ['provider_connected'=>true],
  ['authorization_eligible'=>true],
  ['ready_for_production'=>true],
  ['dispatch_enabled'=>true],
  ['billing_enabled'=>true],
  ['access_token'=>'secret'],
  ['counts'=>array_replace($fixture['counts'],['account_candidates'=>3])],
  ['counts'=>array_replace($fixture['counts'],['review_internal_no_execution'=>0])],
  ['accounts'=>array_merge($fixture['accounts'],[$fixture['accounts'][0]])],
  ['accounts'=>[array_replace($fixture['accounts'][0],['account_ref'=>'act_123'])]],
  ['campaigns'=>[array_replace($fixture['campaigns'][0],['portal_channel'=>'google_ads'])]],
  ['campaigns'=>[array_replace($fixture['campaigns'][0],['account_ref'=>'demo_missing'])]],
  ['campaigns'=>[array_replace($fixture['campaigns'][0],['portal_stage'=>'mock_review'])]],
  ['campaigns'=>[array_replace($fixture['campaigns'][0],['campaign_observation_state'=>'serving'])]],
  ['campaigns'=>[array_replace($fixture['campaigns'][0],['campaign_title'=>'<script>evil</script>'])]],
  ['reviews'=>[array_replace($fixture['reviews'][0],['campaign_ref'=>'cam-demo-other'])]],
  ['reviews'=>[array_replace($fixture['reviews'][0],['journaled'=>false])]],
  ['reviews'=>[array_replace($fixture['reviews'][0],['review_observation_state'=>'approved'])]],
  ['reviews'=>[array_replace($fixture['reviews'][0],['portal_stage'=>'approved'])]],
  ['reviews'=>array_merge($fixture['reviews'],[$fixture['reviews'][0]])],
] as $mutation) $mutate($mutation);

if(isset($argv[1])){
    $path=$argv[1];
    $check(is_file($path)&&is_readable($path),'SQL-backed JSON present');
    $loaded=json_decode((string)file_get_contents($path),true,24,JSON_THROW_ON_ERROR);
    $result=Preview::fromDto($loaded);
    $check($result instanceof Preview,'accept actual Performance F100 PostgreSQL JSON');
    $check($result->counts['account_candidates']===2,'actual F100 candidate accounts');
    $check($result->counts['linked_campaigns']===1,'actual F100 linked campaign');
    $check($result->counts['visible_reviews']===1,'actual F100 review');
    $check(!$result->hasPortalIdentity()&&!$result->canPublishAds()&&!$result->canBill(),
        'actual PostgreSQL DTO never grants Portal rights');
}
putenv('PORTAL_F62_CREDENTIAL_WORKBENCH_LAB=0');
$check(Preview::fromDto($fixture)===null,'explicit lab flag required');
putenv('PORTAL_F62_CREDENTIAL_WORKBENCH_LAB=1');
putenv('APP_ENV=production');
$check(Preview::fromDto($fixture)===null,'production disabled');
echo "PASS PORTAL-F62 $n assertions: synthetic-only credential-bound preview and safety gates\n";
