<?php
declare(strict_types=1);
/**
 * CI-only signed S2S worker: a separate exercise in the HTTP simulator.
 * Explicit test fixture decision is NOT consent or operational authority.
 * Does not access a Google/Meta account, run an ad, publish, deliver or bill.
 */
if (getenv('GITHUB_ACTIONS')!=='true'
    || getenv('PORTAL_PREVIEW_LOOPBACK_TEST')!=='1'
    || !in_array($argc,[3,4],true)
    || ($argc===4 && ($argv[3]??'')!=='--expect-no-stage')) exit(78);
[$script,$company,$order]=$argv;
if (!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company)
    || !preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)) exit(78);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if (!preg_match('/\A[a-f0-9]{64}\z/D',$key)) exit(78);
function activationHttp(string $host,string $method,string $path,array $headers,
                        ?array $packet=null): array {
    $body=$packet===null?'':json_encode($packet,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $headers[]='Content-Type: application/json';
    $ctx=stream_context_create(['http'=>[
        'method'=>$method,'content'=>$body,'header'=>implode("\r\n",$headers),
        'timeout'=>8,'ignore_errors'=>true,'follow_location'=>0]]);
    $raw=@file_get_contents('http://'.$host.$path,false,$ctx);
    $status=0;
    if (isset($http_response_header[0]) &&
        preg_match('/\AHTTP\/\S+\s+([0-9]{3})/',$http_response_header[0],$m))
        $status=(int)$m[1];
    return [$status,is_string($raw)?json_decode($raw,true):null];
}
function activationMock(string $method,string $path,string $key,
                        ?array $packet=null,bool $forge=false): array {
    $body=$packet===null?'':json_encode($packet,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $sig=hash_hmac('sha256',$method."\n".$path."\n".$body,hex2bin($key));
    if ($forge) $sig=str_repeat('0',64);
    return activationHttp('f423-mock-activation:8101',$method,$path,
        ['X-F423-Mock-Signature: '.$sig],$packet);
}
function stageRead(string $key): array {
    $sig=hash_hmac('sha256',"GET\n/stage-state\n",hex2bin($key));
    return activationHttp('f423-mock-stage:8100','GET','/stage-state',
        ['X-F423-Mock-Signature: '.$sig]);
}
// Retrieve the real fictitious purchase and buyer-approved exact plan/draft
// from Portal, over its authenticated CI S2S contract (no SQL or browser).
$path='/_preview/portal/lab-s2s/orders/'.$order;
$ts=(string)time(); $nonce=bin2hex(random_bytes(16));
$sig=hash_hmac('sha256',"GET\n".$path."\n".$company."\n".$ts."\n".$nonce."\n".
    hash('sha256',''),hex2bin($key));
[$code,$snap]=activationHttp('application:8080','GET',$path,[
    'X-F423-Company: '.$company,'X-F423-Timestamp: '.$ts,
    'X-F423-Nonce: '.$nonce,'X-F423-Signature: '.$sig]);
if ($code!==200 || !is_array($snap)
    || ($snap['company_id']??null)!==$company || ($snap['order_id']??null)!==$order
    || ($snap['purchase_state']??null)!=='synthetic_confirmed'
    || ($snap['briefing_review_state']??null)!=='synthetic_buyer_approved'
    || ($snap['performance_s2s_proven']??null)!==true
    || ($snap['plan_approved']??null)!==true
    || ($snap['diagnostic_plan']['buyer_plan_approved']??null)!==true
    || array_keys($snap['channels']??[])!==['google_ads']
    || ($snap['channels']['google_ads']['decision_state']??null)!=='internal_review_only'
    || ($snap['channels']['google_ads']['connection_state']??null)!=='not_connected'
    || ($snap['operational_enabled']??null)!==false
    || ($snap['ads_write_enabled']??null)!==false
    || ($snap['billable']??null)!==false)
    throw new RuntimeException('Mock activation: signed Portal fixture unavailable');
[$pausedCode,$paused]=stageRead($key);
if ($argc===4) {
    if ($pausedCode!==404 || ($snap['internal_campaign_draft']??null)===null)
        throw new RuntimeException('Mock activation must be denied before stage');
    [$getCode]=activationMock('GET','/exercise-state',$key);
    if ($getCode!==404) throw new RuntimeException('Pre-stage simulated activation exists');
    echo "PASS F423 MOCK ACTIVATION PRE-STAGE: draft exists; no staged receipt, no transition\n";
    exit(0);
}
$draft=$snap['internal_campaign_draft']??null;
$plan=$snap['diagnostic_plan']??null;
$stagePacket=$paused['record']['packet']??null;
if ($pausedCode!==200 || !is_array($draft) || !is_array($plan)
    || !is_array($stagePacket)
    || ($paused['record']['stage_count']??null)!==1
    || ($paused['activated']??null)!==false
    || ($paused['delivered']??null)!==false
    || ($paused['external_ads_calls']??null)!==0
    || ($draft['state']??null)!=='ci_internal_unpublished'
    || ($draft['provider']??null)!=='google_ads'
    || ($draft['published']??null)!==false
    || ($draft['ads_connected']??null)!==false
    || ($draft['plan_fingerprint']??null)!==($plan['plan_fingerprint']??null)
    || ($snap['campaign_drafts_created']??null)!==1)
    throw new RuntimeException('Mock activation requires the exact paused internal draft');
$exact=[
    'company_id'=>$company, 'order_id'=>$order,
    'order_revision'=>$snap['approved_briefing_revision']??null,
    'briefing_sha256'=>$snap['approved_briefing_sha256']??null,
    'provider'=>'google_ads',
    'plan_fingerprint'=>$plan['plan_fingerprint']??null,
    'draft_fingerprint'=>$draft['draft_fingerprint']??null
];
foreach ($exact as $field=>$value)
    if (!array_key_exists($field,$stagePacket) || $stagePacket[$field]!==$value)
        throw new RuntimeException('Mock activation paused/signed order mismatch');
$packet=[
    'schema'=>'f423_mock_activation_ci_v1',
    'fixture_decision'=>'ci_explicit_exact_draft_exercise_only',
    ...$exact,
    'stage_sha256'=>hash('sha256',json_encode($stagePacket,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),
    'simulator_action'=>'exercise_mock_activation',
    'external_ads_allowed'=>false,
    'provider_activated'=>false,'delivered'=>false,'published'=>false,
    'ads_write_enabled'=>false,'operational_enabled'=>false,'billable'=>false
];
[$forged]=activationMock('POST','/exercise',$key,$packet,true);
if ($forged!==403) throw new RuntimeException('Mock accepted forged HMAC');
foreach ([
    ['company_id','demo-'.str_repeat('f',32)],
    ['order_id','TEST-'.str_repeat('f',32)],
    ['order_revision',$exact['order_revision']+1],
    ['briefing_sha256',str_repeat('0',64)],
    ['plan_fingerprint',str_repeat('0',64)],
    ['draft_fingerprint',str_repeat('0',64)],
    ['provider','meta_ads'],
    ['stage_sha256',str_repeat('0',64)],
    ['fixture_decision','operational_consent'],
    ['simulator_action','publish_campaign'],
    ['external_ads_allowed',true],
    ['provider_activated',true],['delivered',true],['published',true],
    ['ads_write_enabled',true],['operational_enabled',true],['billable',true]
] as [$field,$value]) {
    $bad=$packet; $bad[$field]=$value;
    [$denied]=activationMock('POST','/exercise',$key,$bad);
    if ($denied!==409) throw new RuntimeException('Mock accepted mismatch: '.$field);
}
[$postCode,$post]=activationMock('POST','/exercise',$key,$packet);
if ($postCode!==200 || ($post['exercise_count']??null)!==1
    || ($post['simulator_activated']??null)!==true
    || ($post['provider_activated']??null)!==false
    || ($post['delivered']??null)!==false
    || ($post['published']??null)!==false
    || ($post['billable']??null)!==false
    || ($post['external_ads_calls']??null)!==0)
    throw new RuntimeException('Mock exercise did not persist one synthetic transition');
[$readCode,$read]=activationMock('GET','/exercise-state',$key);
if ($readCode!==200
    || ($read['schema']??null)!=='f423_mock_activation_read_ci_v1'
    || ($read['record']['packet']??null)!==$packet
    || ($read['record']['exercise_count']??null)!==1
    || ($read['record']['state']??null)!=='ci_mock_activation_exercised_not_delivered'
    || ($read['simulator_activated']??null)!==true
    || ($read['provider_activated']??null)!==false
    || ($read['delivered']??null)!==false
    || ($read['published']??null)!==false
    || ($read['external_ads_calls']??null)!==0)
    throw new RuntimeException('Independent mock activation readback mismatch');
[$stillPausedCode,$stillPaused]=stageRead($key);
if ($stillPausedCode!==200 || ($stillPaused['record']['packet']??null)!==$stagePacket
    || ($stillPaused['activated']??null)!==false
    || ($stillPaused['delivered']??null)!==false)
    throw new RuntimeException('Mock activation modified paused stage');
echo "PASS F423 MOCK-ONLY ACTIVATION: exactly one independent HTTP exercise; signed fixed draft/fixture, replay-safe, paused unchanged; zero Ads/delivery/billing\n";
