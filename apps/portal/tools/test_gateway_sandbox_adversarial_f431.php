<?php
declare(strict_types=1);
/**
 * F431 sandbox gateway adversarial preflight.
 *
 * Intentionally never sends network traffic: Asaas endpoint is reached only
 * through an injected fake transport. C6 sandbox remains DISABLED. This does
 * not approve real bank, customer, credential or production operations.
 */
require __DIR__.'/../app/Homologation/Commerce/AsaasSandboxGateway.php';
require __DIR__.'/../app/Domain/PaymentRouting/GatewayRoutingPreview.php';

use App\Homologation\Commerce\AsaasSandboxGateway as Asaas;
use App\Domain\PaymentRouting\GatewayRoutingPreview as Router;

const SECRET='synthetic-sandbox-do-not-use-on-real-provider';
const CUSTOMER='cus_f431';
const PAYMENT='pay_f431';
const ORDER='imagine:order-f431:activation';
const DUE='2026-12-15';
$checks=0;

function must(bool $condition,string $message): void {
    global $checks;
    $checks++;
    if (!$condition)throw new RuntimeException('F431 critical: '.$message);
}
function rejects(callable $fn,string $message): void {
    try { $fn(); }
    catch (InvalidArgumentException|RuntimeException|LogicException) {
        must(true,'reject '.$message);
        return;
    }
    throw new RuntimeException('F431 accepted unsafe: '.$message);
}
function transport(callable $fn): Asaas {return new Asaas(SECRET,Closure::fromCallable($fn));}
function payment(array $changes=[]):array {
    return array_replace([
        'id'=>PAYMENT,
        'customer'=>CUSTOMER,
        'externalReference'=>ORDER,
        'value'=>980.00,
        'status'=>'PENDING',
        'billingType'=>'PIX',
        'deleted'=>false
    ],$changes);
}

$writes=0;
$good=transport(static function(string $method,string $url,array $headers,?array $data) use (&$writes):array {
    must(str_starts_with($url,'https://api-sandbox.asaas.com/v3/'),'sandbox endpoint only');
    must(in_array('access_token: '.SECRET,$headers,true),'credential transmitted only to fake sandbox');
    if ($method==='POST') {
        $writes++;
        must(str_ends_with($url,'/payments'),'only single sandbox payment endpoint');
        must($data['customer']===CUSTOMER,'correct fake customer');
        must(is_int($data['value']) || is_float($data['value']), 'synthetic PSP amount is numeric');
        must((int)round($data['value']*100)===98000,'activation R$980 exact integer cents');
        must($data['externalReference']===ORDER,'exact charge ownership');
        must($data['billingType']==='UNDEFINED','client selects method in sandbox');
        must(!isset($data['installmentCount']),'no accidental installment instruction');
        must(!isset($data['management_fee']),'no postpaid fee in PSP');
        return ['status'=>201,'body'=>payment()];
    }
    must($method==='GET','only GET or POST allowed');
    return ['status'=>200,'body'=>payment()];
});
must($good->createPayment(CUSTOMER,ORDER,98000,DUE)['status']==='PENDING','successful sandbox payment payload');
must($writes===1,'only one provider attempt');
must($good->payment(PAYMENT,CUSTOMER,ORDER,98000)['id']===PAYMENT,'safe read/reconciliation');

// All write timeouts and 401/429/5xx are UNKNOWN OUTCOME, not a retry signal.
// A caller must persist intent and reconcile before any new write.
foreach([401,403,409,422,429,500,502,503,504,301] as $http) {
    $attempts=0;
    $gateway=transport(static function() use ($http,&$attempts):array {
        $attempts++;
        return ['status'=>$http,'body'=>['errors'=>[['code'=>'F431-SYNTHETIC']]]];
    });
    rejects(static fn()=>$gateway->createPayment(CUSTOMER,ORDER,98000,DUE),'write HTTP '.$http);
    must($attempts===1,'no internal retry on unsafe HTTP '.$http);
}
foreach(['connection reset','timeout','invalid JSON'] as $kind) {
    $attempts=0;
    $gateway=transport(static function() use (&$attempts,$kind):array {
        $attempts++;
        if ($kind==='invalid JSON')return ['status'=>201,'body'=>'truncated'];
        throw new RuntimeException('synthetic transport '.$kind);
    });
    rejects(static fn()=>$gateway->createPayment(CUSTOMER,ORDER,98000,DUE),'uncertain write '.$kind);
    must($attempts===1,'no duplicate write after '.$kind);
}
// The PSP assigns a new opaque ID at POST; strict ID matching belongs to
// GET reconciliation, where the expected persisted ID is known.
foreach([
    ['id'=>'forged-unparseable-id'],
    ['customer'=>'cus_other'],
    ['externalReference'=>'imagine:forged'],
    ['value'=>980.01],
    ['value'=>'980.00'],
    ['value'=>INF],
    ['status'=>null]
] as $mutated) {
    $gateway=transport(static fn()=>['status'=>201,'body'=>payment($mutated)]);
    rejects(static fn()=>$gateway->createPayment(CUSTOMER,ORDER,98000,DUE),'provider response altered '.array_key_first($mutated));
}
$read404=transport(static fn()=>['status'=>404,'body'=>['errors'=>[]]]);
rejects(static fn()=>$read404->payment(PAYMENT,CUSTOMER,ORDER,98000),'GET 404 not treated as paid');
$readWrongId=transport(static fn()=>['status'=>200,'body'=>payment(['id'=>'pay_wrong'])]);
rejects(static fn()=>$readWrongId->payment(PAYMENT,CUSTOMER,ORDER,98000),'GET mismatched payment id');

