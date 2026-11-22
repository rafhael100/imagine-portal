<?php
declare(strict_types=1);

/**
 * R41 adversarial HTTP origin, solely for GH Actions loopback testing.
 * Correct status/MIME/cache headers and inert bytes, but a CSP or XFO
 * directive could alter the browser's presentation of the same HTML.
 * No database, customer identity, public serving or outgoing connection.
 */
if (PHP_SAPI !== 'cli-server'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('LANDING_R29_DELIVERY_LAB') !== '1'
    || getenv('LANDING_R41_DISPLAY_LAB') !== '1'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(404); exit;
}
$path=parse_url((string)($_SERVER['REQUEST_URI'] ?? ''),PHP_URL_PATH);
$mode=getenv('LANDING_R41_DISPLAY_MODE');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || !in_array($path,['/ci/state','/ci/served'],true)
    || !in_array($mode,['sandbox','frame-deny','clean'],true)) {
    http_response_code(403); exit;
}
header('Cache-Control: no-store');
header('Content-Type: '.($path === '/ci/state'
    ? 'application/json' : 'text/html; charset=utf-8'));
if ($mode === 'sandbox') {
    header('Content-Security-Policy: sandbox');
} else if ($mode === 'frame-deny') {
    header('X-Frame-Options: DENY');
}
if ($path === '/ci/state') {
    echo '{"requestRef":"synthetic-only","state":"APPLIED"}';
} else {
    echo '<!doctype html><html lang="pt-BR"><body>Somente observacao local, sem publicacao.</body></html>';
}
