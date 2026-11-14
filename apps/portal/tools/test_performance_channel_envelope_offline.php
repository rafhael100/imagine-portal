<?php
declare(strict_types=1);
/** F415 fast PHP-only test. No HTTP, database, CRM writes, PSP or secrets. */
require __DIR__.'/../app/Domain/Performance/ActivationPostpaidPreview.php';
require __DIR__.'/../app/Domain/PaymentRouting/GatewayRoutingPreview.php';
require __DIR__.'/../app/Domain/PaymentRouting/GatewayPolicyReview.php';
require __DIR__.'/../app/Domain/PaymentRouting/PortalGatewayReviewExporter.php';
require __DIR__.'/../app/Domain/Performance/ActivationGatewayReviewLab.php';
require __DIR__.'/../app/Domain/Performance/PerformanceChannelReviewEnvelope.php';

use App\Domain\Performance\ActivationGatewayReviewLab as Review;
use App\Domain\Performance\PerformanceChannelReviewEnvelope as Envelope;

$key='synthetic-f415-channel-review-hmac-key-not-for-production';
$eligible=['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>false];
$org='org-001';$order='order-001';
$policy=['revision'=>3,'mode'=>'manual','currency'=>'BRL','preferred_gateway'=>'asaas',
 'providers'=>[
   'asaas'=>['enabled'=>true,'approved'=>true,'healthy'=>true,'fee_version'=>'SIM-2026-11',
     'methods'=>['pix'=>['enabled'=>true,'fixed_cents'=>100,'variable_bps'=>100,'settlement_days'=>1]]],
   'c6'=>['enabled'=>false,'approved'=>false,'healthy'=>false,'fee_version'=>'SIM-C6-2026-11','methods'=>[]],
 ]];
$review=Review::preview(['meta_ads','google_ads'],$eligible,$policy,'pix',$org,$order,3,$key);
$wire=Envelope::issue($review,$eligible,$org,$order,3,'2026-11-14T20:45:00Z',$key);
if($wire['payload']['activation_cents']!==98000
 || $wire['payload']['channels']!==['google_ads','meta_ads']
 || $wire['payload']['can_charge']!==false
 || !Envelope::verify($wire,$review,$eligible,$org,$order,3,'2026-11-14T20:50:00Z',$key)){
 throw new RuntimeException('Synthetic channel binding failed');
}
$checks=[
 [$wire,$review,$eligible,'other-org-001',$order,3,'2026-11-14T20:50:00Z'],
 [$wire,$review,$eligible,$org,$order,3,'2026-11-14T21:00:01Z'],
];
$bad=$wire;$bad['payload']['channels']=['chatgpt_ads','google_ads'];
$checks[]=[$bad,$review,$eligible,$org,$order,3,'2026-11-14T20:50:00Z'];
$bad=$wire;$bad['payload']['can_charge']=true;
$checks[]=[$bad,$review,$eligible,$org,$order,3,'2026-11-14T20:50:00Z'];
foreach($checks as [$envelope,$source,$scope,$tenant,$reference,$revision,$now]){
 if(Envelope::verify($envelope,$source,$scope,$tenant,$reference,$revision,$now,$key)){
  throw new RuntimeException('Unsafe review accepted');
 }
}
echo "PASS F415: exact channel + order + tenant + PSP review MAC binding; NO-GO money.\n";
