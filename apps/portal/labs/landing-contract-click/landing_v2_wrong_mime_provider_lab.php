<?php
declare(strict_types=1);

/**
 * R36 negative-only loopback provider: HTTP 200 with short, harmless bodies,
 * but the wrong media type. No schema writer, publication, secret, or egress.
 */
if (PHP_SAPI !== 'cli-server' || getenv('GITHUB_ACTIONS') !== 'true'
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
header('Cache-Control: no-store');
if (getenv('LANDING_R36_DUPLICATE') === '1') {
    // The first MIME is correct but a conflicting second value makes the
    // final response ambiguous. This only runs in a disposable PHP server.
    header('Content-Type: '.($path === '/ci/state'
        ? 'application/json' : 'text/html; charset=utf-8'));
    header('Content-Type: text/plain', false);
} else {
    header('Content-Type: text/plain');
}
if ($path === '/ci/state') {
    echo '{"requestRef":"ci-only","state":"APPLIED"}';
} else {
    echo '<!doctype html><html><body>Fixture local fictícia sem formulário</body></html>';
}
