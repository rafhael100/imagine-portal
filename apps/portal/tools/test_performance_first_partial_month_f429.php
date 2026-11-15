<?php
declare(strict_types=1);
/** F429: deterministic provisional contract simulation; no invoices or PSP. */
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceMediaEvidenceReconciliation.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceFirstPartialMonthProposal.php';

use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\PerformanceSettlement;
use App\Homologation\Commerce\PerformanceMediaEvidenceReconciliation as Evidence;
use App\Homologation\Commerce\PerformanceFirstPartialMonthProposal as Partial;

$offer=(new ApprovedOfferCatalog())->quote(
    'performance',['google_ads'=>100_000,'meta_ads'=>70_000]
);
$report=PerformanceSettlement::calculate(
    $offer,'2026-11',['google_ads'=>40_000,'meta_ads'=>100_000],
    'SIM-F429-PARTIAL-MONTH'
);
function statement(string $channel,string $account,int $cents):array {
    return [
        'account_ref'=>$account,'channel'=>$channel,'complete'=>true,
        'currency'=>'BRL','generated_at_utc'=>'2026-12-02T06:00:00Z',
        'order_ref'=>'order-f429-synthetic','origin'=>'offline_synthetic_fixture',
        'period'=>'2026-11','rows'=>[
            ['event_ref'=>$channel.'-row-001','day'=>'2026-11-26','spend_cents'=>$cents],
        ],
        'tenant_ref'=>'org-f429-synthetic','timezone'=>'America/Sao_Paulo',
    ];
}
$media=Evidence::inspect(
    $offer,$report,'org-f429-synthetic','order-f429-synthetic',
    ['google_ads'=>'google-f429','meta_ads'=>'meta-f429'],
    ['google_ads'=>statement('google_ads','google-f429',40_000),
     'meta_ads'=>statement('meta_ads','meta-f429',100_000)],
    '2026-11','2026-12-05T12:00:00Z'
);
$activations=['google_ads'=>'2026-11-16','meta_ads'=>'2026-11-21'];
$state=['google_ads'=>'clear','meta_ads'=>'clear'];
$checks=0;
function check(bool $test,string $reason):void {
    global $checks;
    $checks++;
    if (!$test)throw new RuntimeException('F429 unexpected: '.$reason);
}
function proposal(array $activation=[],array $states=[],array $evidence=[],
    string $period='2026-11',string $today='2026-12-05'):array {
    global $offer,$media,$activations,$state;
    return Partial::inspect($offer,$evidence?:$media,$activation?:$activations,
        $states?:$state,$period,$today);
}
function deny(callable $fn,string $reason):void {
    try {$fn();}catch(InvalidArgumentException){check(true,'blocked '.$reason);return;}
    throw new RuntimeException('F429 unexpectedly approved '.$reason);
}
$draft=proposal();
check($draft['schema']==='imagine.portal.performance.partial-month-contract-proposal.v1','schema');
check($draft['status']==='contract_proposal_only','proposal');
check($draft['informational_management_total_cents']===28_500,'R$285 total');
check($draft['channels']['google_ads']['active_calendar_days']===15,'Google 15 days');
check($draft['channels']['google_ads']['proportional_minimum_cents']===8_500,'Google R$85 floor');
check($draft['channels']['google_ads']['twenty_percent_media_cents']===8_000,'Google 20% R$80');
check($draft['channels']['google_ads']['proposed_management_cents']===8_500,'Google floor wins');
check($draft['channels']['meta_ads']['active_calendar_days']===10,'Meta 10 days');
check($draft['channels']['meta_ads']['proportional_minimum_cents']===5_667,'Meta floor rounded half-up');
check($draft['channels']['meta_ads']['twenty_percent_media_cents']===20_000,'Meta 20% R$200');
check($draft['channels']['meta_ads']['proposed_management_cents']===20_000,'Meta percent wins');
check($draft['rounding']==='half_up_integer_cents_per_channel','deterministic rounding');
foreach(['contract_policy_ratified','official_media_evidence_verified',
         'suspension_policy_ratified','real_invoice_authorized',
         'can_charge','can_write_psp','can_activate_ads'] as $flag) {
    check($draft[$flag]===false,'NO-GO '.$flag);
}
deny(static fn()=>proposal(states:['google_ads'=>'suspended','meta_ads'=>'clear']),'suspended channel');
deny(static fn()=>proposal(states:['google_ads'=>'unknown','meta_ads'=>'clear']),'unknown suspension');
deny(static fn()=>proposal(activation:['google_ads'=>'2026-11-01','meta_ads'=>'2026-11-01']),'not partial');
deny(static fn()=>proposal(activation:['google_ads'=>'2026-10-31','meta_ads'=>'2026-11-21']),'out of month');
deny(static fn()=>proposal(activation:['google_ads'=>'2026-11-31','meta_ads'=>'2026-11-21']),'invalid date');
deny(static fn()=>proposal(activation:['google_ads'=>'2026-11-16','meta_ads'=>'2026-12-01']),'future activation');
deny(static fn()=>proposal(today:'2026-11-30'),'unclosed month');
deny(static fn()=>proposal(states:['google_ads'=>'clear']),'missing suspension state');
deny(static fn()=>proposal(activation:['google_ads'=>'2026-11-16']),'missing channel activation');
$unsafe=$media;$unsafe['channels']['google_ads']['matched']=false;
deny(static fn()=>proposal(evidence:$unsafe),'unmatched media');
$unsafe=$media;$unsafe['amounts_match_offline']=false;
deny(static fn()=>proposal(evidence:$unsafe),'media statement/report mismatch');
$unsafe=$media;$unsafe['official_media_evidence_verified']=true;
deny(static fn()=>proposal(evidence:$unsafe),'cannot claim real evidence');
$unsafe=$media;$unsafe['can_charge']=true;
deny(static fn()=>proposal(evidence:$unsafe),'cannot borrow PSP authorization');
$unsafe=$media;$unsafe['channels']['google_ads']['statement_spend_cents']=0;
deny(static fn()=>proposal(evidence:$unsafe),'zero media requires human review');
$unsafe=$media;$unsafe['channels']['google_ads']['statement_spend_cents']=100;
deny(static fn()=>proposal(evidence:$unsafe),'tampered media amount');
// The leap-year calendar can be reproduced with a deliberately changed
// synthetic evidence period. This verifies the math, not source authenticity.
$leap=$media;$leap['period']='2028-02';
$leapPlan=proposal(
    activation:['google_ads'=>'2028-02-29','meta_ads'=>'2028-02-01'],
    evidence:$leap,period:'2028-02',today:'2028-03-05'
);
check($leapPlan['channels']['google_ads']['days_in_month']===29,'leap February');
check($leapPlan['channels']['google_ads']['active_calendar_days']===1,'one active day leap');
check($leapPlan['channels']['google_ads']['proportional_minimum_cents']===586,'29-day floor round');
check($leapPlan['channels']['meta_ads']['proportional_minimum_cents']===17_000,'full-period per-channel floor');
check($leapPlan['can_charge']===false,'leap still unbillable');
echo "PASS F429: {$checks} calendar/percent/floor/rounding/suspension tests; proposal only, no invoice or PSP.\n";
