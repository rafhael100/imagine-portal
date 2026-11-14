<?php
declare(strict_types=1);
/** Standalone non-network F411 guard: never enables payment API or bank calls. */
require __DIR__.'/../app/Domain/PaymentRouting/GatewayRoutingPreview.php';
require __DIR__.'/../app/Domain/PaymentRouting/GatewayPolicyReview.php';
require __DIR__.'/../app/Domain/PaymentRouting/PortalGatewayReviewExporter.php';

use App\Domain\PaymentRouting\GatewayRoutingPreview as Routing;
use App\Domain\PaymentRouting\GatewayPolicyReview as Policy;
use App\Domain\PaymentRouting\PortalGatewayReviewExporter as Exporter;

function guard(bool $ok, string $message): void {
    if (!$ok) throw new \RuntimeException('F411: '.$message);
}
function refuses(callable $run): void {
    try {$run();} catch (\InvalidArgumentException|\LogicException $e) {return;}
    throw new \RuntimeException('F411: unsafe quote/policy unexpectedly accepted');
}
$policy = [
    'revision'=>1,'mode'=>'manual','currency'=>'BRL','preferred_gateway'=>'asaas',
    'providers'=>[
        'asaas'=>[
            'enabled'=>true,'approved'=>true,'healthy'=>true,'fee_version'=>'SIM-ASAAS-01',
            'methods'=>['pix'=>['enabled'=>true,'fixed_cents'=>100,'variable_bps'=>75,'settlement_days'=>1]],
        ],
        'c6'=>[
            'enabled'=>false,'approved'=>false,'healthy'=>false,'fee_version'=>'SIM-C6-01',
            'methods'=>[],
        ],
    ],
];
$new=$policy; $new['revision']=2;
refuses(static fn()=>Policy::review($policy,$new,1,'reviewer-01','2026-11-14T19:00:00Z'));
$new['mode']='lowest_cost';
$review=Policy::review($policy,$new,1,'reviewer-01','2026-11-14T19:00:00Z');
guard($review['activation_allowed']===false,'Policy review should remain NO-GO');
$q=Routing::quote($policy,'pix',10000);
guard($q['estimated_fee_cents']===175,'Historical HMAC amount changed');
$key='synthetic-gateway-crossproduct-review-key-2026-11';
$envelope=Exporter::export($q,'org-synthetic-001','order-synthetic-001',1,$key);
guard($envelope['mac']==='7a6738f4a62c15ab7748c7e31fc07b74cf0b2ec1bbf01ae13b48ab1d5b63fc31','CRM parity HMAC fixture mismatch');
guard($envelope['payload']['can_charge']===false && $envelope['payload']['can_activate']===false,'Unsafe contract flags');
$bad=$q;$bad['estimated_net_cents']+=10;
refuses(static fn()=>Exporter::export($bad,'org-synthetic-001','order-synthetic-001',1,$key));
$bad=$q;$bad['estimated_fee_cents']=$bad['gross_cents'];$bad['estimated_net_cents']=0;
refuses(static fn()=>Exporter::export($bad,'org-synthetic-001','order-synthetic-001',1,$key));
$bad=$q;$bad['settlement_days']='1';
refuses(static fn()=>Exporter::export($bad,'org-synthetic-001','order-synthetic-001',1,$key));
echo "PASS F411: policy no-op blocked, quote arithmetic valid, CRM HMAC parity preserved. NO-GO payments.\n";
