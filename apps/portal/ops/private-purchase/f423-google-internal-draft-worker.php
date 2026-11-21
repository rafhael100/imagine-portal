<?php
declare(strict_types=1);
// Separate stateless CI worker: authenticated S2S only. NO SQL password,
// OAuth, Ads provider, PSP, credentials or actual campaign operations.
if(getenv('GITHUB_ACTIONS')!=='true'
  ||getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1'||$argc!==3) exit(1);
[$script,$company,$order]=$argv;
if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)
  ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)) exit(1);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)) exit(1);
function requestLab(string $method,string $path,string $company,string $key,?array $payload=null,bool $forged=false):array {
 $body=$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR);
 $ts=(string)time();$nonce=bin2hex(random_bytes(16));
 $sig=hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$company."\n".$ts."\n".$nonce."\n".hash('sha256',$body),hex2bin($key));
 if($forged)$sig=str_repeat('0',64);
 $headers=["X-F423-Company: ".$company,"X-F423-Timestamp: ".$ts,
   "X-F423-Nonce: ".$nonce,"X-F423-Signature: ".$sig];
 if($method==='POST')$headers[]='Content-Type: application/json';
 $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
   'content'=>$body,'timeout'=>8,'ignore_errors'=>true]]);
 $raw=@file_get_contents('http://application:8080'.$path,false,$context);
 $status=(int)preg_replace('/^HTTP\/\S+\s+([0-9]+).*$/','$1',$http_response_header[0]??'HTTP/1.1 0');
 return [$status,is_string($raw)?json_decode($raw,true):null];
}
$path='/_preview/portal/lab-s2s/orders/'.$order;
[$status,$source]=requestLab('GET',$path,$company,$key);
if($status!==200||!is_array($source)
  ||$source['company_id']!==$company||$source['order_id']!==$order
  ||$source['purchase_state']!=='synthetic_confirmed'
  ||$source['briefing_review_state']!=='synthetic_buyer_approved'
  ||$source['performance_s2s_proven']!==true
  ||$source['plan_approved']!==true
  ||!is_array($source['diagnostic_plan']??null)
  ||$source['diagnostic_plan']['buyer_plan_approved']!==true
  ||$source['diagnostic_plan']['plan_approval_state']!=='synthetic_buyer_approved'
  ||$source['diagnostic_plan']['plan_version']!==1
  ||array_keys($source['channels'])!==['google_ads']
  ||$source['channels']['google_ads']['decision_state']!=='internal_review_only'
  ||$source['channels']['google_ads']['connection_state']!=='not_connected'
  ||$source['operational_enabled']!==false||$source['ads_write_enabled']!==false
  ||$source['billable']!==false)
  throw new RuntimeException('Exact approved synthetic plan is required');
$revision=$source['approved_briefing_revision'];
$digest=$source['approved_briefing_sha256'];
$buyer=$source['briefing_review_actor'];
$plan=$source['diagnostic_plan']['plan_fingerprint'];
if(!is_int($revision)||!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)
  ||!is_string($buyer)||!preg_match('/\Ademo-[a-z0-9-]{3,40}\z/D',$buyer)
  ||!is_string($plan)||!preg_match('/\A[a-f0-9]{64}\z/D',$plan)) exit(1);
$fingerprint=hash('sha256',implode("\0",[
  $company,$order,(string)$revision,$digest,'google_ads','1',$plan,
  $buyer,'ci_internal_unpublished_v1'
]));
$packet=[
 'schema'=>'f423_google_internal_draft_ci_v1',
 'company_id'=>$company,'order_id'=>$order,'order_revision'=>$revision,
 'briefing_sha256'=>$digest,'provider'=>'google_ads','plan_version'=>1,
 'plan_fingerprint'=>$plan,'draft_fingerprint'=>$fingerprint,
 'draft_state'=>'ci_internal_unpublished',
 'operational_enabled'=>false,'ads_write_enabled'=>false,'billable'=>false
];
$endpoint=$path.'/internal-draft';
[$forged]=requestLab('POST',$endpoint,$company,$key,$packet,true);
if($forged!==403) throw new RuntimeException('Signed CI scope not enforced');
$bad=$packet;$bad['plan_fingerprint']=str_repeat('0',64);
[$wrongPlan]=requestLab('POST',$endpoint,$company,$key,$bad);
if($wrongPlan!==409) throw new RuntimeException('Changed plan must fail');
$bad=$packet;$bad['order_revision']++;
[$wrongRevision]=requestLab('POST',$endpoint,$company,$key,$bad);
if($wrongRevision!==409) throw new RuntimeException('Changed revision must fail');
$bad=$packet;$bad['company_id']='demo-'.str_repeat('a',32);
[$wrongCompany]=requestLab('POST',$endpoint,$company,$key,$bad);
if($wrongCompany!==409) throw new RuntimeException('Another company must fail');
$bad=$packet;$bad['briefing_sha256']=str_repeat('1',64);
[$wrongBriefing]=requestLab('POST',$endpoint,$company,$key,$bad);
if($wrongBriefing!==409) throw new RuntimeException('Changed briefing must fail');
$bad=$packet;$bad['provider']='meta_ads';
[$wrongProvider]=requestLab('POST',$endpoint,$company,$key,$bad);
if($wrongProvider!==409) throw new RuntimeException('Other channel must fail');
[$created,$result]=requestLab('POST',$endpoint,$company,$key,$packet);
if($created!==200||!is_array($result)
 ||$result['persisted_drafts']!==1
 ||$result['draft_state']!=='ci_internal_unpublished'
 ||$result['published']!==false||$result['operational_enabled']!==false)
 throw new RuntimeException('Unpublished internal draft did not persist: '.$created);
[$read,$back]=requestLab('GET',$path,$company,$key);
if($read!==200||!is_array($back)
  ||$back['campaign_drafts_created']!==1
  ||$back['plan_approved']!==true
  ||($back['channels']['google_ads']['campaign_state']??null)!=='internal_draft_unpublished'
  ||($back['channels']['google_ads']['connection_state']??null)!=='not_connected'
  ||($back['internal_campaign_draft']['draft_fingerprint']??null)!==$fingerprint
  ||$back['internal_campaign_draft']['published']!==false
  ||$back['operational_enabled']!==false||$back['ads_write_enabled']!==false
  ||$back['billable']!==false)
  throw new RuntimeException('Portal cannot read the SAME unpublished internal draft');
echo "PASS F423 INTERNAL DRAFT: same Google-only order/briefing/approved plan, signed S2S, durable PG18 replay, cross-company/revision/plan/channel negatives, no Ads publication\n";
