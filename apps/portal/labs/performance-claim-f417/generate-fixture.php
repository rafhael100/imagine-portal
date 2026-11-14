<?php
declare(strict_types=1);

/**
 * F417: bridges the *actual* F413+F415 signed synthetic Performance review
 * to a one-use PostgreSQL18 claim from F416. Emits only SQL test fixtures.
 * No private key, real tenant, HTTP, bank calls, CRM delivery or production DB.
 */
require __DIR__.'/../../app/Domain/Performance/ActivationPostpaidPreview.php';
require __DIR__.'/../../app/Domain/PaymentRouting/GatewayRoutingPreview.php';
require __DIR__.'/../../app/Domain/PaymentRouting/GatewayPolicyReview.php';
require __DIR__.'/../../app/Domain/PaymentRouting/PortalGatewayReviewExporter.php';
require __DIR__.'/../../app/Domain/Performance/ActivationGatewayReviewLab.php';
require __DIR__.'/../../app/Domain/Performance/PerformanceChannelReviewEnvelope.php';

use App\Domain\Performance\ActivationGatewayReviewLab as Review;
use App\Domain\Performance\PerformanceChannelReviewEnvelope as Envelope;

$key='synthetic-f417-git-actions-key-offline-only-never-real';
$org='org-f417-alpha';
$order='order-f417-alpha';
$revision=11;
$eligible=['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>false];
$policy=[
    'revision'=>$revision,
    'mode'=>'manual','currency'=>'BRL','preferred_gateway'=>'asaas',
    'providers'=>[
        'asaas'=>[
            'enabled'=>true,'approved'=>true,'healthy'=>true,
            'fee_version'=>'TEST-F417-AS-01',
            'methods'=>['pix'=>[
                'enabled'=>true,'fixed_cents'=>150,
                'variable_bps'=>75,'settlement_days'=>1,
            ]],
        ],
        'c6'=>[
            'enabled'=>false,'approved'=>false,'healthy'=>false,
            'fee_version'=>'TEST-F417-C6-01','methods'=>[],
        ],
    ],
];
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$issued=$now->modify('-1 minute')->format('Y-m-d\TH:i:s\Z');
$nowText=$now->format('Y-m-d\TH:i:s\Z');
$review=Review::preview(['meta_ads','google_ads'],$eligible,$policy,'pix',
    $org,$order,$revision,$key);
$wire=Envelope::issue($review,$eligible,$org,$order,$revision,$issued,$key);
if(!Envelope::verify($wire,$review,$eligible,$org,$order,$revision,$nowText,$key)
    || $wire['payload']['activation_cents']!==98000
    || $wire['payload']['channels']!==['google_ads','meta_ads']
    || $wire['payload']['crm_review_mac']!==$review['crm_review']['mac']
    || $wire['payload']['can_charge']!==false) {
    throw new RuntimeException('Invalid synthetic F415->F416 channel proof');
}
$forged=$wire;
$forged['payload']['channels']=['chatgpt_ads','google_ads'];
if(Envelope::verify($forged,$review,$eligible,$org,$order,$revision,$nowText,$key)) {
    throw new RuntimeException('Tampered channels were accepted');
}
$hash=hash('sha256',json_encode($wire,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
$mac=$wire['mac'];
$channelHash=hash('sha256',implode(',', $wire['payload']['channels']));
$amount=$wire['payload']['activation_cents'];
$expires=$wire['payload']['expires_at'];
function sqlLiteral(string $input): string {
    if(!preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D',$input)){
        throw new InvalidArgumentException('Unsafe synthetic SQL fixture');
    }
    return "'".$input."'";
}
foreach([$hash,$mac,$channelHash] as $hex) {
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$hex)) {
        throw new RuntimeException('Unexpected digest shape');
    }
}
if($amount!==98000){throw new RuntimeException('Unexpected activation fee');}
$h=sqlLiteral($hash);$m=sqlLiteral($mac);$t=sqlLiteral($org);
$o=sqlLiteral($order);$c=sqlLiteral($channelHash);
$i=sqlLiteral($issued);$e=sqlLiteral($expires);

echo "\\set ON_ERROR_STOP on\n";
echo "-- F417 generated synthetic signed F415 envelope, no secrets or user PII.\n";
echo "SET SESSION AUTHORIZATION perf_claim_f416_issuer;\n";
echo "INSERT INTO perf_claim_f416.activation_claims (envelope_hash,envelope_mac,tenant_ref,order_ref,policy_revision,channel_set_hash,activation_cents,issued_at,expires_at) VALUES ($h,$m,$t,$o,$revision,$c,$amount,$i::timestamptz,$e::timestamptz);\n";
echo "RESET SESSION AUTHORIZATION;\n";
echo "SET SESSION AUTHORIZATION perf_claim_f416_worker;\n";
echo "DO \$proof\$\nDECLARE n bigint;\nBEGIN\n";
echo " UPDATE perf_claim_f416.activation_claims SET state='consumed',consumed_at=now() WHERE envelope_hash=$h AND envelope_mac=repeat('0',64) AND state='issued' AND expires_at>now();\n";
echo " GET DIAGNOSTICS n=ROW_COUNT; IF n<>0 THEN RAISE EXCEPTION 'F417 wrong HMAC accepted'; END IF;\n";
echo " UPDATE perf_claim_f416.activation_claims SET state='consumed',consumed_at=now() WHERE envelope_hash=$h AND envelope_mac=$m AND tenant_ref=$t AND order_ref=$o AND policy_revision=$revision AND channel_set_hash=$c AND activation_cents=$amount AND state='issued' AND expires_at>now();\n";
echo " GET DIAGNOSTICS n=ROW_COUNT; IF n<>1 THEN RAISE EXCEPTION 'F417 signed claim not consumed once'; END IF;\n";
echo " UPDATE perf_claim_f416.activation_claims SET state='consumed',consumed_at=now() WHERE envelope_hash=$h AND envelope_mac=$m AND state='issued' AND expires_at>now();\n";
echo " GET DIAGNOSTICS n=ROW_COUNT; IF n<>0 THEN RAISE EXCEPTION 'F417 replay was accepted'; END IF;\n";
echo "END \$proof\$;\n";
echo "RESET SESSION AUTHORIZATION;\n";
echo "\\echo 'PASS F417: PHP signed channel envelope consumed once in PostgreSQL18, no PSP.'\n";
