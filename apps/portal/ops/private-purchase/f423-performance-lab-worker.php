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
require '/lab/f423-google-only-readiness.php';
require '/lab/AdministrativeAdsAccountMandateGate.php';

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
$singleGoogle=$providers===['google_ads'];
if(!$singleGoogle && $providers!==['google_ads','meta_ads'])
    throw new RuntimeException('Not an exact selected synthetic F423 order');
$sourceAlias='order_'.substr(hash('sha256',"f423-alias-v1\0".$company."\0".$orderId),0,32);
$otherAlias='order_'.substr(hash('sha256',"f423-alias-v1\0".$company."\0".'TEST-'.str_repeat('a',32)),0,32);
if($sourceAlias===$otherAlias) throw new RuntimeException('Alias collision');
$accounts=['google_ads'=>'1234567890','meta_ads'=>'98765432109876'];$channels=[];
foreach($providers as $provider) {
 $row=$source['channels'][$provider];
 if(!is_int($row['media_cents'])||$row['media_cents']!==($provider==='google_ads'?100000:70000)
  ||$row['activation_cents']!==49000
  ||$row['connection_state']!=='not_connected'||$row['campaign_state']!=='not_created')
   throw new RuntimeException('Unexpected Portal channel source');
 $channels[$provider]=['account_id'=>$accounts[$provider],'media_cents'=>$row['media_cents'],
   'activation_cents'=>$row['activation_cents']];
}
$order=['order_id'=>$sourceAlias,'company_id'=>$company,'purchase_state'=>$source['purchase_state'],
 'purchase_evidence'=>$source['purchase_evidence'],'catalog_version'=>'performance-f423-v5-lab',
 'activation_total_cents'=>49000*count($providers),'channels'=>$channels,
 'briefing_revision'=>$source['approved_briefing_revision'],
 'briefing_sha256'=>$source['approved_briefing_sha256']];
$briefing=['order_id'=>$sourceAlias,'company_id'=>$company,'state'=>'synthetic_review_approved',
 'revision'=>$order['briefing_revision'],'sha256'=>$order['briefing_sha256']];
// Synthetic snapshots INJECTED into the actual Performance gate, not Portal
// SQL001 or provider IAM/consent. Leases are test digests only.
$gateClass=\Imagine\Performance\Administration\AdministrativeAdsAccountMandateGate::class;
$principal='imagine_portal_s2s';$now=2000000000;
$service=[
 'version'=>$gateClass::SERVICE_VERSION,'principal'=>$principal,'company_id'=>$company,
 'operation'=>$gateClass::OPERATION,'active'=>true,'revision'=>2,
 'expires_at'=>$now+600,'revoked_at'=>null
];
$mandates=[];
foreach($providers as $provider) {
 $mandates[$provider][$accounts[$provider]]=[
   'version'=>$gateClass::VERSION,'company_id'=>$company,
   'service_principal'=>$principal,'provider'=>$provider,
   'account_id'=>$accounts[$provider],'purpose'=>$gateClass::PURPOSE,
   'operation'=>$gateClass::OPERATION,
   'consent_reference'=>'synthetic-test-only-'.$provider,
   'credential_ref'=>'opaque-lab-only-'.$provider,
   'status'=>$provider==='google_ads'?'active':'revoked',
   'revision'=>2,'expires_at'=>$now+500,'revoked_at'=>null
 ];
}
$gate=new $gateClass($principal,
 static function() use (&$service):array { return $service; },
 static function(string $c,string $p,string $account) use (&$mandates):?array {
    return $mandates[$p][$account]??null;
 },
 static fn():int=>$now
);
$authorize=static fn(string $c,string $p,string $a):?string=>$gate->lease($c,$p,$a);
$decide=static function(array &$ledger) use($order,$briefing,$authorize,$singleGoogle):array {
 if($singleGoogle) return F423GoogleOnlyReadiness::decide($order,$briefing,$authorize,$ledger);
 return \Imagine\Performance\Tests\Fixtures\SyntheticTwoChannelOrderReadiness::decide(
   $order,$briefing,$authorize,$ledger);
};
// Strict Google-only contract adversarial probes before any decision write.
// These cannot be satisfied by fabricating a Meta channel or approving a
// different SQL revision; none consult Ads or the provider mock.
if($singleGoogle) {
 foreach(['extra_provider','wrong_amount','wrong_revision','other_company','wrong_briefing_sha'] as $attack) {
   $altered=$order;$alteredBriefing=$briefing;
   if($attack==='extra_provider')$altered['channels']['meta_ads']=[
     'account_id'=>'98765432109876','media_cents'=>70000,'activation_cents'=>49000];
   if($attack==='wrong_amount')$altered['channels']['google_ads']['media_cents']=100001;
   if($attack==='wrong_revision')$altered['briefing_revision']++;
   if($attack==='other_company')$altered['company_id']='demo-'.str_repeat('a',32);
   if($attack==='wrong_briefing_sha')$alteredBriefing['sha256']=str_repeat('0',64);
   try {
     $testLedger=[];F423GoogleOnlyReadiness::decide($altered,$alteredBriefing,$authorize,$testLedger);
     throw new RuntimeException('Google-only malformed contract accepted: '.$attack);
   } catch (\InvalidArgumentException) {
     if($testLedger!==[])throw new RuntimeException('Invalid order obtained local decision');
   }
 }
}
$ledger=[];
$result=$decide($ledger);
if($result['state']!=='internal_review_only'||count($ledger)!==1
 ||$result['channels']['google_ads']['state']!=='internal_review_only'
 ||(!$singleGoogle && $result['channels']['meta_ads']['state']!=='blocked'))
 throw new RuntimeException('Only Google can enter synthetic internal review');
