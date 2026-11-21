<?php
declare(strict_types=1);
// Separate signed PHP container records an OFFLINE fixture comparison only.
if(getenv('GITHUB_ACTIONS')!=='true'
 ||getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1'||$argc!==3)exit(1);
[$script,$company,$order]=$argv;
if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)
 ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order))exit(1);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$key))exit(1);
function offlineRequest(string $method,string $path,string $company,string $key,
 ?array $packet=null,bool $badSignature=false):array {
 $body=$packet===null?'':json_encode($packet,JSON_THROW_ON_ERROR);
 $time=(string)time();$nonce=bin2hex(random_bytes(16));
 $sig=hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$company."\n".
   $time."\n".$nonce."\n".hash('sha256',$body),hex2bin($key));
 if($badSignature)$sig=str_repeat('0',64);
 $headers=["X-F423-Company: ".$company,"X-F423-Timestamp: ".$time,
   "X-F423-Nonce: ".$nonce,"X-F423-Signature: ".$sig];
 if($method==='POST')$headers[]='Content-Type: application/json';
 $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
  'content'=>$body,'timeout'=>8,'ignore_errors'=>true]]);
 $raw=@file_get_contents('http://application:8080'.$path,false,$ctx);
 $code=(int)preg_replace('/^HTTP\/\S+\s+([0-9]+).*$/','$1',$http_response_header[0]??'HTTP/1.1 0');
 return [$code,is_string($raw)?json_decode($raw,true):null];
}
$base='/_preview/portal/lab-s2s/orders/'.$order;
[$code,$s]=offlineRequest('GET',$base,$company,$key);
if($code!==200||!is_array($s)
 ||($s['company_id']??null)!==$company||($s['order_id']??null)!==$order
 ||($s['purchase_state']??null)!=='synthetic_confirmed'
 ||($s['briefing_review_state']??null)!=='synthetic_buyer_approved'
 ||($s['plan_approved']??null)!==true
 ||($s['improvement_proposal']['separate_approval_recorded']??null)!==true
 ||($s['improvement_proposal']['applied']??null)!==false
 ||($s['internal_campaign_draft']['state']??null)!=='ci_internal_unpublished'
 ||($s['operational_enabled']??null)!==false||($s['ads_write_enabled']??null)!==false)
 throw new RuntimeException('Exact separate offline approval unavailable');
$revision=$s['approved_briefing_revision'];
$digest=$s['approved_briefing_sha256'];
$proposal=$s['improvement_proposal'];
$post=[
 ['sample_date'=>'2026-11-21','impressions'=>1100,'clicks'=>40,'conversions'=>2,
   'source'=>'ci_offline_google_ads_post_fixture'],
 ['sample_date'=>'2026-11-22','impressions'=>1200,'clicks'=>44,'conversions'=>2,
   'source'=>'ci_offline_google_ads_post_fixture'],
];
$postSha=hash('sha256',json_encode($post,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
$fp=hash('sha256',implode("\0",[$company,$order,(string)$revision,$digest,'google_ads',
 $proposal['proposal_fingerprint'],$proposal['evidence_sha256'],$postSha,'ci_offline_application_only']));
$packet=[
 'schema'=>'f423_offline_application_ci_v1','company_id'=>$company,'order_id'=>$order,
 'order_revision'=>$revision,'briefing_sha256'=>$digest,'provider'=>'google_ads',
 'plan_version'=>1,'proposal_version'=>1,
 'proposal_fingerprint'=>$proposal['proposal_fingerprint'],
 'draft_fingerprint'=>$proposal['draft_fingerprint'],
 'evidence_sha256'=>$proposal['evidence_sha256'],'post_sha256'=>$postSha,
 'result_fingerprint'=>$fp,'post_samples'=>$post,
 'application_state'=>'ci_offline_fixture_applied','published'=>false,
 'ads_write_enabled'=>false,'operational_enabled'=>false,'billable'=>false,
];
$endpoint=$base.'/offline-application';
[$invalid]=offlineRequest('POST',$endpoint,$company,$key,$packet,true);
if($invalid!==403)throw new RuntimeException('Invalid HMAC was accepted');
$bad=$packet;$bad['company_id']='demo-'.str_repeat('a',32);
[$co]=offlineRequest('POST',$endpoint,$company,$key,$bad);
if($co!==409)throw new RuntimeException('Wrong company was accepted');
$bad=$packet;$bad['order_revision']++;
[$rev]=offlineRequest('POST',$endpoint,$company,$key,$bad);
if($rev!==409)throw new RuntimeException('Wrong revision was accepted');
$bad=$packet;$bad['post_samples'][1]['conversions']=10;
[$metric]=offlineRequest('POST',$endpoint,$company,$key,$bad);
if($metric!==409)throw new RuntimeException('Altered post sample accepted');
$bad=$packet;$bad['result_fingerprint']=str_repeat('0',64);
[$sha]=offlineRequest('POST',$endpoint,$company,$key,$bad);
if($sha!==409)throw new RuntimeException('Altered result digest accepted');
$bad=$packet;$bad['published']=true;
[$pub]=offlineRequest('POST',$endpoint,$company,$key,$bad);
if($pub!==409)throw new RuntimeException('Published flag accepted');
$bad=$packet;$bad['ads_write_enabled']=true;
[$write]=offlineRequest('POST',$endpoint,$company,$key,$bad);
if($write!==409)throw new RuntimeException('Ads-write flag accepted');
[$ok,$result]=offlineRequest('POST',$endpoint,$company,$key,$packet);
if($ok!==200||($result['persisted_offline_applications']??null)!==1
 ||($result['application_state']??null)!=='ci_offline_fixture_applied'
 ||($result['published']??true)!==false||($result['operational_enabled']??true)!==false)
 throw new RuntimeException('Offline application not persisted');
[$backCode,$back]=offlineRequest('GET',$base,$company,$key);
if($backCode!==200||($back['offline_improvement_simulation']['result_fingerprint']??null)!==$fp
 ||($back['offline_improvement_simulation']['simulated_application_recorded']??null)!==true
 ||($back['offline_improvement_simulation']['after_totals']['conversions']??null)!==4
 ||($back['offline_improvement_simulation']['before_totals']['conversions']??null)!==4
 ||($back['offline_improvement_simulation']['published']??true)!==false
 ||($back['improvement_proposal']['applied']??true)!==false)
 throw new RuntimeException('Same-order comparison not relayed');
echo "PASS F423 OFFLINE APPLICATION: separate buyer consent verified, two synthetic post samples, neutral conversions comparison, exact PG18 replay, zero Ads writes\n";