foreach([
    ['status'=>'PENDING','billingType'=>'PIX','settled'=>false],
    ['status'=>'CONFIRMED','billingType'=>'PIX','settled'=>false],
    ['status'=>'CONFIRMED','billingType'=>'CREDIT_CARD','settled'=>true],
    ['status'=>'RECEIVED','billingType'=>'PIX','settled'=>true],
    ['status'=>'REFUNDED','billingType'=>'PIX','settled'=>false],
    ['status'=>'CHARGEBACK_REQUESTED','billingType'=>'CREDIT_CARD','settled'=>false],
    ['status'=>'RECEIVED','billingType'=>'PIX','deleted'=>true,'settled'=>false],
] as $sample) {
    $expected=$sample['settled'];
    unset($sample['settled']);
    must(Asaas::settled(payment($sample))===$expected,'verified settlement status');
}
must(Asaas::verifyWebhookToken(str_repeat('a',32),str_repeat('a',32)),'webhook token exact match');
must(!Asaas::verifyWebhookToken(str_repeat('a',32),str_repeat('b',32)),'webhook invalid token');
must(!Asaas::verifyWebhookToken('short','short'),'weak webhook token fails closed');

foreach([
    ['','imagine:good:activation',98000,DUE],
    [CUSTOMER,'forged',98000,DUE],
    [CUSTOMER,ORDER,0,DUE],
    [CUSTOMER,ORDER,-1,DUE],
    [CUSTOMER,ORDER,98000,'2026-02-30'],
] as [$customer,$ref,$cents,$due]) {
    $attempts=0;
    $g=transport(static function()use(&$attempts):array{
        $attempts++;
        return ['status'=>201,'body'=>payment()];
    });
    rejects(static fn()=>$g->createPayment($customer,$ref,$cents,$due),'bad client terms');
    must($attempts===0,'invalid terms never reach fake transport');
}

// C6 is OFF by default even if someone supplies a lower synthetic fee;
// existing invoices always remain assigned to their original provider.
$config=[
    'mode'=>'lowest_cost','currency'=>'BRL','preferred_gateway'=>'asaas',
    'providers'=>[
        'asaas'=>[
            'enabled'=>true,'approved'=>true,'healthy'=>true,
            'fee_version'=>'F431-TEST-ASAAS',
            'methods'=>['pix'=>['enabled'=>true,'fixed_cents'=>100,'variable_bps'=>75,'settlement_days'=>1]]
        ],
        'c6'=>[
            'enabled'=>false,'approved'=>false,'healthy'=>false,
            'fee_version'=>'F431-TEST-C6',
            'methods'=>['pix'=>['enabled'=>true,'fixed_cents'=>0,'variable_bps'=>0,'settlement_days'=>0]]
        ]
    ]
];
must(Router::quote($config,'pix',98000)['gateway']==='asaas','C6 never auto-enables');
$bad=$config;$bad['providers']['asaas']['healthy']=false;
rejects(static fn()=>Router::quote($bad,'pix',98000),'no silent C6 fallback');
rejects(static fn()=>Router::enforcePinnedGateway('asaas','c6'),'historical PSP never migrates');
must(Router::enforcePinnedGateway('asaas','asaas')==='asaas','historical Asaas remains pinned');

echo "PASS F431: {$checks} checks; fake Asaas HTTP failure/mismatch/replay conditions, routing pinned, C6 OFF, NO REAL PSP.\n";
