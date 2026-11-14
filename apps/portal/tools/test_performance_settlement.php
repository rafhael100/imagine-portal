<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\PerformanceSettlement;
$c=new ApprovedOfferCatalog();$q=$c->quote('performance',['google_ads'=>70000]);
function check(bool $ok):void{if(!$ok)throw new RuntimeException('Settlement regression');}
foreach([0=>-14000,50000=>-4000,70000=>0,100000=>6000] as $actual=>$difference){$r=PerformanceSettlement::calculate($q,'2026-11',['google_ads'=>$actual],'SIM-RELATORIO-001');check($r['adjustment_cents']===$difference&&$r['status']==='requires_finance_review_no_auto_charge_or_credit');}
$q2=$c->quote('performance',['google_ads'=>70001,'meta_ads'=>70002]);$r=PerformanceSettlement::calculate($q2,'2026-11',['google_ads'=>70002,'meta_ads'=>70001],'SIM-ROUNDING');check($r['adjustment_cents']===0&&$r['advance_fee_cents']===28001);
foreach([['2026-13',['google_ads'=>70000],'SIM-ABC'],['2026-11',['google_ads'=>-1],'SIM-ABC'],['2026-11',['google_ads'=>true],'SIM-ABC'],['2026-11',['meta_ads'=>70000],'SIM-ABC'],['2026-11',['google_ads'=>70000],'https://real-report.example']] as [$period,$actual,$evidence]){try{PerformanceSettlement::calculate($q,$period,$actual,$evidence);throw new RuntimeException('Invalid report accepted');}catch(InvalidArgumentException){}}
try{PerformanceSettlement::calculate($q+[],'2026-11',['google_ads'=>70000,'meta_ads'=>0],'SIM-EXTRA');throw new RuntimeException('Extra channel accepted');}catch(InvalidArgumentException){}
$q['amount_now_cents']=1;try{PerformanceSettlement::calculate($q,'2026-11',['google_ads'=>70000],'SIM-FORGED');throw new RuntimeException('Forged advance accepted');}catch(InvalidArgumentException){}
echo "PASS Performance adjustments, zero actual spend, rounding, scope and immutable advance validation\n";
