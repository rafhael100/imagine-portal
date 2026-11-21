<?php
declare(strict_types=1);
// CI-only single-Google Performance policy adapter. NOT SQL001, official
// consent, operational IAM, provider access, a plan or a campaign draft.
if (getenv('GITHUB_ACTIONS')!=='true' || getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1' || $argc!==3) exit(1);
[$script,$company,$orderId]=$argv;
if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)
 ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$orderId)) exit(1);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)) exit(1);
require '/lab/AdministrativeAdsAccountMandateGate.php';

function googleLabHttp(string $method,string $path,string $company,string $key,?array $payload=null):array {
 $body=$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR);
 $ts=(string)time();$nonce=bin2hex(random_bytes(16));
 $signature=hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$company."\n".$ts."\n".$nonce."\n".hash('sha256',$body),hex2bin($key));
 $headers=["X-F423-Company: ".$company,"X-F423-Timestamp: ".$ts,
  "X-F423-Nonce: ".$nonce,"X-F423-Signature: ".$signature];
 if($method==='POST')$headers[]='Content-Type: application/json';
 $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
  'content'=>$body,'timeout'=>8,'ignore_errors'=>true]]);
 $raw=@file_get_contents('http://application:8080'.$path,false,$context);
 $status=(int)preg_replace('/^HTTP\/\S+\s+([0-9]+).*$/','$1',$http_response_header[0]??'HTTP/1.1 0');
 return [$status,is_string($raw)?json_decode($raw,true):null];
}
$path='/_preview/portal/lab-s2s/orders/'.$orderId;
[$status,$source]=googleLabHttp('GET',$path,$company,$key);
if($status!==200||!is_array($source)
 ||$source['schema']!=='portal_f423_ci_authenticated_order_source_v1'
 ||$source['source']!=='ci_only_portal_customer_purchase_pg18'
 ||$source['company_id']!==$company||$source['order_id']!==$orderId
 ||$source['purchase_state']!=='synthetic_confirmed'
 ||$source['purchase_evidence']!=='fake_provider_callback_only'
 ||$source['briefing_review_state']!=='synthetic_buyer_approved'
 ||!is_string($source['briefing_review_actor'])||$source['briefing_review_actor']===''
 ||$source['approved_briefing_revision']!==$source['order_revision']
 ||$source['approved_briefing_sha256']!==$source['briefing_submission_sha256']
 ||!is_string($source['approved_briefing_sha256'])
 ||!preg_match('/\A[a-f0-9]{64}\z/D',$source['approved_briefing_sha256'])
 ||array_keys($source['channels'])!==['google_ads']
 ||$source['channels']['google_ads']['media_cents']!==100000
 ||$source['channels']['google_ads']['activation_cents']!==49000
 ||$source['channels']['google_ads']['connection_state']!=='not_connected'
 ||$source['channels']['google_ads']['campaign_state']!=='not_created'
 ||$source['operational_enabled']!==false||$source['ads_write_enabled']!==false
 ||$source['billable']!==false)
 throw new RuntimeException('Source must be exact buyer-approved single-Google PG18 order');

$gateClass=\Imagine\Performance\Administration\AdministrativeAdsAccountMandateGate::class;
$now=2000000000;$account='1234567890';$principal='imagine_portal_s2s';
$service=['version'=>$gateClass::SERVICE_VERSION,'principal'=>$principal,
 'company_id'=>$company,'operation'=>$gateClass::OPERATION,'active'=>true,
 'revision'=>2,'expires_at'=>$now+600,'revoked_at'=>null];
$mandate=['version'=>$gateClass::VERSION,'company_id'=>$company,
 'service_principal'=>$principal,'provider'=>'google_ads',
 'account_id'=>$account,'purpose'=>$gateClass::PURPOSE,'operation'=>$gateClass::OPERATION,
 'consent_reference'=>'synthetic-test-only-google_ads',
 'credential_ref'=>'opaque-lab-only-google_ads',
 'status'=>'active','revision'=>2,'expires_at'=>$now+500,'revoked_at'=>null];
