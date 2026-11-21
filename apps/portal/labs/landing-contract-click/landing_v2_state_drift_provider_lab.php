<?php
declare(strict_types=1);

/**
 * R38 disposable adversarial origin: after serving the exact, approved v2
 * HTML, the provider's next state GET is no longer APPLIED. This is a
 * deliberately inconsistent CI-only source, not a publisher or product API.
 */
if (PHP_SAPI !== 'cli-server'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('LANDING_R38_STATE_DRIFT_LAB') !== '1'
    || (int)($_SERVER['SERVER_PORT'] ?? 0) !== 8791
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(404); exit;
}
define('LANDING_R27_READ_ONLY_LIBRARY', true);
require_once __DIR__.'/landing_v2_mtls_release_lab.php';

$path=parse_url((string)($_SERVER['REQUEST_URI'] ?? ''),PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(403); exit;
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($path === '/ci/r38-ready') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "CI_ONLY_STATE_DRIFT_READY\n"; exit;
}
if ($path !== '/ci/state' && $path !== '/ci/served') {
    http_response_code(403); exit;
}
if ($path === '/ci/state'
    && ($_GET ?? []) !== [
        'tenantRef'=>R26_TENANT,
        'orderRef'=>R26_ORDER,
        'releaseRef'=>R26_RELEASE,
    ]) {
    http_response_code(403); exit;
}
if ($path === '/ci/served' && ($_GET ?? []) !== []) {
    http_response_code(403); exit;
}

// Read only through the existing CI's separate, FORCE-RLS provider role.
$q=r26db('landing_r28_reader','ci-synthetic-not-operational')->prepare(<<<'SQL'
SELECT tenant_ref,order_ref,release_ref,request_ref,html_sha256,
       deadline_epoch,delivery_state,side_effects,html_bytes
FROM landing_ack_r28.release_state
WHERE tenant_ref=? AND order_ref=? AND request_ref=?
SQL);
$q->execute([R26_TENANT,R26_ORDER,'ci-landing-v2-probe-001']);
$rows=$q->fetchAll(PDO::FETCH_ASSOC);
if (count($rows)!==1 || $rows[0]['delivery_state']!=='APPLIED'
    || (int)$rows[0]['side_effects']!==1) {
    http_response_code(403); exit;
}
$r=$rows[0];
if ($path==='/ci/served') {
    if (!is_string($r['html_bytes'])
        || !hash_equals((string)$r['html_sha256'],hash('sha256',$r['html_bytes']))) {
        http_response_code(403); exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $r['html_bytes']; exit;
}
// One server process, strictly serial PHP built-in HTTP requests. No
// external inputs may select the state or a filesystem path.
$counter=sys_get_temp_dir().'/landing-r38-ci-state-counter';
$previous=@file_get_contents($counter);
if (!is_string($previous) || preg_match('/\A[0-9]{1,3}\z/D',$previous)!==1) {
    http_response_code(403); exit;
}
$count=(int)$previous;
if ($count>1 || file_put_contents($counter,(string)($count+1),LOCK_EX)===false) {
    http_response_code(403); exit;
}
header('Content-Type: application/json');
echo json_encode([
    'requestRef'=>(string)$r['request_ref'],
    'tenantRef'=>(string)$r['tenant_ref'],
    'orderRef'=>(string)$r['order_ref'],
    'releaseRef'=>(string)$r['release_ref'],
    'artifactDigest'=>(string)$r['html_sha256'],
    'deadlineEpoch'=>(int)$r['deadline_epoch'],
    'state'=>$count===0?'APPLIED':'PENDING',
    'sideEffects'=>(int)$r['side_effects'],
], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
