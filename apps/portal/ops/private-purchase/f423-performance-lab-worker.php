<?php
declare(strict_types=1);
// Separate PHP process/container: no Portal DB/password/session and no Ads API.
// Uses REAL Performance #458 readiness fixture loaded from exact pinned checkout.
if(getenv('GITHUB_ACTIONS')!=='true' || getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1'
   || ($argc!==3)) exit(1);
[$script,$company,$orderId]=$argv;
if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)
   ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$orderId)) exit(1);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)) exit(1);
require '/lab/SyntheticTwoChannelOrderReadiness.php';

function signedRequest(string $method,string $path,string $company,string $key,?array $payload=null,?string $corrupt=null):array {
 $body=$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR);
 $ts=(string)time();$nonce=bin2hex(random_bytes(16));
 $sig=hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$company."\n".$ts."\n".$nonce."\n".hash('sha256',$body),hex2bin($key));
 if($corrupt==='signature')$sig=str_repeat('0',64);
 $headers=["X-F423-Company: ".$company,"X-F423-Timestamp: ".$ts,
   "X-F423-Nonce: ".$nonce,"X-F423-Signature: ".$sig];
 if($method==='POST')$headers[]='Content-Type: application/json';
 if($corrupt==='none')$headers=[];
 $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
   'content'=>$body,'timeout'=>8,'ignore_errors'=>true]]);
 $raw=@file_get_contents('http://application:8080'.$path,false,$context);
 $status=(int)preg_replace('/^HTTP\/\S+\s+([0-9]+).*$/','$1',$http_response_header[0]??'HTTP/1.1 0');
 return [$status,is_string($raw)?json_decode($raw,true):null];
}
$path='/_preview/portal/lab-s2s/orders/'.$orderId;
[$unauth]=signedRequest('GET',$path,$company,$key,null,'none');
[$forged]=signedRequest('GET',$path,$company,$key,null,'signature');
// Missing company header is deliberately indistinguishable from a
// nonexistent order (404); an invalid signature with valid scope is 403.
if(!in_array($unauth,[403,404],true)||$forged!==403)
    throw new RuntimeException('Unsigned/forged S2S request not denied: unsigned='.$unauth.', forged='.$forged);
[$status,$source]=signedRequest('GET',$path,$company,$key);
if($status!==200||!is_array($source)) throw new RuntimeException('Portal S2S HTTP source unavailable: '.$status);
if($source['schema']!=='portal_f423_ci_authenticated_order_source_v1'
  ||$source['order_id']!==$orderId||$source['company_id']!==$company
  ||$source['source']!=='ci_only_portal_customer_purchase_pg18'
  ||$source['purchase_state']!=='synthetic_confirmed'
  ||$source['purchase_evidence']!=='fake_provider_callback_only'
  ||$source['briefing_review_state']!=='synthetic_buyer_approved'
  ||!is_string($source['briefing_review_actor'])||$source['briefing_review_actor']===''
  ||$source['approved_briefing_revision']!==$source['order_revision']
  ||$source['approved_briefing_sha256']!==$source['briefing_submission_sha256']
  ||!is_string($source['approved_briefing_sha256'])
  ||!preg_match('/\A[a-f0-9]{64}\z/D',$source['approved_briefing_sha256']))
    throw new RuntimeException('Unapproved or inexact Portal buyer briefing');
