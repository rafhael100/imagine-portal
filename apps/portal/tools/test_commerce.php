<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/AsaasSandboxGateway.php';
use App\Homologation\Commerce\ApprovedOfferCatalog as Catalog;
use App\Homologation\Commerce\AsaasSandboxGateway as Gateway;
$checks=0;
function check(bool $ok): void { global $checks; ++$checks; if (!$ok) throw new RuntimeException('Check '.$checks.' failed'); }
function denied(callable $fn): void { try { $fn(); } catch (Throwable) { check(true); return; } check(false); }
$c=new Catalog();
foreach (['onepage'=>[99000,33000],'landing-pages'=>[129000,43000]] as $product=>$amounts) {
    $q=$c->quote($product);
    check($q['setup_total_cents']===$amounts[0]); check($q['amount_now_cents']===$amounts[1]);
    check(array_sum(array_column($q['setup_charges'],'amount_cents'))===$amounts[0]);
    check(array_column($q['setup_charges'],'offset_days')===[0,30,60]);
    check($q['monthly']['amount_cents']===7990); check($q['monthly']['first_due_offset_days']===90);
    check($q['monthly']['mandatory']===true); check($q['real_charge_enabled']===false);
    denied(fn()=>$c->quote($product,['google_ads'=>70000]));
}
foreach ([[['google_ads'=>70000],14000],[['meta_ads'=>70000],14000],[['google_ads'=>70000,'meta_ads'=>70000],28000],[['google_ads'=>200000],40000],[['meta_ads'=>70003],14001]] as [$media,$fee]) {
    $q=$c->quote('performance',$media); check($q['estimated_management_fee_cents']===$fee);
    check($q['media_paid_directly_to_platforms']===true); check($q['amount_now_cents']===$fee);
}
foreach ([[],['google_ads'=>69999],['google_ads'=>140000,'meta_ads'=>0],['facebook'=>70000],['google_ads'=>'70000'],['google_ads'=>70000.0],['google_ads'=>PHP_INT_MAX]] as $media) denied(fn()=>$c->quote('performance',$media));
denied(fn()=>$c->quote('forged')); check(strlen($c->version())===64);
foreach (['700'=>70000,'700,01'=>70001,'700.1'=>70010,'0'=>0] as $s=>$cents) check(Catalog::money((string)$s)===$cents);
foreach (['-700','7e2','NaN','700.001','1.000,00',' 700','700foo'] as $s) denied(fn()=>Catalog::money($s));
$payment=['id'=>'pay_test1','customer'=>'cus_test1','value'=>330,'externalReference'=>'imagine:TEST:setup:1','status'=>'PENDING','billingType'=>'UNDEFINED'];
$calls=[];
$g=new Gateway('synthetic-test-only',function($method,$url,$headers,$body) use (&$calls,$payment) {
    $calls[]=[$method,$url,$body]; check(str_starts_with($url,'https://api-sandbox.asaas.com/v3/'));
    check(in_array('access_token: synthetic-test-only',$headers,true)); return ['status'=>200,'body'=>$payment];
});
check($g->createPayment('cus_test1','imagine:TEST:setup:1',33000,'2026-11-14')['status']==='PENDING');
check(count($calls)===1); check($calls[0][2]['value']===330); check(!isset($calls[0][2]['installmentCount']));
check(!Gateway::settled($g->payment('pay_test1','cus_test1','imagine:TEST:setup:1',33000)));
denied(fn()=>$g->payment('pay_test1','cus_other','imagine:TEST:setup:1',33000));
denied(fn()=>$g->payment('pay_test1','cus_test1','imagine:OTHER:setup:1',33000));
denied(fn()=>$g->payment('pay_test1','cus_test1','imagine:TEST:setup:1',1));
denied(fn()=>$g->payment('../credentials','cus_test1','imagine:TEST:setup:1',33000));
denied(fn()=>$g->createPayment('cus_test1','imagine:TEST:setup:1',33000,'2026-02-30'));
denied(fn()=>new Gateway('')); denied(fn()=>new Gateway("test\r\nHeader: forged"));
foreach (['PENDING','OVERDUE','REFUNDED','DELETED','CHARGEBACK_REQUESTED'] as $status) check(!Gateway::settled(array_replace($payment,['status'=>$status])));
check(!Gateway::settled(array_replace($payment,['status'=>'CONFIRMED','billingType'=>'PIX'])));
check(Gateway::settled(array_replace($payment,['status'=>'CONFIRMED','billingType'=>'CREDIT_CARD'])));
check(Gateway::settled(array_replace($payment,['status'=>'RECEIVED'])));
check(!Gateway::settled(array_replace($payment,['status'=>'RECEIVED','deleted'=>true])));
foreach ([302,400,401,429,500] as $status) {
    $n=0; $bad=new Gateway('fixture',function() use (&$n,$status) {++$n;return ['status'=>$status,'body'=>[]];});
    denied(fn()=>$bad->createPayment('cus_test1','imagine:TEST:setup:1',33000,'2026-11-14')); check($n===1);
}
$n=0; $bad=new Gateway('fixture',function() use (&$n) {++$n;throw new RuntimeException('private upstream detail');});
try {$bad->createPayment('cus_test1','imagine:TEST:setup:1',33000,'2026-11-14');check(false);} catch (RuntimeException $e) {check(str_contains($e->getMessage(),'uncertain'));check(!str_contains($e->getMessage(),'private'));}
check($n===1);
$q=$c->quote('performance',['google_ads'=>70000]);
check($q['reference_period']==='monthly');
check(Catalog::reconcilePerformance($q,['google_ads'=>70000])['adjustment_cents']===0);
check(Catalog::reconcilePerformance($q,['google_ads'=>100000])['adjustment_cents']===6000);
check(Catalog::reconcilePerformance($q,['google_ads'=>50000])['adjustment_cents']===-4000);
check(Catalog::reconcilePerformance($q,['google_ads'=>0])['adjustment_cents']===-14000);
check(Catalog::reconcilePerformance($q,['google_ads'=>0])['automatic_charge_enabled']===false);
denied(fn()=>Catalog::reconcilePerformance($q,['meta_ads'=>70000]));
denied(fn()=>Catalog::reconcilePerformance($q,['google_ads'=>-1]));
denied(fn()=>Catalog::reconcilePerformance($q,['google_ads'=>'70000']));
denied(fn()=>Catalog::reconcilePerformance($c->quote('onepage'),['google_ads'=>70000]));
check(Gateway::verifyWebhookToken(str_repeat('x',32),str_repeat('x',32)));
check(!Gateway::verifyWebhookToken(str_repeat('x',32),str_repeat('y',32)));
check(!Gateway::verifyWebhookToken('short','short'));
echo 'PASS '.$checks." commerce and sandbox adapter checks (injected transport; no live PSP)\n";
