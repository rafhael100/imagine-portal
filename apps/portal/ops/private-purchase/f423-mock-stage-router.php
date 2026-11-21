<?php
declare(strict_types=1);
// CI-only, PAUSED delivery state in isolated HTTP mock. This is NOT Ads activation.
if (PHP_SAPI !== 'cli-server' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_PREVIEW_LOOPBACK_TEST') !== '1') {
    http_response_code(404); exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
function stageReply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    exit;
}
$method=$_SERVER['REQUEST_METHOD']??'';
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
if($method==='GET' && $path==='/health') stageReply(200,['ci_only'=>true]);
if(!in_array($path,['/stage','/stage-state'],true)) stageReply(404,['denied'=>true]);
if(($path==='/stage' && $method!=='POST') ||
   ($path==='/stage-state' && $method!=='GET')) stageReply(405,['denied'=>true]);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if(!preg_match('/\A[a-f0-9]{64}\z/D',$key)) stageReply(503,['denied'=>true]);
$body=file_get_contents('php://input');
if(!is_string($body)||strlen($body)>2048) stageReply(413,['denied'=>true]);
$signature=(string)($_SERVER['HTTP_X_F423_MOCK_SIGNATURE']??'');
$expected=hash_hmac('sha256',$method."\n".$path."\n".$body,hex2bin($key));
if(strlen($signature)!==64 || !hash_equals($expected,$signature))
    stageReply(403,['denied'=>true]);
$handle=@fopen('/tmp/f423-paused-stage-state.json','c+');
if($handle===false || !flock($handle,LOCK_EX)) stageReply(503,['denied'=>true]);
$raw=stream_get_contents($handle);
$saved=is_string($raw)&&$raw!==''?json_decode($raw,true):null;
if($method==='GET') {
    if(!is_array($saved)) stageReply(404,['not_found'=>true]);
    stageReply(200,['schema'=>'f423_paused_stage_read_ci_v1','record'=>$saved,
        'activated'=>false,'delivered'=>false,'published'=>false,'external_ads_calls'=>0]);
}
$packet=json_decode($body,true);
$keys=['schema','company_id','order_id','order_revision','briefing_sha256',
    'provider','plan_fingerprint','draft_fingerprint','stage_state',
    'activated','delivered','published','ads_write_enabled','operational_enabled','billable'];
$actual=is_array($packet)?array_keys($packet):[];
sort($keys);sort($actual);
if($actual!==$keys || ($packet['schema']??null)!=='f423_paused_stage_ci_v1'
    || ($packet['provider']??null)!=='google_ads'
    || ($packet['stage_state']??null)!=='ci_staged_paused_not_delivered'
    || ($packet['activated']??null)!==false
    || ($packet['delivered']??null)!==false
    || ($packet['published']??null)!==false
    || ($packet['ads_write_enabled']??null)!==false
    || ($packet['operational_enabled']??null)!==false
    || ($packet['billable']??null)!==false
    || !is_int($packet['order_revision']??null) || $packet['order_revision']<1
    || !is_string($packet['company_id']??null)
    || !preg_match('/\Ademo-[a-f0-9]{32}\z/D',$packet['company_id'])
    || !is_string($packet['order_id']??null)
    || !preg_match('/\ATEST-[a-f0-9]{32}\z/D',$packet['order_id']))
    stageReply(409,['denied'=>true]);
foreach(['briefing_sha256','plan_fingerprint','draft_fingerprint'] as $field)
    if(!is_string($packet[$field]??null)
        || !preg_match('/\A[a-f0-9]{64}\z/D',$packet[$field]))
        stageReply(409,['denied'=>true]);
if(is_array($saved)) {
    if(($saved['packet']??null)!==$packet) stageReply(409,['denied'=>true]);
    stageReply(200,['schema'=>'f423_paused_stage_response_ci_v1',
        'already_staged'=>true,'stage_count'=>1,'activated'=>false,
        'delivered'=>false,'published'=>false,'external_ads_calls'=>0]);
}
$record=['packet'=>$packet,'stage_count'=>1];
rewind($handle);
if(!ftruncate($handle,0)
    || fwrite($handle,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))===false
    || !fflush($handle)) stageReply(503,['denied'=>true]);
stageReply(200,['schema'=>'f423_paused_stage_response_ci_v1',
    'already_staged'=>false,'stage_count'=>1,'activated'=>false,
    'delivered'=>false,'published'=>false,'external_ads_calls'=>0]);
