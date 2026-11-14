<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PreviewBillingCalendar.php';
require __DIR__.'/../app/Homologation/Commerce/CustomerPreviewHandoff.php';
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
$payment=['id'=>'pay_test01','customer'=>'cus_test1','value'=>330,'externalReference'=>'imagine:TEST:setup:1','status'=>'PENDING','billingType'=>'UNDEFINED'];
$calls=[];
$g=new Gateway('synthetic-test-only',function($method,$url,$headers,$body) use (&$calls,$payment) {
    $calls[]=[$method,$url,$body]; check(str_starts_with($url,'https://api-sandbox.asaas.com/v3/'));
    check(in_array('access_token: synthetic-test-only',$headers,true)); return ['status'=>200,'body'=>$payment];
});
check($g->createPayment('cus_test1','imagine:TEST:setup:1',33000,'2026-11-14')['status']==='PENDING');
check(count($calls)===1); check($calls[0][2]['value']===330); check(!isset($calls[0][2]['installmentCount']));
check(!Gateway::settled($g->payment('pay_test01','cus_test1','imagine:TEST:setup:1',33000)));
denied(fn()=>$g->payment('pay_test01','cus_other','imagine:TEST:setup:1',33000));
denied(fn()=>$g->payment('pay_test01','cus_test1','imagine:OTHER:setup:1',33000));
denied(fn()=>$g->payment('pay_test01','cus_test1','imagine:TEST:setup:1',1));
denied(fn()=>$g->payment('../credentials','cus_test1','imagine:TEST:setup:1',33000));

// Asaas official changelog (05/11/2026): new IDs can include a numeric
// segment separated by underscore. Treat the whole payment ID as opaque.
$newId='pay_1_a1B2c3D4e5F6g7';
$newRecord=array_replace($payment,['id'=>$newId]);
$newReader=new Gateway('synthetic-test-only',
    static fn($method,$url,$headers,$body)=>['status'=>200,'body'=>$newRecord]
);
check($newReader->payment($newId,'cus_test1','imagine:TEST:setup:1',33000)['id']===$newId);
foreach (['pay_1_a1B2c3D4e5F6g7/../customers',
          'pay_1_a1B2c3D4e5F6g7%2Fadmin',
          'pay_1_a1B2c3D4e5F6g7?test=1'] as $badId) {
    denied(fn()=>$newReader->payment($badId,'cus_test1','imagine:TEST:setup:1',33000));
}