// Pre-persistence adversarial probes; the ledger is immutable after INSERT.
$mandates['google_ads'][$accounts['google_ads']]['revoked_at']=$now;
$revokedLedger=[];$afterRevoke=$decide($revokedLedger);
if($revokedLedger!==[]||$afterRevoke['channels']['google_ads']['state']!=='blocked'
 ||(!$singleGoogle && $afterRevoke['channels']['meta_ads']['state']!=='blocked'))
 throw new RuntimeException('Synthetic Google revocation not isolated');
$mandates['google_ads'][$accounts['google_ads']]['revoked_at']=null;
if(!$singleGoogle) {
$mandates['google_ads'][$accounts['google_ads']]['status']='revoked';
$mandates['meta_ads'][$accounts['meta_ads']]['status']='active';
$invertedLedger=[];$inverted=$decide($invertedLedger);
if(count($invertedLedger)!==1
 ||$inverted['channels']['google_ads']['state']!=='blocked'
 ||$inverted['channels']['meta_ads']['state']!=='internal_review_only')
 throw new RuntimeException('Reverse synthetic channel gate not isolated');
$mandates['google_ads'][$accounts['google_ads']]['status']='active';
$mandates['meta_ads'][$accounts['meta_ads']]['status']='revoked';
}
$out=[];
foreach($providers as $provider) {
 $decision=$result['channels'][$provider];
 $state=$decision['state'];
 $reason=$state==='internal_review_only'
    ?'synthetic_gate_accepted_for_internal_review':'mandate_or_s2s_denied';
 if(!in_array($state,['blocked','internal_review_only'],true)
    ||($state==='blocked' && $decision['reason']!=='mandate_or_s2s_denied'))
    throw new RuntimeException('Unexpected synthetic Performance decision');
 $accountHash=hash('sha256','f423-account-reference-v1:'.$accounts[$provider]);
 $out[$provider]=['state'=>$state,'reason'=>$reason,
   'media_cents'=>$channels[$provider]['media_cents'],'account_fingerprint'=>$accountHash,
   'fingerprint'=>hash('sha256',implode("\0",[$company,$orderId,(string)$order['briefing_revision'],
     $order['briefing_sha256'],$provider,(string)$channels[$provider]['media_cents'],
     $accountHash,$state,$reason]))];
}
$packet=['company_id'=>$company,'order_id'=>$orderId,'source_order_alias'=>$sourceAlias,
 'order_revision'=>$order['briefing_revision'],'briefing_sha256'=>$order['briefing_sha256'],
 'channels'=>$out,'operational_enabled'=>false,'ads_write_enabled'=>false,'billable'=>false];
[$tampered]=signedRequest('POST',$path.'/decisions',$company,$key,
 array_replace($packet,['briefing_sha256'=>str_repeat('0',64)]));
