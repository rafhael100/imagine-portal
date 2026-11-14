<?php
declare(strict_types=1);

// Portal F419 → V7 signed planned-media agreement REVIEW only.
// No network, real client, acceptance, database, PSP, CRM or ad API writes.
require_once __DIR__.'/../app/Domain/Performance/ActivationPostpaidPreview.php';
require_once __DIR__.'/../app/Domain/PaymentRouting/GatewayRoutingPreview.php';
require_once __DIR__.'/../app/Domain/PaymentRouting/GatewayPolicyReview.php';
require_once __DIR__.'/../app/Domain/PaymentRouting/PortalGatewayReviewExporter.php';
require_once __DIR__.'/../app/Domain/Performance/ActivationGatewayReviewLab.php';
require_once __DIR__.'/../app/Domain/Performance/PerformanceChannelReviewEnvelope.php';
require_once __DIR__.'/../app/Domain/Performance/PortalPerformanceV7AgreementPlanLabIssuer.php';

use App\Domain\Performance\ActivationGatewayReviewLab as Gateway;
use App\Domain\Performance\PerformanceChannelReviewEnvelope as ReviewIssuer;
use App\Domain\Performance\PortalPerformanceV7AgreementPlanLabIssuer as PlanIssuer;

function planMust(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException('Portal V7 plan CI: '.$label);
}
function planDeny(callable $fn,string $label):void{
    try {$fn();}catch(InvalidArgumentException|RuntimeException|LogicException){return;}
    throw new RuntimeException('Portal V7 plan CI accepted: '.$label);
}
if(getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_PERF_V7_PLAN_AGREEMENT_LAB')!=='1') {
    throw new RuntimeException('Lab gates not present');
}
$activationKey=str_repeat('A',48);
$agreementKey=str_repeat('G',48);
$org='org-v7-plan-001';
$order='order-v7-plan-001';
$scope=[
    'tenant_id'=>'tenant_v7plan_12345678',
    'agreement_id'=>'agreement_v7plan_12345678',
    'period'=>'2026-11',
    'organization_ref'=>$org,
    'order_ref'=>$order,
    'policy_revision'=>3,
];
$eligible=['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>false];
$psp=[
    'revision'=>3,'mode'=>'manual','currency'=>'BRL',
    'preferred_gateway'=>'asaas',
    'providers'=>[
        'asaas'=>[
            'enabled'=>true,'approved'=>true,'healthy'=>true,'fee_version'=>'SIM-TEST',
            'methods'=>['pix'=>['enabled'=>true,'fixed_cents'=>100,
                'variable_bps'=>100,'settlement_days'=>1]],
        ],
        'c6'=>[
            'enabled'=>false,'approved'=>false,'healthy'=>false,
            'fee_version'=>'SIM-C6','methods'=>[],
        ],
    ],
];
$original=Gateway::preview(['google_ads','meta_ads'],$eligible,$psp,'pix',
    $org,$order,3,$activationKey);
$reviewIssued='2026-11-14T21:00:00Z';
$reviewNow='2026-11-14T21:05:00Z';
$review=ReviewIssuer::issue($original,$eligible,$org,$order,3,
    $reviewIssued,$activationKey);
$now=(new DateTimeImmutable($reviewNow))->getTimestamp();
$plan=['google_ads'=>100000,'meta_ads'=>70000];
$sign=static function(
    ?array $envelope=null,
    ?array $expectedScope=null,
    ?array $media=null,
    ?string $nonce=null,
    ?string $activationSecret=null,
    ?string $planSecret=null
)use($review,$original,$eligible,$scope,$plan,$reviewNow,$now,
      $activationKey,$agreementKey):array{
    return PlanIssuer::issue(
        $envelope??$review,
        $original,$eligible,
        $expectedScope??$scope,$media??$plan,
        $reviewNow,'k_v7_plan_demo_01',
        $nonce??'nonce_portal_v7_plan_12345678',
        $now-1,$now+90,
        $activationSecret??$activationKey,
        $planSecret??$agreementKey
    );
};
$valid=$sign();
planMust($valid['payload']['activation_cents']===98000,'activation R490 per selected channel');
planMust($valid['payload']['planned_media_total_cents']===170000,'R1700 planned media paid directly');
planMust($valid['payload']['estimated_management_fee_cents']===37000,
    'Meta R170 + Google R200 = R370 monthly estimated management');
planMust($valid['payload']['minimum_management_fee_per_channel_cents']===17000,
    'fixed management floor');
planMust($valid['payload']['postpaid_policy_version']===
    'performance-postpaid-170-per-channel-2026-11-14',
    'policy version is signed');
planMust($valid['payload']['activation_review_mac']===$review['mac'],
    'bound to authentic prior F419 order+CRM review');
planMust($valid['payload']['channels']===['google_ads','meta_ads'],
    'strict canonical channel list');
foreach(['billable','can_charge','can_activate','ads_write_enabled',
    'customer_acceptance_proven','portal_iam_authenticated',
    'provider_spend_verified'] as $flag){
    planMust(($valid['payload'][$flag]??null)===false,'lab authority false '.$flag);
}
planMust(preg_match('/\A[a-f0-9]{64}\z/D',$valid['mac'])===1,
    'distinct HMAC hex');
planMust(!str_contains(json_encode($valid,JSON_THROW_ON_ERROR),$agreementKey),
    'no secret in signed review');

// Exact plan scopes: a selected channel may neither be omitted nor
// smuggled in, and media floors are per-channel (not shared).
planDeny(fn()=> $sign(null,null,['meta_ads'=>70000]),'channel omission');
planDeny(fn()=> $sign(null,null,[
    'chatgpt_ads'=>120000,'google_ads'=>100000,'meta_ads'=>70000
]),'unselected ChatGPT channel');
planDeny(fn()=> $sign(null,null,[
    'google_ads'=>99999,'meta_ads'=>70000
]),'Google below minimum');
planDeny(fn()=> $sign(null,null,[
    'google_ads'=>100000,'meta_ads'=>69999
]),'Meta below minimum');
planDeny(fn()=> $sign(null,null,[
    'google_ads'=>100000.0,'meta_ads'=>70000
]),'no floating media money');
planDeny(fn()=> $sign(null,null,[
    'google_ads'=>100000,'meta_ads'=>100000001
]),'media cap');
planDeny(fn()=> $sign(null,null,[
    'google_ads'=>100000,'meta_ads'=>70000,'extra'=>1
]),'extra media item');
$badScope=$scope;$badScope['period']='2026-13';
planDeny(fn()=> $sign(null,$badScope),'invalid month');
$badScope=$scope;$badScope['organization_ref']='org-other-001';
planDeny(fn()=> $sign(null,$badScope),'wrong organization review');
$badScope=$scope;$badScope['order_ref']='order-other-001';
planDeny(fn()=> $sign(null,$badScope),'wrong order review');
$badScope=$scope;$badScope['policy_revision']=4;
planDeny(fn()=> $sign(null,$badScope),'wrong PSP policy revision');
$badReview=$review;$badReview['payload']['can_charge']=true;
planDeny(fn()=> $sign($badReview),'cannot bind charge-authorizing review');
$badReview=$review;$badReview['mac']=str_repeat('0',64);
planDeny(fn()=> $sign($badReview),'cannot bind forged F419 review');
planDeny(fn()=> $sign(null,null,null,null,null,$activationKey),
    'distinct signing secret required');

$threeEligible=['chatgpt_ads'=>true,'google_ads'=>true,'meta_ads'=>true];
$threeOriginal=Gateway::preview(['meta_ads','google_ads','chatgpt_ads'],
    $threeEligible,$psp,'pix',$org,$order,3,$activationKey);
$threeReview=ReviewIssuer::issue($threeOriginal,$threeEligible,$org,$order,3,
    $reviewIssued,$activationKey);
$all=PlanIssuer::issue(
    $threeReview,$threeOriginal,$threeEligible,$scope,
    ['chatgpt_ads'=>120000,'google_ads'=>100000,'meta_ads'=>70000],
    $reviewNow,'k_v7_plan_demo_01','nonce_portal_v7_plan_3channels',
    $now-1,$now+90,$activationKey,$agreementKey
);
planMust($all['payload']['activation_cents']===147000 &&
    $all['payload']['planned_media_total_cents']===290000 &&
    $all['payload']['estimated_management_fee_cents']===61000,
    'all 3 channels are synthetic and cost R1470 activation + R610 monthly estimate');
planMust($all['payload']['billable']===false &&
    $all['payload']['portal_iam_authenticated']===false,
    'ChatGPT enabled in test is NOT production eligibility');

putenv('PORTAL_PERF_V7_PLAN_AGREEMENT_LAB=0');
planDeny(fn()=> $sign(),'feature gate disabled');
putenv('PORTAL_PERF_V7_PLAN_AGREEMENT_LAB=1');
putenv('APP_ENV=production');
planDeny(fn()=> $sign(),'production forbidden');

echo "OK: actual Portal F419 review bound to signed V7 tenant/agreement/month/channel budgets and R170 fee floor, no payment/Ads actions\n";