denied(fn()=>$g->createPayment('cus_test1','imagine:TEST:setup:1',33000,'2026-02-30'));
denied(fn()=>new Gateway('')); denied(fn()=>new Gateway("test\r\nHeader: forged"));
foreach (['PENDING','OVERDUE','REFUNDED','DELETED','CHARGEBACK_REQUESTED'] as $status) check(!Gateway::settled(array_replace($payment,['status'=>$status])));
check(!Gateway::settled(array_replace($payment,['status'=>'CONFIRMED','billingType'=>'PIX'])));
check(!Gateway::settled(array_replace($payment,['status'=>'CONFIRMED','billingType'=>'CREDIT_CARD'])));
check(Gateway::settled(array_replace($payment,['status'=>'RECEIVED'])));
check(!Gateway::settled(array_replace($payment,['status'=>'RECEIVED','deleted'=>true])));
foreach ([
    ['status'=>'RECEIVED','refundedDate'=>'2026-11-14'],
    ['status'=>'RECEIVED','refunds'=>[['id'=>'refund-synthetic']]],
    ['status'=>'RECEIVED','chargeback'=>['status'=>'OPEN']],
    ['status'=>'RECEIVED','subscription'=>'sub_fake'],
    ['status'=>'RECEIVED','currency'=>'USD'],
] as $unsafe) {
    check(!Gateway::settled(array_replace($payment,$unsafe)));
}
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
foreach (['onepage','landing-pages'] as $product) {
    check(\App\Homologation\Commerce\CustomerPreviewHandoff::shopUrl($product)==='/_preview/portal/shop?product='.$product);
    denied(fn()=>\App\Homologation\Commerce\CustomerPreviewHandoff::shopUrl($product,'google_ads'));
}
foreach (['google_ads','meta_ads'] as $channel) {
    check(\App\Homologation\Commerce\CustomerPreviewHandoff::channel('performance',$channel)===$channel);
    check(\App\Homologation\Commerce\CustomerPreviewHandoff::shopUrl('performance',$channel)==='/_preview/portal/shop?product=performance&channel='.$channel);
}
check(\App\Homologation\Commerce\CustomerPreviewHandoff::shopUrl('performance')==='/_preview/portal/shop?product=performance');
foreach (['google','meta','facebook','../../admin','',1,[],['google_ads']] as $bad) denied(fn()=>\App\Homologation\Commerce\CustomerPreviewHandoff::channel('performance',$bad));
check(\App\Homologation\Commerce\CustomerPreviewHandoff::shopUrl('unknown')===null);
// F1-400: a frozen offer generates INFORMATION only, never a PSP event.
$cal=\App\Homologation\Commerce\PreviewBillingCalendar::class;
foreach (['onepage'=>33000,'landing-pages'=>43000] as $product=>$setup) {
    $entries=$cal::forOrder($c->quote($product),'2026-11-14T14:00:00-03:00',$product);
    check(count($entries)===6);
    check(array_column($entries,'due_iso')===[
      '2026-11-14','2026-12-14','2027-01-13',
      '2027-02-12','2027-03-12','2027-04-12'
    ]);
    check(array_column($entries,'kind')===['setup','setup','setup','monthly','monthly','monthly']);
    check(array_slice(array_column($entries,'amount_cents'),0,3)===[$setup,$setup,$setup]);
    check(array_slice(array_column($entries,'amount_cents'),3)===[7990,7990,7990]);
    foreach ($entries as $event) {
        check($event['automated_charge_enabled']===false);
        check($event['reference_only']===true);
    }
}
// Clamp the reference calendar date at month-end, including February.
$jan31=$cal::forOrder($c->quote('onepage'),'2026-11-02T12:00:00-03:00','onepage');
check(array_slice(array_column($jan31,'due_iso'),3)===[
    '2027-01-31','2027-02-28','2027-03-31'
]);
$leap=$cal::forOrder($c->quote('onepage'),'2023-11-02T12:00:00-03:00','onepage');
check(array_slice(array_column($leap,'due_iso'),3)===[
    '2024-01-31','2024-02-29','2024-03-31'
]);
// Date anchor is São Paulo local, not silently a UTC calendar date.
$midnight=$cal::forOrder($c->quote('onepage'),'2026-12-01T00:30:00+00:00','onepage');
check($midnight[0]['due_iso']==='2026-11-30');
// Performance must NOT inherit Sites monthly installments or invent spend.
$perf=$cal::forOrder($c->quote('performance',['google_ads'=>70000,'meta_ads'=>70000]),
    '2026-11-14T10:00:00-03:00','performance');
check(count($perf)===1);
check($perf[0]['amount_cents']===28000);
check($perf[0]['kind']==='performance_estimate');
check($perf[0]['due_iso']===null && $perf[0]['due_label']===null);
check($perf[0]['automated_charge_enabled']===false);
check($cal::forOrder(null,null,'onepage')===[]);
$bad=$c->quote('onepage');
$bad['setup_total_cents']=1;
denied(fn()=>$cal::forOrder($bad,'2026-11-14T10:00:00-03:00','onepage'));
$bad=$c->quote('landing-pages');
$bad['monthly']['first_due_offset_days']=60;
denied(fn()=>$cal::forOrder($bad,'2026-11-14T10:00:00-03:00','landing-pages'));
$bad=$c->quote('onepage');
$bad['real_charge_enabled']=true;
denied(fn()=>$cal::forOrder($bad,'2026-11-14T10:00:00-03:00','onepage'));
$bad=$c->quote('onepage');
$bad['setup_charges'][1]['amount_cents']=1;
denied(fn()=>$cal::forOrder($bad,'2026-11-14T10:00:00-03:00','onepage'));
denied(fn()=>$cal::forOrder($c->quote('onepage'),'not-a-date','onepage'));
denied(fn()=>$cal::forOrder($c->quote('onepage'),'2026-11-14T10:00:00-03:00','performance'));
$bad=$c->quote('performance',['google_ads'=>70000]);
$bad['media_by_channel_cents']=['fake_channel'=>70000];
denied(fn()=>$cal::forOrder($bad,'2026-11-14T10:00:00-03:00','performance'));

echo 'PASS '.$checks." commerce, channel handoff and sandbox tests (injected transport; no live PSP)\n";