if($tampered!==409) throw new RuntimeException('Mismatched signed digest must be denied');
// A correctly signed and internally consistent (rehashed) packet with a
// different Meta amount must still fail against the Portal's exact offer.
$wrongPrice=$packet;
$priceProvider=$singleGoogle?'google_ads':'meta_ads';
$wrongPrice['channels'][$priceProvider]['media_cents']++;
$wrongPrice['channels'][$priceProvider]['fingerprint']=hash('sha256',implode("\0",[
 $company,$orderId,(string)$order['briefing_revision'],$order['briefing_sha256'],
 $priceProvider,(string)$wrongPrice['channels'][$priceProvider]['media_cents'],
 $out[$priceProvider]['account_fingerprint'],
 $out[$priceProvider]['state'],$out[$priceProvider]['reason']
]));
[$wrongPriceStatus]=signedRequest('POST',$path.'/decisions',$company,$key,$wrongPrice);
if($wrongPriceStatus!==409) throw new RuntimeException('Mismatched signed selected-channel amount must be denied');
// Signed and re-fingerprinted swapped account must be refused by the
// exact test-account contract, not merely by HMAC validity.
$wrongAccount=$packet;
$wrongAccount['channels']['google_ads']['account_fingerprint']=
  hash('sha256','f423-account-reference-v1:1234567891');
$wrongAccount['channels']['google_ads']['fingerprint']=hash('sha256',implode("\0",[
 $company,$orderId,(string)$order['briefing_revision'],$order['briefing_sha256'],
 'google_ads',(string)$out['google_ads']['media_cents'],
 $wrongAccount['channels']['google_ads']['account_fingerprint'],
 $out['google_ads']['state'],$out['google_ads']['reason']
]));
[$accountStatus]=signedRequest('POST',$path.'/decisions',$company,$key,$wrongAccount);
if($accountStatus!==409) throw new RuntimeException('Signed swapped Google account not denied');

// Correctly re-fingerprinted newer revision must not bypass the actual SQL
// buyer approval or the current order revision.
$wrongRevision=$packet;$wrongRevision['order_revision']++;
foreach($wrongRevision['channels'] as $provider=>&$r) {
 $r['fingerprint']=hash('sha256',implode("\0",[
  $company,$orderId,(string)$wrongRevision['order_revision'],
  $order['briefing_sha256'],$provider,(string)$r['media_cents'],
  $r['account_fingerprint'],$r['state'],$r['reason']
 ]));
}
unset($r);
[$revisionStatus]=signedRequest('POST',$path.'/decisions',$company,$key,$wrongRevision);
if($revisionStatus!==409) throw new RuntimeException('Signed unapproved revision not denied');

$otherCompany='demo-'.str_repeat('a',32);
[$companyStatus]=signedRequest('POST',$path.'/decisions',$otherCompany,$key,
 array_replace($packet,['company_id'=>$otherCompany]));
if($companyStatus!==409) throw new RuntimeException('Signed other-company order not denied');
[$wrongCompany]=signedRequest('GET',$path,'demo-'.str_repeat('a',32),$key);
if($wrongCompany!==404) throw new RuntimeException('Other company must not read synthetic order');
[$status,$response]=signedRequest('POST',$path.'/decisions',$company,$key,$packet);
if($status!==200||$response['schema']!=='f423_ci_only_persisted_channel_decisions_v1'
 ||$response['persisted_channels']!==count($providers)||$response['ready_channels_count']!==1)
 throw new RuntimeException('Performance decision not persisted: '.$status);
[$status,$back]=signedRequest('GET',$path,$company,$key);
if($status!==200||$back['decision_persisted_by_performance']!==true
 ||$back['ready_channels_count']!==1||$back['performance_s2s_proven']!==true)
 throw new RuntimeException('Portal S2S readback missing persisted decision');
if($back['channels']['google_ads']['decision_state']!=='internal_review_only'
 ||(!$singleGoogle && $back['channels']['meta_ads']['decision_state']!=='blocked')
 ||$back['channels']['google_ads']['campaign_state']!=='not_created'
 ||(!$singleGoogle && $back['channels']['meta_ads']['campaign_state']!=='not_created'))
 throw new RuntimeException('Portal internal review must not imply campaign or connection');
