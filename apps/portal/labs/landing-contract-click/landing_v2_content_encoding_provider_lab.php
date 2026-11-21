<?php
declare(strict_types=1);

/**
 * R39 negative-only origin. Sends deliberately uncompressed bytes while
 * advertising gzip/br, so a byte digest alone cannot prove that the browser
 * would display the exact entity the server-side reader observed.
 * Loopback CI only: no real releases, data, credentials, or external network.
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
$encoding=getenv('LANDING_R39_ENCODING');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || !in_array($path, ['/ci/state', '/ci/served'], true)
    || !in_array($encoding, ['gzip', 'br'], true)) {
    http_response_code(403);
    exit;
}
header('Cache-Control: no-store');
header('Content-Encoding: '.$encoding);
header('Content-Type: '.($path === '/ci/state'
    ? 'application/json' : 'text/html; charset=utf-8'));
if ($path === '/ci/state') {
    echo '{"requestRef":"ci-only","state":"APPLIED"}';
} else {
    echo '<!doctype html><html lang="pt-BR"><body>Prévia sintética sem formulário</body></html>';
}
