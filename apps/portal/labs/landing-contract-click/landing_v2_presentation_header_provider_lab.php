<?php
declare(strict_types=1);

/**
 * R37 negative-only origin. HTTP 200 and correct MIME can still carry
 * presentation-changing headers: download attachment or client-side Refresh.
 * Loopback only; no credentials, operational routes, or external egress.
 */
if (PHP_SAPI !== 'cli-server'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('LANDING_R29_DELIVERY_LAB') !== '1'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$path=parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || !in_array($path, ['/ci/state','/ci/served'], true)) {
    http_response_code(403);
    exit;
}
$control=getenv('LANDING_R37_CONTROL');
if (!in_array($control, ['attachment','refresh'], true)) {
    http_response_code(403);
    exit;
}
header('Cache-Control: no-store');
header('Content-Type: '.($path === '/ci/state'
    ? 'application/json' : 'text/html; charset=utf-8'));
if ($control === 'attachment') {
    header('Content-Disposition: attachment; filename="synthetic-only.txt"');
} else {
    header('Refresh: 0; url=/ci/other');
}
if ($path === '/ci/state') {
    echo '{"requestRef":"ci-only","state":"APPLIED"}';
} else {
    echo '<!doctype html><html lang="pt-BR"><body>Fixture privada de teste, sem formulário</body></html>';
}