echo "PASS F423 S2S: ".($singleGoogle?"Google-only exact selected order":"Google+Meta selected order")."; Google internal-only".($singleGoogle?"":", Meta blocked")."; PG18 readback/replay; revoke and signed negative probes, no Ads/draft\n";


if($singleGoogle) {
    // The plan is PRODUCED by the separate Performance lab worker after
    // authenticating the same exact buyer-approved order and persisting its
    // internal-only decision. All metrics are fixture values, NEVER Google.
    $plan=[
      'schema'=>'f423_google_diagnostic_plan_ci_v1','company_id'=>$company,
      'order_id'=>$orderId,'order_revision'=>$order['briefing_revision'],
      'briefing_sha256'=>$order['briefing_sha256'],'provider'=>'google_ads',
      'diagnostic_version'=>1,'plan_version'=>1,
      'decision_fingerprint'=>$out['google_ads']['fingerprint'],
      'metric_origin'=>'ci_synthetic_fixture_no_ads',
      'sample_impressions'=>1000,'sample_clicks'=>40,'sample_conversions'=>2,
      'media_cents'=>100000,'proposed_action'=>'review_search_terms_only',
      'plan_state'=>'awaiting_separate_approval',
      'operational_enabled'=>false,'campaign_draft_created'=>false,'billable'=>false
    ];
    $plan['plan_fingerprint']=hash('sha256',implode("\0",[
      $company,$orderId,(string)$order['briefing_revision'],$order['briefing_sha256'],
      'google_ads',$out['google_ads']['fingerprint'],'1','1',
      '1000','40','2','100000','review_search_terms_only'
    ]));
    $planPath=$path.'/diagnostic-plan';
    [$unsigned]=signedRequest('POST',$planPath,$company,$key,$plan,'signature');
    if($unsigned!==403) throw new RuntimeException('Forged plan signature not denied');
    $bad=$plan;$bad['sample_clicks']=41;
    [$badStatus]=signedRequest('POST',$planPath,$company,$key,$bad);
    if($badStatus!==409) throw new RuntimeException('Altered synthetic metric not denied');
    $bad=$plan;$bad['order_revision']++;
    [$badStatus]=signedRequest('POST',$planPath,$company,$key,$bad);
    if($badStatus!==409) throw new RuntimeException('Plan revision change not denied');
    $bad=$plan;$bad['decision_fingerprint']=str_repeat('0',64);
    [$badStatus]=signedRequest('POST',$planPath,$company,$key,$bad);
    if($badStatus!==409) throw new RuntimeException('Plan decision substitution not denied');
    $bad=$plan;$bad['company_id']='demo-'.str_repeat('a',32);
    [$badStatus]=signedRequest('POST',$planPath,$company,$key,$bad);
    if($badStatus!==409) throw new RuntimeException('Cross-company signed plan not denied');
    [$planStatus,$saved]=signedRequest('POST',$planPath,$company,$key,$plan);
    if($planStatus!==200||$saved['persisted_plans']!==1
      ||$saved['plan_state']!=='awaiting_separate_approval'
      ||$saved['campaign_drafts_created']!==0||$saved['plan_approved']!==false)
       throw new RuntimeException('Exact synthetic diagnostic plan not persisted');
    [$readStatus,$updated]=signedRequest('GET',$path,$company,$key);
    if($readStatus!==200||!is_array($updated['diagnostic_plan']??null)
      ||$updated['diagnostic_plan']['plan_version']!==1
      ||$updated['diagnostic_plan']['diagnostic_version']!==1
      ||$updated['diagnostic_plan']['plan_fingerprint']!==$plan['plan_fingerprint']
      ||$updated['diagnostic_plan']['metric_origin']!=='ci_synthetic_fixture_no_ads'
      ||$updated['diagnostic_plan']['buyer_plan_approved']!==false
      ||$updated['diagnostic_plan']['campaign_draft_created']!==false
      ||$updated['plan_approved']!==false||$updated['campaign_drafts_created']!==0)
       throw new RuntimeException('Plan not readable from actual buyer order');
    echo "PASS F423 GOOGLE PLAN: isolated signed Performance PHP to PG18, v1 synthetic diagnosis and plan awaiting separate approval, no draft, no Ads; replay safe\n";
}
