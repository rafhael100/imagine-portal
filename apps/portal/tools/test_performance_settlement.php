<?php
declare(strict_types=1);
function legacy(array $media):array { $fee=intdiv(array_sum($media)*2000+5000,10000);return ['product'=>'performance','currency'=>'BRL','media_by_channel_cents'=>$media,'media_paid_directly_to_platforms'=>true,'management_fee_basis_points'=>2000,'estimated_management_fee_cents'=>$fee,'amount_now_cents'=>$fee,'reference_period'=>'monthly']; }

require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\PerformanceSettlement;
$c=new ApprovedOfferCatalog();$q=legacy(['google_ads'=>70000]);
function check(bool $ok):void{if(!$ok)throw new RuntimeException('Settlement regression');}
foreach([0=>-14000,50000=>-4000,70000=>0,100000=>6000] as $actual=>$difference){$r=PerformanceSettlement::calculate($q,'2026-11',['google_ads'=>$actual],'SIM-RELATORIO-001');check($r['adjustment_cents']===$difference&&$r['status']==='requires_finance_review_no_auto_charge_or_credit');}
$q2=legacy(['google_ads'=>70001,'meta_ads'=>70002]);$r=PerformanceSettlement::calculate($q2,'2026-11',['google_ads'=>70002,'meta_ads'=>70001],'SIM-ROUNDING');check($r['adjustment_cents']===0&&$r['advance_fee_cents']===28001);
foreach([['2026-13',['google_ads'=>70000],'SIM-ABC'],['2026-11',['google_ads'=>-1],'SIM-ABC'],['2026-11',['google_ads'=>true],'SIM-ABC'],['2026-11',['meta_ads'=>70000],'SIM-ABC'],['2026-11',['google_ads'=>70000],'https://real-report.example']] as [$period,$actual,$evidence]){try{PerformanceSettlement::calculate($q,$period,$actual,$evidence);throw new RuntimeException('Invalid report accepted');}catch(InvalidArgumentException){}}
try{PerformanceSettlement::calculate($q+[],'2026-11',['google_ads'=>70000,'meta_ads'=>0],'SIM-EXTRA');throw new RuntimeException('Extra channel accepted');}catch(InvalidArgumentException){}
$q['amount_now_cents']=1;try{PerformanceSettlement::calculate($q,'2026-11',['google_ads'=>70000],'SIM-FORGED');throw new RuntimeException('Forged advance accepted');}catch(InvalidArgumentException){}
echo "PASS Performance adjustments, zero actual spend, rounding, scope and immutable advance validation\n";

$new=$c->quote('performance',['google_ads'=>70000,'meta_ads'=>70000]);
$r=PerformanceSettlement::calculate($new,'2026-11',['google_ads'=>400000,'meta_ads'=>35000],'SIM-POSTPAID');
check($r['actual_management_fee_cents']===97000&&$r['activation_paid_cents']===98000&&!isset($r['adjustment_cents']));
check($r['channels']['meta_ads']['management_fee_cents']===17000);
try{ApprovedOfferCatalog::reconcilePerformance($new,['google_ads'=>0,'meta_ads'=>0]);throw new RuntimeException('Postpaid mistaken for advance');}catch(InvalidArgumentException){}
echo "PASS new postpaid calculation is independent of legacy advance settlement\n";
