<?php
declare(strict_types=1);
/** F413: no PSP, no database, no HTTP, no CRM delivery. */
require __DIR__.'/../app/Domain/Performance/ActivationPostpaidPreview.php';
require __DIR__.'/../app/Domain/Performance/ActivationGatewayReviewLab.php';
require __DIR__.'/../app/Domain/PaymentRouting/GatewayRoutingPreview.php';
require __DIR__.'/../app/Domain/PaymentRouting/GatewayPolicyReview.php';
require __DIR__.'/../app/Domain/PaymentRouting/PortalGatewayReviewExporter.php';

use App\Domain\Performance\ActivationGatewayReviewLab;

$policy=[
 'revision'=>3,'mode'=>'manual','currency'=>'BRL','preferred_gateway'=>'asaas',
 'providers'=>[
  'asaas'=>[
   'enabled'=>true,'approved'=>true,'healthy'=>true,'fee_version'=>'SIM-2026-11',
   'methods'=>['pix'=>['enabled'=>true,'fixed_cents'=>100,'variable_bps'=>100,'settlement_days'=>1]],
  ],
  'c6'=>['enabled'=>false,'approved'=>false,'healthy'=>false,'fee_version'=>'SIM-C6-2026-11','methods'=>[]],
 ],
];
$key='synthetic-f413-portal-to-crm-review-signing-key';
$quote=ActivationGatewayReviewLab::preview(['google_ads','meta_ads'],
 ['meta_ads'=>true,'google_ads'=>true],$policy,'pix','org-test-001','order-test-001',3,$key);
if ($quote['activation_cents']!==98000
    || $quote['crm_review']['payload']['amount_cents']!==98000
    || $quote['gateway_quote']['estimated_fee_cents']!==1080
    || $quote['gateway_quote']['gateway']!=='asaas'
    || $quote['can_charge']!==false
    || $quote['can_activate']!==false
    || $quote['minimum_management_fee_cents']!==null) {
    throw new RuntimeException('F413 activation routing contract mismatch');
}
try {
    ActivationGatewayReviewLab::preview(['meta_ads'], ['meta_ads'=>true],
      $policy,'pix','org-test-001','order-test-001',2,$key);
    throw new RuntimeException('F413 policy revision bypass');
} catch (LogicException) {
    // Signature must not bind mismatched commercial terms.
}
echo "PASS F413 synthetic activation->gateway->CRM review; no payment capability.\n";
