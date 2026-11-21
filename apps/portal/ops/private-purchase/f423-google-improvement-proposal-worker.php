<?php
declare(strict_types=1);
// Offline-only recommendation worker. No SQL/Ads/PSP credentials, no provider calls.
if(getenv('GITHUB_ACTIONS')!=='true'
 ||getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1'||$argc!==3) exit(1);
[$script,$company,$order]=$argv;
if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)
 ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)) exit(1);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)) exit(1);
function proposeRequest(string $method,string $path,string $company,string $key,?array $payload=null,bool $forge=false):array {
 $body=$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR);
 $ts=(string)time();$nonce=bin2hex(random_bytes(16));
 $sign=hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$company."\n".$ts."\n".$nonce."\n".hash('sha256',$body),hex2bin($key));
 if($forge)$sign=str_repeat('0',64);
 $headers=["X-F423-Company: ".$company,"X-F423-Timestamp: ".$ts,
    "X-F423-Nonce: ".$nonce,"X-F423-Signature: ".$sign];
 if($method==='POST')$headers[]='Content-Type: application/json';
 $ctx=stream_context_create(['http'=>['method'=>$method,
    'header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>8,'ignore_errors'=>true]]);
 $raw=@file_get_contents('http://application:8080'.$path,false,$ctx);
 $code=(int)preg_replace('/^HTTP\/\S+\s+([0-9]+).*$/','$1',$http_response_header[0]??'HTTP/1.1 0');
 return [$code,is_string($raw)?json_decode($raw,true):null];
}
$path='/_preview/portal/lab-s2s/orders/'.$order;
[$code,$source]=proposeRequest('GET',$path,$company,$key);
$expected=[
 ['sample_date'=>'2026-11-19','impressions'=>1000,'clicks'=>40,'conversions'=>2,
  'source'=>'ci_offline_google_ads_provider_fixture'],
 ['sample_date'=>'2026-11-20','impressions'=>1200,'clicks'=>46,'conversions'=>2,
  'source'=>'ci_offline_google_ads_provider_fixture'],
];
if($code!==200||!is_array($source)||($source['company_id']??null)!==$company
  ||($source['order_id']??null)!==$order
  ||($source['purchase_state']??null)!=='synthetic_confirmed'
  ||($source['briefing_review_state']??null)!=='synthetic_buyer_approved'
  ||($source['plan_approved']??null)!==true
  ||($source['monitoring_samples']??null)!==$expected
  ||($source['monitoring_state']??null)!=='two_dated_offline_fixture_samples'
  ||($source['internal_campaign_draft']['state']??null)!=='ci_internal_unpublished'
  ||($source['internal_campaign_draft']['published']??null)!==false
  ||($source['operational_enabled']??null)!==false
  ||($source['ads_write_enabled']??null)!==false)
  throw new RuntimeException('Same-order offline monitoring evidence unavailable');
$revision=$source['approved_briefing_revision'];
$digest=$source['approved_briefing_sha256'];
$plan=$source['diagnostic_plan']['plan_fingerprint'];
$draft=$source['internal_campaign_draft']['draft_fingerprint'];
$evidence=hash('sha256',json_encode($expected,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
$action='audit_search_terms_offline_simulation';
$fp=hash('sha256',implode("\0",[$company,$order,(string)$revision,$digest,'google_ads',
 '1','1',$plan,$draft,$evidence,$action,'ci_offline_improvement_only']));
$packet=[
 'schema'=>'f423_offline_improvement_ci_v1','company_id'=>$company,'order_id'=>$order,
 'order_revision'=>$revision,'briefing_sha256'=>$digest,'provider'=>'google_ads',
 'plan_version'=>1,'proposal_version'=>1,'plan_fingerprint'=>$plan,
 'draft_fingerprint'=>$draft,'evidence_sha256'=>$evidence,
 'proposal_fingerprint'=>$fp,'action'=>$action,'state'=>'awaiting_separate_approval',
 'metric_origin'=>'ci_offline_google_ads_provider_fixture',
 'separate_approval_recorded'=>false,'applied'=>false,
 'operational_enabled'=>false,'billable'=>false,
];
$endpoint=$path.'/improvement-proposal';
[$badSign]=proposeRequest('POST',$endpoint,$company,$key,$packet,true);
if($badSign!==403) throw new RuntimeException('Unsigned proposal not denied');
$bad=$packet;$bad['evidence_sha256']=str_repeat('0',64);
[$wrongEvidence]=proposeRequest('POST',$endpoint,$company,$key,$bad);
if($wrongEvidence!==409) throw new RuntimeException('Changed metrics evidence allowed');
$bad=$packet;$bad['proposal_fingerprint']=str_repeat('0',64);
[$wrongFp]=proposeRequest('POST',$endpoint,$company,$key,$bad);
if($wrongFp!==409) throw new RuntimeException('Changed proposal SHA allowed');
$bad=$packet;$bad['order_revision']++;
[$wrongRev]=proposeRequest('POST',$endpoint,$company,$key,$bad);
if($wrongRev!==409) throw new RuntimeException('Changed revision allowed');
$bad=$packet;$bad['company_id']='demo-'.str_repeat('a',32);
[$wrongCo]=proposeRequest('POST',$endpoint,$company,$key,$bad);
if($wrongCo!==409) throw new RuntimeException('Changed company allowed');
$bad=$packet;$bad['action']='publish_campaign';
[$badAction]=proposeRequest('POST',$endpoint,$company,$key,$bad);
if($badAction!==409) throw new RuntimeException('Campaign publish action allowed');
$bad=$packet;$bad['separate_approval_recorded']=true;
[$fakeApprove]=proposeRequest('POST',$endpoint,$company,$key,$bad);
if($fakeApprove!==409) throw new RuntimeException('Approval cannot be declared by worker');
[$created,$saved]=proposeRequest('POST',$endpoint,$company,$key,$packet);
if($created!==200||!is_array($saved)
  ||$saved['persisted_proposals']!==1
  ||$saved['proposal_state']!=='awaiting_separate_approval'
  ||$saved['separate_approval_recorded']!==false
  ||$saved['applied']!==false||$saved['published']!==false)
 throw new RuntimeException('Offline versioned proposal not saved');
[$read,$back]=proposeRequest('GET',$path,$company,$key);
if($read!==200||($back['improvement_proposal']['proposal_fingerprint']??null)!==$fp
  ||($back['improvement_proposal']['evidence_sha256']??null)!==$evidence
  ||($back['improvement_proposal']['state']??null)!=='awaiting_separate_approval'
  ||($back['improvement_proposal']['separate_approval_recorded']??true)!==false
  ||($back['improvement_proposal']['applied']??true)!==false
  ||($back['campaign_drafts_created']??null)!==1
  ||($back['operational_enabled']??true)!==false)
 throw new RuntimeException('Proposal not relayed to same buyer order');
echo "PASS F423 OFFLINE IMPROVEMENT: exact two fixture sample evidence, one versioned proposal PG18, separate approval pending, replay safe, no Ads API or application\n";
