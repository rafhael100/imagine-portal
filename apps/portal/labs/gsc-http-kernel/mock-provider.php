<?php
declare(strict_types=1);

// This process is only a synthetic Google substitute bound to loopback.
if (PHP_SAPI!=='cli-server' || getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_HTTP_KERNEL_LAB')!=='1' ||
    ($_SERVER['REMOTE_ADDR']??null)!=='127.0.0.1') {
    http_response_code(404); exit;
}
if (($_SERVER['REQUEST_URI']??null)==='/sites' &&
    ($_SERVER['REQUEST_METHOD']??null)==='GET') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (($_SERVER['HTTP_AUTHORIZATION']??null)!==
        'Bearer synthetic-only-access-token-1234567890') {
        http_response_code(401); echo '{}'; exit;
    }
    echo json_encode(['siteEntry'=>[
        ['siteUrl'=>'sc-domain:example.com','permissionLevel'=>'siteOwner'],
        ['siteUrl'=>'https://restricted.example.com/',
            'permissionLevel'=>'siteRestrictedUser'],
    ]],JSON_THROW_ON_ERROR);
    exit;
}
// All existing malformed/redirect/scope cases remain byte-for-byte F116.
require dirname(__DIR__).'/gsc-f116-local-token/mock-token-endpoint.php';
