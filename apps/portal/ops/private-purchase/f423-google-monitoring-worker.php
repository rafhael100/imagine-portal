<?php
declare(strict_types=1);
// Stateless offline simulated-provider reader (NO Ads API or SQL credentials).
if(getenv('GITHUB_ACTIONS')!=='true'
  ||getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1'||$argc!==3) exit(1);
[$script,$company,$order]=$argv;
if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)
  ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)) exit(1);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)) exit(1);
function monitoringRequest(string $method,string $path,string $company,string $key,?array $payload=null,bool $forge=false):array {
 $body=$payload===null?'':json_encode($payload,JSON_THROW_ON_ERROR);
 $stamp=(string)time();$nonce=bin2hex(random_bytes(16));
 $signature=hash_hmac('sha256',strtoupper($method)."\n".$path."\n".$company."\n".$stamp."\n".$nonce."\n".hash('sha256',$body),hex2bin($key));
 if($forge)$signature=str_repeat('0',64);
 $headers=["X-F423-Company: ".$company,"X-F423-Timestamp: ".$stamp,
   "X-F423-Nonce: ".$nonce,"X-F423-Signature: ".$signature];
 if($method==='POST')$headers[]='Content-Type: application/json';
 $stream=stream_context_create(['http'=>['method'=>$method,
   'header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>8,'ignore_errors'=>true]]);
 $raw=@file_get_contents('http://application:8080'.$path,false,$stream);
 $code=(int)preg_replace('/^HTTP\/\S+\s+([0-9]+).*$/','$1',$http_response_header[0]??'HTTP/1.1 0');
 return [$code,is_string($raw)?json_decode($raw,true):null];
}
$path='/_preview/portal/lab-s2s/orders/'.$order;
[$code,$source]=monitoringRequest('GET',$path,$company,$key);
if($code!==200||!is_array($source)
 ||($source['company_id']??null)!==$company||($source['order_id']??null)!==$order
 ||($source['purchase_state']??null)!=='synthetic_confirmed'
 ||($source['briefing_review_state']??null)!=='synthetic_buyer_approved'
 ||($source['plan_approved']??null)!==true
 ||($source['campaign_drafts_created']??null)!==1
 ||($source['internal_campaign_draft']['state']??null)!=='ci_internal_unpublished'
 ||($source['internal_campaign_draft']['published']??null)!==false
 ||($source['channels']['google_ads']['connection_state']??null)!=='not_connected'
 ||($source['channels']['google_ads']['campaign_state']??null)!=='internal_draft_unpublished'
 ||($source['operational_enabled']??null)!==false
 ||($source['ads_write_enabled']??null)!==false)
 throw new RuntimeException('Exact unconnected approved CI draft unavailable');
$revision=$source['approved_briefing_revision'];
$digest=$source['approved_briefing_sha256'];
$plan=$source['diagnostic_plan']['plan_fingerprint'];
$draft=$source['internal_campaign_draft']['draft_fingerprint'];
$expected=[
 ['sample_date'=>'2026-11-19','impressions'=>1000,'clicks'=>40,'conversions'=>2,
  'source'=>'ci_offline_google_ads_provider_fixture'],
 ['sample_date'=>'2026-11-20','impressions'=>1200,'clicks'=>46,'conversions'=>2,
  'source'=>'ci_offline_google_ads_provider_fixture']
];
$packet=[
 'schema'=>'f423_google_monitoring_ci_v1','company_id'=>$company,'order_id'=>$order,
 'order_revision'=>$revision,'briefing_sha256'=>$digest,
 'provider'=>'google_ads','plan_version'=>1,'plan_fingerprint'=>$plan,
 'draft_fingerprint'=>$draft,'samples'=>$expected,
 'operational_enabled'=>false,'ads_write_enabled'=>false,'billable'=>false
];
$endpoint=$path.'/monitoring-samples';
[$unsigned]=monitoringRequest('POST',$endpoint,$company,$key,$packet,true);
if($unsigned!==403) throw new RuntimeException('Forged monitoring signature not denied');
$bad=$packet;$bad['samples'][1]['clicks']=47;
[$changedMetric]=monitoringRequest('POST',$endpoint,$company,$key,$bad);
if($changedMetric!==409) throw new RuntimeException('Tampered metric allowed');
$bad=$packet;$bad['samples'][1]['sample_date']='2026-11-21';
[$changedDate]=monitoringRequest('POST',$endpoint,$company,$key,$bad);
if($changedDate!==409) throw new RuntimeException('New fake date allowed');
$bad=$packet;$bad['plan_fingerprint']=str_repeat('0',64);
[$changedPlan]=monitoringRequest('POST',$endpoint,$company,$key,$bad);
if($changedPlan!==409) throw new RuntimeException('Other plan allowed');
$bad=$packet;$bad['draft_fingerprint']=str_repeat('0',64);
[$changedDraft]=monitoringRequest('POST',$endpoint,$company,$key,$bad);
if($changedDraft!==409) throw new RuntimeException('Other draft allowed');
$bad=$packet;$bad['order_revision']++;
[$changedRevision]=monitoringRequest('POST',$endpoint,$company,$key,$bad);
if($changedRevision!==409) throw new RuntimeException('Other revision allowed');
$bad=$packet;$bad['company_id']='demo-'.str_repeat('a',32);
[$changedCompany]=monitoringRequest('POST',$endpoint,$company,$key,$bad);
if($changedCompany!==409) throw new RuntimeException('Cross-company sample allowed');
[$status,$saved]=monitoringRequest('POST',$endpoint,$company,$key,$packet);
if($status!==200||!is_array($saved)||$saved['samples_persisted']!==2
  ||$saved['source']!=='ci_offline_google_ads_provider_fixture'
  ||$saved['published']!==false||$saved['billable']!==false)
  throw new RuntimeException('Offline monitoring samples not persisted: '.$status);
[$read,$back]=monitoringRequest('GET',$path,$company,$key);
if($read!==200||!is_array($back)
  ||$back['monitoring_samples']!==$expected
  ||$back['monitoring_state']!=='two_dated_offline_fixture_samples'
  ||$back['campaign_drafts_created']!==1
  ||$back['operational_enabled']!==false||$back['ads_write_enabled']!==false
  ||($back['internal_campaign_draft']['published']??true)!==false)
 throw new RuntimeException('Same-order monitoring samples not available via Portal');
echo "PASS F423 OFFLINE MONITORING: two dated fixture-only Google samples persisted PG18, signed worker replay-safe; no Ads API, billing, publication or claimed real gain\n";
