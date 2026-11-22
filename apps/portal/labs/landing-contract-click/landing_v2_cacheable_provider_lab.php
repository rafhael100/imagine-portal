<?php
declare(strict_types=1);

/**
 * R40: adversarial cache headers on a fictional Landing state/HTML origin.
 * Loopback CI only; no publisher, customer, credentials or external traffic.
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
$mode=getenv('LANDING_R40_CACHE_MODE');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || !in_array($path, ['/ci/state', '/ci/served'], true)
    || !in_array($mode, ['public', 'contradictory'], true)) {
    http_response_code(403);
    exit;
}
header('Content-Type: '.($path === '/ci/state'
    ? 'application/json' : 'text/html; charset=utf-8'));
if ($mode === 'public') {
    header('Cache-Control: public, max-age=600');
} else {
    header('Cache-Control: no-store', false);
    header('Cache-Control: public, max-age=600', false);
}
if ($path === '/ci/state') {
    echo '{"requestRef":"ci-only","state":"APPLIED"}';
} else {
    echo '<!doctype html><html lang="pt-BR"><body>Entrega fictícia sem formulário</body></html>';
}
