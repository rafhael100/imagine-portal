<?php
declare(strict_types=1);
// CI-only: derive a PAUSED mock-stage receipt from the real signed Portal order.
// Never activate, deliver, publish or call an Ads provider.
if(getenv('GITHUB_ACTIONS')!=='true'
   || getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1'
   || !in_array($argc,[3,4],true)
   || ($argc===4 && ($argv[3]??'')!=='--expect-no-draft')) exit(78);
[$script,$company,$order]=$argv;
if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)
   || !preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)) exit(78);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)) exit(78);
function stageHttp(string $host,string $method,string $path,array $headers,
                   ?array $packet=null): array {
    $body=$packet===null?'':json_encode($packet,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $headers[]='Content-Type: application/json';
    $context=stream_context_create(['http'=>['method'=>$method,'content'=>$body,
        'header'=>implode("\r\n",$headers),'timeout'=>8,'ignore_errors'=>true]]);
    $response=@file_get_contents('http://'.$host.$path,false,$context);
    $status=(int)preg_replace('/^HTTP\/\S+\s+([0-9]+).*$/','$1',
        $http_response_header[0]??'HTTP/1.1 0');
    return [$status,is_string($response)?json_decode($response,true):null];
}
function mockStage(string $method,string $path,string $key,?array $packet=null,
                   bool $forge=false): array {
    $body=$packet===null?'':json_encode($packet,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $sig=hash_hmac('sha256',$method."\n".$path."\n".$body,hex2bin($key));
    if($forge)$sig=str_repeat('0',64);
    return stageHttp('f423-mock-stage:8100',$method,$path,
        ['X-F423-Mock-Signature: '.$sig],$packet);
}
$path='/_preview/portal/lab-s2s/orders/'.$order;
$ts=(string)time();
$nonce=bin2hex(random_bytes(16));
$sig=hash_hmac('sha256',"GET\n".$path."\n".$company."\n".$ts."\n".$nonce."\n".
    hash('sha256',''),hex2bin($key));
[$status,$snap]=stageHttp('application:8080','GET',$path,[
    'X-F423-Company: '.$company,'X-F423-Timestamp: '.$ts,
    'X-F423-Nonce: '.$nonce,'X-F423-Signature: '.$sig]);
if($status!==200 || !is_array($snap)
    || ($snap['company_id']??null)!==$company || ($snap['order_id']??null)!==$order
    || ($snap['purchase_state']??null)!=='synthetic_confirmed'
    || ($snap['briefing_review_state']??null)!=='synthetic_buyer_approved'
    || ($snap['performance_s2s_proven']??null)!==true
    || ($snap['plan_approved']??null)!==true
    || ($snap['diagnostic_plan']['buyer_plan_approved']??null)!==true
    || ($snap['channels']['google_ads']['decision_state']??null)!=='internal_review_only'
    || ($snap['channels']['google_ads']['connection_state']??null)!=='not_connected'
    || array_keys($snap['channels']??[])!==['google_ads']
    || ($snap['operational_enabled']??null)!==false
    || ($snap['ads_write_enabled']??null)!==false
    || ($snap['billable']??null)!==false)
    throw new RuntimeException('Paused stage source guard failed closed');
[$initialStatus]=mockStage('GET','/stage-state',$key);
if($argc===4) {
    if(($snap['internal_campaign_draft']??null)!==null || $initialStatus!==404)
        throw new RuntimeException('Pre-draft stage must remain absent');
    echo "PASS F423 PAUSED STAGE PRE-DRAFT: signed approved plan read; absent draft and mock receipt\n";
    exit(0);
}
$draft=$snap['internal_campaign_draft']??null;
$plan=$snap['diagnostic_plan']??null;
if(!is_array($draft)
    || ($draft['state']??null)!=='ci_internal_unpublished'
    || ($draft['published']??null)!==false
    || ($draft['ads_connected']??null)!==false
    || ($draft['provider']??null)!=='google_ads'
    || ($draft['plan_version']??null)!==1
    || ($draft['plan_fingerprint']??null)!==($plan['plan_fingerprint']??null)
    || ($snap['campaign_drafts_created']??null)!==1)
    throw new RuntimeException('Only the exact draft may be staged paused');
$revision=$snap['approved_briefing_revision']??null;
$sha=$snap['approved_briefing_sha256']??null;
$planSha=$plan['plan_fingerprint']??null;
$draftSha=$draft['draft_fingerprint']??null;
if(!is_int($revision) || $revision<1)
    throw new RuntimeException('Missing exact revision');
foreach([$sha,$planSha,$draftSha] as $value)
    if(!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/D',$value))
        throw new RuntimeException('Missing exact digest');
$packet=[
    'schema'=>'f423_paused_stage_ci_v1',
    'company_id'=>$company,'order_id'=>$order,'order_revision'=>$revision,
    'briefing_sha256'=>$sha,'provider'=>'google_ads',
    'plan_fingerprint'=>$planSha,'draft_fingerprint'=>$draftSha,
    'stage_state'=>'ci_staged_paused_not_delivered',
    'activated'=>false,'delivered'=>false,'published'=>false,
    'ads_write_enabled'=>false,'operational_enabled'=>false,'billable'=>false
];
[$forged]=mockStage('POST','/stage',$key,$packet,true);
if($forged!==403) throw new RuntimeException('Forged stage MAC accepted');
[$stored,$response]=mockStage('POST','/stage',$key,$packet);
if($stored!==200 || ($response['stage_count']??null)!==1
    || ($response['activated']??null)!==false || ($response['published']??null)!==false)
    throw new RuntimeException('Paused stage rejected');
[$readStatus,$read]=mockStage('GET','/stage-state',$key);
if($readStatus!==200 || ($read['record']['packet']??null)!==$packet
    || ($read['record']['stage_count']??null)!==1
    || ($read['activated']??null)!==false || ($read['delivered']??null)!==false
    || ($read['published']??null)!==false || ($read['external_ads_calls']??null)!==0)
    throw new RuntimeException('Independent mock paused stage read failed');
foreach([
    ['company_id','demo-'.str_repeat('f',32)],
    ['order_id','TEST-'.str_repeat('f',32)],
    ['order_revision',$revision+1],
    ['briefing_sha256',str_repeat('0',64)],
    ['plan_fingerprint',str_repeat('0',64)],
    ['draft_fingerprint',str_repeat('0',64)],
    ['provider','meta_ads'],
    ['stage_state','ci_activated'],
    ['activated',true],['delivered',true],['published',true],
    ['ads_write_enabled',true],['operational_enabled',true],['billable',true]
] as [$field,$value]) {
    $changed=$packet;$changed[$field]=$value;
    [$bad]=mockStage('POST','/stage',$key,$changed);
    if($bad!==409) throw new RuntimeException('Conflicting paused stage accepted');
}
echo "PASS F423 PAUSED STAGE: same signed Google-only draft stored in second CI HTTP container; replay idempotent, mismatches rejected; not delivered, not activated, no Ads\n";
