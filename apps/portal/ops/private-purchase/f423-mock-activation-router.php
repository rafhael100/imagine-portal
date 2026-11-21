<?php
declare(strict_types=1);
/**
 * CI-ONLY independent HTTP activation EXERCISE. This is not activation with
 * Google/Meta, a consumer account grant, delivery, publication or billing.
 * Requires a live authenticated PAUSED state from the OTHER mock before
 * recording one synthetic state transition. Neither mock has external ports.
 */
if (PHP_SAPI !== 'cli-server' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_PREVIEW_LOOPBACK_TEST') !== '1') {
    http_response_code(404); exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
function activationReply(int $status, array $reply): never {
    http_response_code($status);
    echo json_encode($reply, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}
$method=$_SERVER['REQUEST_METHOD']??'';
$path=parse_url($_SERVER['REQUEST_URI']??'', PHP_URL_PATH);
if ($method==='GET' && $path==='/health') activationReply(200,['ci_only'=>true]);
if (!in_array($path,['/exercise','/exercise-state'],true)) activationReply(404,['denied'=>true]);
if (($path==='/exercise' && $method!=='POST') ||
    ($path==='/exercise-state' && $method!=='GET')) activationReply(405,['denied'=>true]);
$key=trim((string)@file_get_contents('/run/secrets/f423-performance-key'));
if (!preg_match('/\A[a-f0-9]{64}\z/D',$key)) activationReply(503,['denied'=>true]);
$body=file_get_contents('php://input');
if (!is_string($body) || strlen($body)>2048) activationReply(413,['denied'=>true]);
$signature=(string)($_SERVER['HTTP_X_F423_MOCK_SIGNATURE']??'');
$expectedSig=hash_hmac('sha256',$method."\n".$path."\n".$body,hex2bin($key));
if (strlen($signature)!==64 || !hash_equals($expectedSig,$signature))
    activationReply(403,['denied'=>true]);

// Independently consult the existing PAUSED state by HTTP: never trust a
// worker-supplied stage receipt, consent flag, account ID or operational grant.
$getSig=hash_hmac('sha256',"GET\n/stage-state\n",hex2bin($key));
$context=stream_context_create(['http'=>[
    'method'=>'GET','header'=>"X-F423-Mock-Signature: ".$getSig."\r\n",
    'timeout'=>3,'ignore_errors'=>true,'follow_location'=>0]]);
$raw=@file_get_contents('http://f423-mock-stage:8100/stage-state',false,$context);
$status=0;
if (isset($http_response_header[0]) &&
    preg_match('/\AHTTP\/\S+\s+([0-9]{3})/',$http_response_header[0],$m))
    $status=(int)$m[1];
if ($status===404) activationReply($method==='GET'?404:409,['denied'=>true]);
if ($status!==200 || !is_string($raw) || strlen($raw)>2048)
    activationReply(503,['denied'=>true]);
try { $stage=json_decode($raw,true,12,JSON_THROW_ON_ERROR); }
catch (Throwable) { activationReply(503,['denied'=>true]); }
$record=is_array($stage)?($stage['record']??null):null;
$paused=is_array($record)?($record['packet']??null):null;
if (!is_array($paused) || ($record['stage_count']??null)!==1 ||
    ($stage['schema']??null)!=='f423_paused_stage_read_ci_v1' ||
    ($stage['activated']??null)!==false ||
    ($stage['delivered']??null)!==false ||
    ($stage['published']??null)!==false ||
    ($stage['external_ads_calls']??null)!==0 ||
    ($paused['schema']??null)!=='f423_paused_stage_ci_v1' ||
    ($paused['provider']??null)!=='google_ads' ||
    ($paused['stage_state']??null)!=='ci_staged_paused_not_delivered' ||
    ($paused['activated']??null)!==false ||
    ($paused['delivered']??null)!==false ||
    ($paused['published']??null)!==false ||
    ($paused['ads_write_enabled']??null)!==false ||
    ($paused['operational_enabled']??null)!==false ||
    ($paused['billable']??null)!==false)
    activationReply(409,['denied'=>true]);
$pausedDigest=hash('sha256',json_encode($paused,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
$handle=@fopen('/tmp/f423-activation-exercise.json','c+');
if ($handle===false || !flock($handle,LOCK_EX)) activationReply(503,['denied'=>true]);
$savedRaw=stream_get_contents($handle);
$saved=is_string($savedRaw)&&$savedRaw!==''?json_decode($savedRaw,true):null;
if ($method==='GET') {
    if (!is_array($saved)) activationReply(404,['not_found'=>true]);
    if (($saved['packet']['stage_sha256']??null)!==$pausedDigest)
        activationReply(409,['denied'=>true]);
    activationReply(200,['schema'=>'f423_mock_activation_read_ci_v1','record'=>$saved,
        'simulator_activated'=>true,'provider_activated'=>false,
        'delivered'=>false,'published'=>false,'billable'=>false,
        'external_ads_calls'=>0]);
}
try { $packet=json_decode($body,true,12,JSON_THROW_ON_ERROR); }
catch (Throwable) { activationReply(409,['denied'=>true]); }
$allowed=['schema','fixture_decision','company_id','order_id','order_revision',
    'briefing_sha256','provider','plan_fingerprint','draft_fingerprint',
    'stage_sha256','simulator_action','external_ads_allowed','provider_activated',
    'delivered','published','ads_write_enabled','operational_enabled','billable'];
$keys=is_array($packet)?array_keys($packet):[];
sort($allowed); sort($keys);
if ($keys!==$allowed || ($packet['schema']??null)!=='f423_mock_activation_ci_v1'
    || ($packet['fixture_decision']??null)!=='ci_explicit_exact_draft_exercise_only'
    || ($packet['simulator_action']??null)!=='exercise_mock_activation'
    || ($packet['stage_sha256']??null)!==$pausedDigest
    || ($packet['external_ads_allowed']??null)!==false
    || ($packet['provider_activated']??null)!==false
    || ($packet['delivered']??null)!==false
    || ($packet['published']??null)!==false
    || ($packet['ads_write_enabled']??null)!==false
    || ($packet['operational_enabled']??null)!==false
    || ($packet['billable']??null)!==false)
    activationReply(409,['denied'=>true]);
foreach (['company_id','order_id','order_revision','briefing_sha256',
          'provider','plan_fingerprint','draft_fingerprint'] as $field)
    if (!array_key_exists($field,$packet)
        || !array_key_exists($field,$paused)
        || $packet[$field]!==$paused[$field])
        activationReply(409,['denied'=>true]);
if (is_array($saved)) {
    if (($saved['packet']??null)!==$packet) activationReply(409,['denied'=>true]);
    activationReply(200,['schema'=>'f423_mock_activation_response_ci_v1',
        'already_exercised'=>true,'exercise_count'=>1,
        'simulator_activated'=>true,'provider_activated'=>false,
        'delivered'=>false,'published'=>false,'billable'=>false,
        'external_ads_calls'=>0]);
}
$new=['packet'=>$packet,'exercise_count'=>1,
    'state'=>'ci_mock_activation_exercised_not_delivered'];
rewind($handle);
$serialized=json_encode($new,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
if (!ftruncate($handle,0) || fwrite($handle,$serialized)!==strlen($serialized)
    || !fflush($handle)) activationReply(503,['denied'=>true]);
activationReply(200,['schema'=>'f423_mock_activation_response_ci_v1',
    'already_exercised'=>false,'exercise_count'=>1,
    'simulator_activated'=>true,'provider_activated'=>false,
    'delivered'=>false,'published'=>false,'billable'=>false,
    'external_ads_calls'=>0]);