$gate=new $gateClass($principal,
 static function() use (&$service):array {return $service;},
 static function(string $c,string $p,string $a) use (&$mandate,$company,$account):?array {
   return $c===$company&&$p==='google_ads'&&$a===$account?$mandate:null;
 },
 static fn():int=>$now);
$lease=$gate->lease($company,'google_ads',$account);
if(!is_string($lease)||!preg_match('/\A[a-f0-9]{64}\z/D',$lease))
 throw new RuntimeException('Synthetic Google lease denied unexpectedly');
// Prove revocation and S2S suspension independently; restore candidate
// BEFORE persistence. These are in-memory snapshots, NOT official sources.
$mandate['revoked_at']=$now;
if($gate->lease($company,'google_ads',$account)!==null)
 throw new RuntimeException('Synthetic revocation not denied');
$mandate['revoked_at']=null;
$service['active']=false;
if($gate->lease($company,'google_ads',$account)!==null)
 throw new RuntimeException('Synthetic service suspension not denied');
$service['active']=true;
if(!hash_equals($lease,$gate->lease($company,'google_ads',$account)??''))
 throw new RuntimeException('Lease did not return to exact synthetic revision');

$revision=$source['approved_briefing_revision'];
$digest=$source['approved_briefing_sha256'];
$accountFingerprint=hash('sha256','f423-account-reference-v1:'.$account);
$state='internal_review_only';$reason='synthetic_gate_accepted_for_internal_review';
$row=['state'=>$state,'reason'=>$reason,'media_cents'=>100000,
 'account_fingerprint'=>$accountFingerprint,
 'fingerprint'=>hash('sha256',implode("\0",[$company,$orderId,(string)$revision,
   $digest,'google_ads','100000',$accountFingerprint,$state,$reason]))];
$packet=['company_id'=>$company,'order_id'=>$orderId,
 'source_order_alias'=>'order_'.substr(hash('sha256',"f423-alias-v1\0".$company."\0".$orderId),0,32),
 'order_revision'=>$revision,'briefing_sha256'=>$digest,
 'channels'=>['google_ads'=>$row],'operational_enabled'=>false,
 'ads_write_enabled'=>false,'billable'=>false];
// A correctly signed extra Meta decision cannot be added to Google-only
// order, even if it uses a syntactically valid fingerprint.
$unselected=$packet;
$unselected['channels']['meta_ads']=['state'=>'blocked','reason'=>'mandate_or_s2s_denied',
 'media_cents'=>70000,
 'account_fingerprint'=>hash('sha256','f423-account-reference-v1:98765432109876'),
 'fingerprint'=>str_repeat('a',64)];
[$bad]=googleLabHttp('POST',$path.'/decisions',$company,$key,$unselected);
if($bad!==409)throw new RuntimeException('Unselected Meta must be rejected');
[$status,$result]=googleLabHttp('POST',$path.'/decisions',$company,$key,$packet);
if($status!==200||!is_array($result)
 ||$result['schema']!=='f423_ci_only_persisted_channel_decisions_v1'
 ||$result['persisted_channels']!==1||$result['ready_channels_count']!==1
 ||$result['campaign_drafts_created']!==0)
 throw new RuntimeException('Single-Google PG18 decision did not persist: '.$status);
[$status,$back]=googleLabHttp('GET',$path,$company,$key);
if($status!==200||!is_array($back)||$back['decision_persisted_by_performance']!==true
 ||$back['performance_s2s_proven']!==true||$back['ready_channels_count']!==1
 ||array_keys($back['channels'])!==['google_ads']
 ||$back['channels']['google_ads']['decision_state']!=='internal_review_only'
 ||$back['channels']['google_ads']['decision_reason']!==$reason
 ||$back['channels']['google_ads']['campaign_state']!=='not_created'
 ||$back['channels']['google_ads']['connection_state']!=='not_connected'
 ||$back['operational_enabled']!==false||$back['ads_write_enabled']!==false
 ||$back['billable']!==false)
 throw new RuntimeException('Single-Google Portal authenticated S2S readback mismatch');
echo "PASS F423 GOOGLE-ONLY S2S: actual Performance gate with synthetic callbacks, one immutable PG18 internal-review decision, signed HTTP readback, no Ads/draft\n";
