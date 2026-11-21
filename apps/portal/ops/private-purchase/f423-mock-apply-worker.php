<?php
declare(strict_types=1);
// CI-only: read approved exact Google-only proposal by S2S, then invoke ONLY
// the mock service on the internal Docker network. No real Ads account or API.
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_PREVIEW_LOOPBACK_TEST') !== '1' || $argc !== 3) exit(78);
[$script,$company,$order] = $argv;
if (preg_match('/\Ademo-[a-f0-9]{32}\z/D',$company) !== 1
    || preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order) !== 1) exit(78);
$key = trim((string) @file_get_contents('/run/secrets/f423-performance-key'));
if (preg_match('/\A[a-f0-9]{64}\z/D',$key) !== 1) exit(78);
function jsonHttp(string $url,string $method,array $headers,?array $packet=null): array {
    $body = $packet === null ? '' : json_encode($packet,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $headers[] = 'Content-Type: application/json';
    $context = stream_context_create(['http'=>['method'=>$method,
        'header'=>implode("\r\n",$headers),'content'=>$body,
        'timeout'=>8,'ignore_errors'=>true]]);
    $raw = @file_get_contents($url,false,$context);
    $status=(int) preg_replace('/^HTTP\/\S+\s+([0-9]+).*$/','$1',
        $http_response_header[0] ?? 'HTTP/1.1 0');
    return [$status,is_string($raw) ? json_decode($raw,true) : null];
}
$path='/_preview/portal/lab-s2s/orders/'.$order;
$timestamp=(string) time();
$nonce=bin2hex(random_bytes(16));
$signature=hash_hmac('sha256',"GET\n".$path."\n".$company."\n".
    $timestamp."\n".$nonce."\n".hash('sha256',''),hex2bin($key));
[$status,$snap]=jsonHttp('http://application:8080'.$path,'GET',[
    'X-F423-Company: '.$company,'X-F423-Timestamp: '.$timestamp,
    'X-F423-Nonce: '.$nonce,'X-F423-Signature: '.$signature]);
if ($status!==200 || !is_array($snap)
    || ($snap['company_id'] ?? null)!==$company
    || ($snap['order_id'] ?? null)!==$order
    || ($snap['purchase_state'] ?? null)!=='synthetic_confirmed'
    || ($snap['briefing_review_state'] ?? null)!=='synthetic_buyer_approved'
    || ($snap['plan_approved'] ?? null)!==true
    || ($snap['internal_campaign_draft']['state'] ?? null)!=='ci_internal_unpublished'
    || ($snap['improvement_proposal']['action'] ?? null)!=='audit_search_terms_offline_simulation'
    || ($snap['improvement_proposal']['separate_approval_recorded'] ?? null)!==true
    || ($snap['improvement_proposal']['applied'] ?? null)!==false
    || ($snap['offline_improvement_simulation']['simulated_application_recorded'] ?? null)!==true
    || ($snap['offline_improvement_simulation']['published'] ?? null)!==false
    || ($snap['operational_enabled'] ?? null)!==false
    || ($snap['ads_write_enabled'] ?? null)!==false)
    throw new RuntimeException('Approved exact synthetic order cannot be read');
$proposal=$snap['improvement_proposal'];
$offline=$snap['offline_improvement_simulation'];
$packet=[
    'schema'=>'f423_mock_provider_apply_ci_v1',
    'company_id'=>$company,'order_id'=>$order,
    'order_revision'=>$snap['approved_briefing_revision'],
    'briefing_sha256'=>$snap['approved_briefing_sha256'],
    'provider'=>'google_ads',
    'proposal_fingerprint'=>$proposal['proposal_fingerprint'],
    'result_fingerprint'=>$offline['result_fingerprint'],
    'action'=>$proposal['action'],
    'published'=>false,'ads_write_enabled'=>false,
    'operational_enabled'=>false,'billable'=>false,
];
function mockHttp(string $method,string $path,string $key,?array $packet=null,
    bool $badSig=false): array {
    $body=$packet===null ? '' : json_encode($packet,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $signature=hash_hmac('sha256',$method."\n".$path."\n".$body,hex2bin($key));
    if($badSig)$signature=str_repeat('0',64);
    return jsonHttp('http://f423-mock-ads:8099'.$path,$method,
        ['X-F423-Mock-Signature: '.$signature],$packet);
}
[$badCode]=mockHttp('POST','/apply',$key,$packet,true);
if($badCode!==403)throw new RuntimeException('Mock accepted invalid signature');
[$applied,$result]=mockHttp('POST','/apply',$key,$packet);
if($applied!==200 || ($result['apply_count'] ?? null)!==1
    || ($result['published'] ?? null)!==false
    || ($result['external_ads_calls'] ?? null)!==0)
    throw new RuntimeException('Mock application failed');
[$readStatus,$read]=mockHttp('GET','/state',$key);
if($readStatus!==200 || ($read['state']['packet'] ?? null)!==$packet
    || ($read['state']['apply_count'] ?? null)!==1
    || ($read['state']['applied_in_isolated_mock'] ?? null)!==true
    || ($read['published'] ?? null)!==false
    || ($read['external_ads_calls'] ?? null)!==0)
    throw new RuntimeException('Independent mock readback failed');
foreach([
    ['company_id','demo-'.str_repeat('f',32)],
    ['order_revision',$packet['order_revision']+1],
    ['briefing_sha256',str_repeat('0',64)],
    ['provider','meta_ads'],['proposal_fingerprint',str_repeat('0',64)],
    ['action','publish_campaign'],['published',true],
    ['ads_write_enabled',true],['billable',true],
] as [$field,$value]) {
    $changed=$packet; $changed[$field]=$value;
    [$denied]=mockHttp('POST','/apply',$key,$changed);
    if($denied!==409)throw new RuntimeException('Mock accepted conflicting payload');
}
echo "PASS F423 MOCK APPLY: approved exact S2S proposal, local HTTP mock applied once, separate process replay, invalid/mismatched inputs denied, no Ads calls\n";
