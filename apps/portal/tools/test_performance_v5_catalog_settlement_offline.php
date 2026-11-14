<?php
declare(strict_types=1);

/**
 * F422 integration of the approved V5 server-owned catalog with new
 * postpaid report review, preserving an IMMUTABLE synthetic legacy advance.
 * No DB connection, PSP API, customer, fiscal issuance or server session.
 */
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';

use App\Homologation\Commerce\ApprovedOfferCatalog as Catalog;
use App\Homologation\Commerce\PerformanceSettlement as Settlement;
use App\Homologation\Commerce\PerformancePostpaidPolicy as Policy;

function f422Assert(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException('F422 failed: '.$name);
}
function f422Reject(callable $call, string $name): void
{
    try {$call();} catch (InvalidArgumentException) {return;}
    throw new RuntimeException('F422 accepted invalid '.$name);
}

$catalog=new Catalog();
$quote=$catalog->quote('performance',['google_ads'=>100_000,'meta_ads'=>70_000]);
f422Assert($quote['pricing_policy']===Policy::VERSION,'approved policy version');
f422Assert($quote['amount_now_cents']===98_000,'R$980 activation');
f422Assert($quote['activation_total_cents']===98_000,'per-channel activation');
f422Assert($quote['estimated_management_fee_cents']===37_000,'planned monthly 200 + 170');
f422Assert($quote['minimum_management_fee_per_channel_cents']===17_000,'minimum policy');
f422Assert($quote['real_charge_enabled']===false,'no PSP');
f422Assert($quote['real_postpaid_invoice_authorized']===false,'no postpaid invoice');
$review=Settlement::calculate(
    $quote,'2026-11',['google_ads'=>400_000,'meta_ads'=>35_000],'SIM-F422-NOV'
);
f422Assert($review['activation_paid_cents']===98_000,'activation separate from management');
f422Assert($review['actual_management_fee_cents']===97_000,'Google 800 + Meta 170');
f422Assert($review['channels']['meta_ads']['minimum_applied']===true,'Meta minimum');
f422Assert($review['channels']['google_ads']['minimum_applied']===false,'Google percentage');
f422Assert($review['real_invoice_authorized']===false,'review cannot invoice');
f422Assert(!array_key_exists('adjustment_cents',$review),'new postpaid never computes prepaid adjustment');
$zero=Settlement::calculate(
    $quote,'2026-12',['google_ads'=>0,'meta_ads'=>0],'SIM-F422-DEC'
);
f422Assert($zero['actual_management_fee_cents']===34_000,'minimum independently per active channel');
f422Assert($zero['real_invoice_authorized']===false,'zero spend is still review-only');

$bad=$quote;$bad['activation_total_cents']=49_000;
f422Reject(static fn()=>Settlement::calculate(
    $bad,'2026-11',['google_ads'=>400_000,'meta_ads'=>35_000],'SIM-F422-NOV'
),'activation tamper');
$bad=$quote;$bad['estimated_management_fee_cents']=20_000;
f422Reject(static fn()=>Settlement::calculate(
    $bad,'2026-11',['google_ads'=>400_000,'meta_ads'=>35_000],'SIM-F422-NOV'
),'monthly forecast tamper');
$bad=$quote;$bad['pricing_policy']='deprecated-version';
f422Reject(static fn()=>Settlement::calculate(
    $bad,'2026-11',['google_ads'=>400_000,'meta_ads'=>35_000],'SIM-F422-NOV'
),'policy tamper');
f422Reject(static fn()=>$catalog->quote('performance',['google_ads'=>70_000]),'Google below R$1,000');
f422Reject(static fn()=>$catalog->quote('performance',['meta_ads'=>69_999]),'Meta below R$700');
f422Reject(static fn()=>$catalog->quote('performance',['chatgpt_ads'=>120_000]),'ChatGPT without eligibility');
f422Reject(static fn()=>Settlement::calculate(
    $quote,'2026-11',['meta_ads'=>35_000],'SIM-F422-NOV'
),'missing Google actual');

// A stored pre-V5 contract is NOT converted or recalculated using the
// new R$170 floor. It remains an advance reconciled against actual media.
$legacy=[
    'product'=>'performance','currency'=>'BRL','management_fee_basis_points'=>2000,
    'media_paid_directly_to_platforms'=>true,
    'media_by_channel_cents'=>['google_ads'=>70_000],
    'estimated_management_fee_cents'=>14_000,'amount_now_cents'=>14_000,
];
$old=Settlement::calculate($legacy,'2026-11',['google_ads'=>100_000],'SIM-F422-OLD');
f422Assert($old['advance_fee_cents']===14_000,'unchanged legacy advance');
f422Assert($old['adjustment_cents']===6_000,'unchanged historical reconciliation');
f422Assert(!array_key_exists('activation_paid_cents',$old),'legacy has no new activation');

echo "PASS F422: v5 R$490/channel activation, separate 170/channel postpaid reports, strict media minima and immutable legacy; NO PSP writes.\n";
