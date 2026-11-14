<?php
declare(strict_types=1);
/**
 * Standalone, network-free F406 cross-branch smoke test.
 * Run: php apps/portal/tools/test_multigateway_offline.php
 * This never executes charges, HTTP requests or persistence.
 */
require __DIR__.'/../app/Domain/PaymentRouting/GatewayRoutingPreview.php';

use App\Domain\PaymentRouting\GatewayRoutingPreview as Router;
use RuntimeException as RoutingRuntimeException;

function verify(bool $ok, string $reason): void {
    if (!$ok) { throw new \RuntimeException('FAIL: '.$reason); }
}
function rejects(callable $fn, string $reason): void {
    try { $fn(); } catch (\InvalidArgumentException|\LogicException|RoutingRuntimeException $e) { return; }
    throw new \RuntimeException('FAIL: '.$reason);
}

$config = [
    'mode'=>'manual', 'currency'=>'BRL', 'preferred_gateway'=>'asaas',
    'providers'=>[
        'asaas'=>[
            'enabled'=>true,'approved'=>true,'healthy'=>true,
            'fee_version'=>'TEST-as-2026-11',
            'methods'=>[
                'pix'=>['enabled'=>true,'fixed_cents'=>100,'variable_bps'=>100,'settlement_days'=>1],
                'boleto'=>['enabled'=>true,'fixed_cents'=>240,'variable_bps'=>0,'settlement_days'=>2],
            ],
        ],
        'c6'=>[
            'enabled'=>false,'approved'=>false,'healthy'=>false,
            'fee_version'=>'TEST-c6-2026-11',
            'methods'=>[
                'pix'=>['enabled'=>true,'fixed_cents'=>30,'variable_bps'=>60,'settlement_days'=>1],
            ],
        ],
    ],
];
$q=Router::quote($config,'pix',10000);
verify($q['gateway']==='asaas' && $q['estimated_fee_cents']===200 && $q['estimated_net_cents']===9800,'Asaas approved default');
verify($q['fee_version']==='TEST-as-2026-11' && $q['currency']==='BRL','versioned BRL snapshot');
$cheaper=$config; $cheaper['mode']='lowest_cost';
verify(Router::quote($cheaper,'pix',10000)['gateway']==='asaas','unapproved C6 excluded');
$cheaper['providers']['c6']['enabled']=true;
$cheaper['providers']['c6']['approved']=true;
verify(Router::quote($cheaper,'pix',10000)['gateway']==='asaas','unhealthy C6 excluded');
$cheaper['providers']['c6']['healthy']=true;
verify(Router::quote($cheaper,'pix',10000)['gateway']==='c6','eligible cheaper C6 selected');
verify(Router::quote($cheaper,'boleto',10000)['gateway']==='asaas','per-method routing independent');
$manual=$cheaper;$manual['mode']='manual';$manual['preferred_gateway']='c6';
verify(Router::quote($manual,'pix',10000)['gateway']==='c6','explicit manual selection');
$manual['providers']['c6']['healthy']=false;
rejects(static fn()=>Router::quote($manual,'pix',10000),'manual no silent fallback');
$loss=$config;$loss['providers']['asaas']['methods']['pix']['fixed_cents']=100;
rejects(static fn()=>Router::quote($loss,'pix',50),'negative net excluded');
rejects(static fn()=>Router::quote($config,'credit_card',10000),'unconfigured methods excluded');
rejects(static fn()=>Router::quote($config,'pix',0),'zero amount excluded');
rejects(static fn()=>Router::enforcePinnedGateway('asaas','c6'),'existing PSP immutable');
verify(Router::enforcePinnedGateway('asaas','asaas')==='asaas','same PSP preserved');
echo "PASS F406 multi-PSP offline safety; NO-GO real billing or C6 API.\n";
