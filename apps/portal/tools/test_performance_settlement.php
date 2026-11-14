<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\PerformanceSettlement;
// Historical snapshots are frozen: never regenerate them from the new V5
// catalog, which now correctly charges R$490 per channel for activation.
function legacyPerformance(array $media):array {
    $fee=intdiv(array_sum($media)*2000+5000,10000);
    return ['product'=>'performance','currency'=>'BRL',
        'media_by_channel_cents'=>$media,'media_paid_directly_to_platforms'=>true,
        'management_fee_basis_points'=>2000,'estimated_management_fee_cents'=>$fee,
        'amount_now_cents'=>$fee,'reference_period'=>'monthly'];
}
$c=new ApprovedOfferCatalog();$q=legacyPerformance(['google_ads'=>70000]);
function check(bool $ok):void{if(!$ok)throw new RuntimeException('Settlement regression');}
foreach([0=>-14000,50000=>-4000,70000=>0,100000=>6000] as $actual=>$difference){$r=PerformanceSettlement::calculate($q,'2026-11',['google_ads'=>$actual],'SIM-RELATORIO-001');check($r['adjustment_cents']===$difference&&$r['status']==='requires_finance_review_no_auto_charge_or_credit');}
$q2=legacyPerformance(['google_ads'=>70001,'meta_ads'=>70002]);$r=PerformanceSettlement::calculate($q2,'2026-11',['google_ads'=>70002,'meta_ads'=>70001],'SIM-ROUNDING');check($r['adjustment_cents']===0&&$r['advance_fee_cents']===28001);
foreach([['2026-13',['google_ads'=>70000],'SIM-ABC'],['2026-11',['google_ads'=>-1],'SIM-ABC'],['2026-11',['google_ads'=>true],'SIM-ABC'],['2026-11',['meta_ads'=>70000],'SIM-ABC'],['2026-11',['google_ads'=>70000],'https://real-report.example']] as [$period,$actual,$evidence]){try{PerformanceSettlement::calculate($q,$period,$actual,$evidence);throw new RuntimeException('Invalid report accepted');}catch(InvalidArgumentException){}}
try{PerformanceSettlement::calculate($q+[],'2026-11',['google_ads'=>70000,'meta_ads'=>0],'SIM-EXTRA');throw new RuntimeException('Extra channel accepted');}catch(InvalidArgumentException){}
$q['amount_now_cents']=1;try{PerformanceSettlement::calculate($q,'2026-11',['google_ads'=>70000],'SIM-FORGED');throw new RuntimeException('Forged advance accepted');}catch(InvalidArgumentException){}

// V5 new business terms use only activation now, with postpaid management;
// no adjustment is generated in the new policy.
$v5=$c->quote('performance',['google_ads'=>100000,'meta_ads'=>70000]);
check($v5['activation_total_cents']===98000 && $v5['amount_now_cents']===98000);
check($v5['estimated_management_fee_cents']===37000);
$r=PerformanceSettlement::calculate(
    $v5,'2026-11',['google_ads'=>400000,'meta_ads'=>35000],'SIM-V5-REVIEW'
);
check($r['actual_management_fee_cents']===97000);
check($r['activation_paid_cents']===98000);
check(!array_key_exists('adjustment_cents',$r));
check($r['real_invoice_authorized']===false);
try {
    $c->quote('performance',['google_ads'=>70000]);
    throw new RuntimeException('Google Ads below approved minimum accepted');
} catch (InvalidArgumentException) {}

echo "PASS V5 postpaid reports and immutable legacy adjustments; price, scope and NO-GO verified\n";