$providers=array_keys($source['channels']);sort($providers);
if($providers!==['google_ads','meta_ads'])throw new RuntimeException('Not the exact synthetic Google+Meta order');
$sourceAlias='order_'.substr(hash('sha256',"f423-alias-v1\0".$company."\0".$orderId),0,32);
$otherAlias='order_'.substr(hash('sha256',"f423-alias-v1\0".$company."\0".'TEST-'.str_repeat('a',32)),0,32);
if($sourceAlias===$otherAlias) throw new RuntimeException('Alias collision');
$accounts=['google_ads'=>'1234567890','meta_ads'=>'98765432109876'];$channels=[];
foreach($providers as $provider) {
 $row=$source['channels'][$provider];
 if(!is_int($row['media_cents'])||$row['activation_cents']!==49000
  ||$row['connection_state']!=='not_connected'||$row['campaign_state']!=='not_created')
   throw new RuntimeException('Unexpected Portal channel source');
 $channels[$provider]=['account_id'=>$accounts[$provider],'media_cents'=>$row['media_cents'],
   'activation_cents'=>$row['activation_cents']];
}
$order=['order_id'=>$sourceAlias,'company_id'=>$company,'purchase_state'=>$source['purchase_state'],
 'purchase_evidence'=>$source['purchase_evidence'],'catalog_version'=>'performance-f423-v5-lab',
 'activation_total_cents'=>98000,'channels'=>$channels,
 'briefing_revision'=>$source['approved_briefing_revision'],
 'briefing_sha256'=>$source['approved_briefing_sha256']];
$briefing=['order_id'=>$sourceAlias,'company_id'=>$company,'state'=>'synthetic_review_approved',
 'revision'=>$order['briefing_revision'],'sha256'=>$order['briefing_sha256']];
$ledger=[];
$result=\Imagine\Performance\Tests\Fixtures\SyntheticTwoChannelOrderReadiness::decide(
 $order,$briefing,static fn(string $company,string $provider,string $account):?string=>null,$ledger);
if($result['state']!=='internal_review_only'||count($ledger)!==0
 ||count($result['channels'])!==2) throw new RuntimeException('Performance fixture policy not invoked');
$out=[];
foreach($providers as $provider) {
 $decision=$result['channels'][$provider];
 if($decision['state']!=='blocked'||$decision['reason']!=='mandate_or_s2s_denied') throw new RuntimeException('No mandate may enable a channel');
 $accountHash=hash('sha256','f423-account-reference-v1:'.$accounts[$provider]);
 $out[$provider]=['state'=>'blocked','reason'=>'mandate_or_s2s_denied',
   'media_cents'=>$channels[$provider]['media_cents'],'account_fingerprint'=>$accountHash,
   'fingerprint'=>hash('sha256',implode("\0",[$company,$orderId,(string)$order['briefing_revision'],
     $order['briefing_sha256'],$provider,(string)$channels[$provider]['media_cents'],
     $accountHash,'blocked','mandate_or_s2s_denied']))];
}
$packet=['company_id'=>$company,'order_id'=>$orderId,'source_order_alias'=>$sourceAlias,
 'order_revision'=>$order['briefing_revision'],'briefing_sha256'=>$order['briefing_sha256'],
 'channels'=>$out,'operational_enabled'=>false,'ads_write_enabled'=>false,'billable'=>false];
[$tampered]=signedRequest('POST',$path.'/decisions',$company,$key,
 array_replace($packet,['briefing_sha256'=>str_repeat('0',64)]));
if($tampered!==409) throw new RuntimeException('Mismatched signed digest must be denied');
[$wrongCompany]=signedRequest('GET',$path,'demo-'.str_repeat('a',32),$key);
if($wrongCompany!==404) throw new RuntimeException('Other company must not read synthetic order');
[$status,$response]=signedRequest('POST',$path.'/decisions',$company,$key,$packet);
if($status!==200||$response['persisted_channels']!==2||$response['ready_channels_count']!==0)
 throw new RuntimeException('Performance decision not persisted: '.$status);
[$status,$back]=signedRequest('GET',$path,$company,$key);
if($status!==200||$back['decision_persisted_by_performance']!==true
 ||$back['ready_channels_count']!==0||$back['performance_s2s_proven']!==true)
 throw new RuntimeException('Portal S2S readback missing persisted decision');
foreach($providers as $provider) if($back['channels'][$provider]['decision_state']!=='blocked')
 throw new RuntimeException('Blocked channel incorrectly promoted');
echo "PASS F423 S2S: authenticated Portal HTTP -> pinned Performance fixture -> actual PostgreSQL -> Portal HTTP; both channels blocked, idempotent across processes, no draft or Ads\n";
