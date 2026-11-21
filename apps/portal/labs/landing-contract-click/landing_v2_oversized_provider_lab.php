<?php
declare(strict_types=1);

/**
 * R33 CI-only adverse provider: fixed loopback responses deliberately exceed
 * transport budgets. Not an operational endpoint, publisher, or trusted source.
 */
if (PHP_SAPI !== 'cli-server'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('LANDING_R29_DELIVERY_LAB') !== '1'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(403);
    exit;
}
if ($path === '/ci/state'
    && ($_GET ?? []) === [
        'tenantRef' => 'tenant-A',
        'orderRef' => 'landing-order-001',
        'releaseRef' => 'landing-v2-generation-2-ci',
    ]) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo str_repeat('X', 12288);
    exit;
}
if ($path === '/ci/served' && ($_GET ?? []) === []) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo str_repeat('Y', 65536);
    exit;
}
http_response_code(403);
exit;
