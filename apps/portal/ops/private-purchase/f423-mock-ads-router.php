<?php
declare(strict_types=1);
// CI-only isolated HTTP simulator. NOT Google/Meta, no credentials or advertising API.
if (PHP_SAPI !== 'cli-server' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_PREVIEW_LOOPBACK_TEST') !== '1') {
    http_response_code(404); exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
function reply(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($method === 'GET' && $path === '/health') reply(200, ['ci_only'=>true]);
if (!in_array($path, ['/apply','/state'], true)
    || !in_array($method, ['GET','POST'], true)) reply(404, ['denied'=>true]);
$key = trim((string) @file_get_contents('/run/secrets/f423-performance-key'));
if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) reply(503, ['denied'=>true]);
$body = file_get_contents('php://input');
if (!is_string($body) || strlen($body) > 2048) reply(413, ['denied'=>true]);
$sig = (string) ($_SERVER['HTTP_X_F423_MOCK_SIGNATURE'] ?? '');
$correct = hash_hmac('sha256', $method."\n".$path."\n".$body, hex2bin($key));
if (strlen($sig) !== 64 || !hash_equals($correct, $sig)) reply(403, ['denied'=>true]);
if (($method === 'GET' && $path !== '/state')
    || ($method === 'POST' && $path !== '/apply')) reply(405, ['denied'=>true]);
$handle = @fopen('/tmp/f423-mock-provider-state.json','c+');
if ($handle === false || !flock($handle,LOCK_EX)) reply(503, ['denied'=>true]);
$existing = stream_get_contents($handle);
$saved = is_string($existing) && $existing !== '' ? json_decode($existing,true) : null;
if ($method === 'GET') {
    if (!is_array($saved)) reply(404, ['not_found'=>true]);
    reply(200, ['schema'=>'f423_mock_provider_read_v1','state'=>$saved,
        'published'=>false,'external_ads_calls'=>0]);
}
$packet = json_decode($body,true);
$keys = ['schema','company_id','order_id','order_revision','briefing_sha256',
    'provider','proposal_fingerprint','result_fingerprint','action',
    'published','ads_write_enabled','operational_enabled','billable'];
sort($keys);
$actual = is_array($packet) ? array_keys($packet) : [];
sort($actual);
if ($keys !== $actual
    || ($packet['schema'] ?? null) !== 'f423_mock_provider_apply_ci_v1'
    || ($packet['provider'] ?? null) !== 'google_ads'
    || ($packet['action'] ?? null) !== 'audit_search_terms_offline_simulation'
    || ($packet['published'] ?? null) !== false
    || ($packet['ads_write_enabled'] ?? null) !== false
    || ($packet['operational_enabled'] ?? null) !== false
    || ($packet['billable'] ?? null) !== false
    || !is_int($packet['order_revision'] ?? null) || $packet['order_revision'] < 1
    || preg_match('/\Ademo-[a-f0-9]{32}\z/D',$packet['company_id'] ?? '') !== 1
    || preg_match('/\ATEST-[a-f0-9]{32}\z/D',$packet['order_id'] ?? '') !== 1) {
    reply(409, ['denied'=>true]);
}
foreach (['briefing_sha256','proposal_fingerprint','result_fingerprint'] as $field) {
    if (!is_string($packet[$field] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D',$packet[$field]) !== 1)
        reply(409, ['denied'=>true]);
}
if (is_array($saved)) {
    if ($saved['packet'] !== $packet) reply(409,['denied'=>true]);
    reply(200,['schema'=>'f423_mock_provider_apply_v1','already_applied'=>true,
        'apply_count'=>1,'published'=>false,'external_ads_calls'=>0]);
}
$record = ['packet'=>$packet,'apply_count'=>1,'applied_in_isolated_mock'=>true];
rewind($handle);
if (!ftruncate($handle,0)
    || fwrite($handle,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)) === false
    || !fflush($handle)) reply(503,['denied'=>true]);
reply(200,['schema'=>'f423_mock_provider_apply_v1','already_applied'=>false,
    'apply_count'=>1,'published'=>false,'external_ads_calls'=>0]);
