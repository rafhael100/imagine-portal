<?php
declare(strict_types=1);
/** Pure PHP F424 eligibility negative matrix; no DB, PSP, HTTP or real clients. */
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceMonthlyCloseCandidate.php';

use App\Homologation\Commerce\ApprovedOfferCatalog as Catalog;
use App\Homologation\Commerce\PerformanceSettlement as Settlement;
use App\Homologation\Commerce\PerformanceMonthlyCloseCandidate as Close;

$catalog=new Catalog();
$quote=$catalog->quote('performance',['google_ads'=>100000,'meta_ads'=>70000]);
$report=Settlement::calculate(
    $quote,'2026-11',['google_ads'=>400000,'meta_ads'=>35000],
    'SIM-F424-EXTRATO-SEM-ORIGEM-EXTERNA'
);
$allowed=static fn(array $q,array $r,string $decision='acknowledged',
    string $activation='2026-10-01',string $period='2026-11',
    string $today='2026-12-05',string $due='2026-12-08') =>
    Close::inspect($q,$r,$decision,$activation,$period,$today,$due);
$checks=0;
function f424Check(bool $condition,string $what):void{
    global $checks;
    $checks++;
    if (!$condition)throw new RuntimeException('F424: '.$what);
}
function f424Deny(callable $call,string $what):void{
    try {$call();}
    catch (InvalidArgumentException){f424Check(true,$what);return;}
    throw new RuntimeException('F424: unsafe approval for '.$what);
}
$r=$allowed($quote,$report);
f424Check($r['schema']==='imagine.portal.performance.monthly-close-review-only.v1','schema');
f424Check($r['policy_version']==='performance-postpaid-170-per-channel-2026-11-14','version');
f424Check($r['period']==='2026-11'&&$r['period_end']==='2026-11-30','period closed');
f424Check($r['proposed_due_date']==='2026-12-08','suggested due');
f424Check($r['charge_key_candidate']==='performance-2026-11','per-order candidate');
f424Check($r['channel_count']===2,'channels');
f424Check($r['informational_management_fee_cents']===97000,'Meta R170 and Google R800');
f424Check($r['minimum_per_channel_cents']===17000,'floor');
foreach(['real_invoice_authorized','sandbox_psp_write_authorized','fiscal_document_authorized','production_authorized'] as $key){
    f424Check($r[$key]===false,'NO-GO '.$key);
}
f424Check($r['sandbox_evidence_only']===true,'never real provider proof');
f424Check($r['media_paid_directly_to_platforms']===true,'media outside PSP');

// The first partial month does NOT have approved prorated floor terms.
$nov=$catalog->quote('performance',['meta_ads'=>70000]);
$novReport=Settlement::calculate($nov,'2026-11',['meta_ads'=>70000],'SIM-F424-NOV');
f424Deny(static fn()=>$allowed($nov,$novReport,activation:'2026-11-14'),'partial first month');
f424Deny(static fn()=>$allowed($quote,$report,today:'2026-11-30'),'open period');
f424Deny(static fn()=>$allowed($quote,$report,activation:'2026-12-01'),'future activation');
f424Deny(static fn()=>$allowed($quote,$report,due:'2026-11-30'),'due before period close');
f424Deny(static fn()=>$allowed($quote,$report,due:'2026-12-01'),'due in the past');
f424Deny(static fn()=>$allowed($quote,$report,today:'2026-02-30'),'invalid trusted date');
f424Deny(static fn()=>$allowed($quote,$report,period:'2026-13'),'invalid period');
f424Deny(static fn()=>$allowed($quote,$report,decision:'pending'),'no client approval');
f424Deny(static fn()=>$allowed($quote,$report,decision:'disputed'),'disputed period');
$fake=$report;$fake['evidence_ref']='provider-real-claimed';
f424Deny(static fn()=>$allowed($quote,$fake),'external evidence spoofing');
$fake=$report;$fake['actual_management_fee_cents']=500;
f424Deny(static fn()=>$allowed($quote,$fake),'total fee tampered');
$fake=$report;$fake['channels']['meta_ads']['management_fee_cents']=7000;
f424Deny(static fn()=>$allowed($quote,$fake),'per-channel fee tampered');
$fake=$report;$fake['channels']['meta_ads']['minimum_applied']=false;
f424Deny(static fn()=>$allowed($quote,$fake),'minimum flag tampered');
$fake=$report;$fake['channels']['meta_ads']['actual_cents']=5000;
f424Deny(static fn()=>$allowed($quote,$fake),'actual spend tampered');
$fake=$report;$fake['channels']['chatgpt_ads']=['actual_cents'=>10000];
f424Deny(static fn()=>$allowed($quote,$fake),'uncontracted ChatGPT ads');
$fake=$report;unset($fake['channels']['meta_ads']);
f424Deny(static fn()=>$allowed($quote,$fake),'missing contracted media');
$fake=$report;$fake['channels']['meta_ads']['actual_cents']=0;
f424Deny(static fn()=>$allowed($quote,$fake),'zero spend needs service-state decision');
$fake=$quote;$fake['activation_total_cents']=49000;
f424Deny(static fn()=>$allowed($fake,$report),'activation falsified');
$fake=$quote;$fake['pricing_policy']='legacy-prepay';
f424Deny(static fn()=>$allowed($fake,$report),'old contract cannot close new');
$fake=$quote;$fake['real_postpaid_invoice_authorized']=true;
f424Deny(static fn()=>$allowed($fake,$report),'malicious invoice authority');
$fake=$report;$fake['real_invoice_authorized']=true;
f424Deny(static fn()=>$allowed($quote,$fake),'malicious report invoice flag');
$fake=$report;$fake['status']='paid';
f424Deny(static fn()=>$allowed($quote,$fake),'untrusted status');

// A *full* first month with activation on day 1 is eligible as a
// review-only candidate, but not an invoice or payment.
$first=Settlement::calculate($nov,'2026-11',['meta_ads'=>85000],'SIM-F424-FULL');
$firstCandidate=$allowed($nov,$first,activation:'2026-11-01');
f424Check($firstCandidate['informational_management_fee_cents']===17000,'full month floor');
f424Check($firstCandidate['real_invoice_authorized']===false,'first month no invoice');
echo "PASS F424: {$checks} review-only full-month and negative scenarios, no billing, no external media proof.\n";
