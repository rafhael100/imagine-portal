<?php

declare(strict_types=1);

// F120: THIS FILE IS A PHP BUILT-IN TEST SERVER ROUTER ONLY.
// Never register in Laravel, reverse proxy, production or public web server.
require_once __DIR__ . '/../commercial-intake-f119/PortalF119SyntheticServiceReservation.php';

use Imagine\Portal\Labs\CommercialIntakeF119\PortalF119SyntheticServiceReservation as Reservation;

function respond(int $status, string $reason): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'schema' => 'imagine.portal.synthetic-loopback-http.v1',
        'reasonCode' => $reason,
        'mode' => 'TEST_ONLY_LOOPBACK_NOT_A_CRM_ACK',
        'canAcceptVisitorData' => false,
        'tenantAuthenticatedForProduction' => false,
        'crmPersisted' => false,
        'canAcknowledgeVisitor' => false,
        'canDeliverToCrm' => false,
        'productionAuthorized' => false,
    ], JSON_THROW_ON_ERROR);
}
function parseSeconds(mixed $value): ?int
{
    if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,11}\z/D', $value) !== 1) {
        return null;
    }
    $number = (int)$value;
    return (string)$number === $value ? $number : null;
}

// All constraints are mandatory, even when an internal caller knows the
// synthetic HMAC key. The role impersonation and DB credentials are CI only.
if (PHP_SAPI !== 'cli-server'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('PORTAL_F119_PG_LAB') !== '1'
    || getenv('PORTAL_F120_HTTP_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'portal_commercial_lab'
    // PHP's CLI built-in router does not always populate SERVER_ADDR.
    // When present it must be IPv4 loopback; all client sockets must be local.
    || (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1')
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    respond(503, 'LOOPBACK_CI_REQUIRED');
    return;
}
if (($_SERVER['REQUEST_URI'] ?? '') !== '/__lab/comercial/internal-intake'
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(404, 'NO_PUBLIC_ROUTE');
    return;
}
if (($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/json') {
    respond(415, 'JSON_CONTENT_TYPE_REQUIRED');
    return;
}
$length = $_SERVER['CONTENT_LENGTH'] ?? null;
if ($length !== null
    && (!is_string($length) || !ctype_digit($length)
        || (int)$length > 2048)) {
    respond(413, 'SYNTHETIC_METADATA_TOO_LARGE');
    return;
}
$raw = file_get_contents('php://input', false, null, 0, 2049);
if (!is_string($raw) || strlen($raw) > 2048) {
    respond(413, 'SYNTHETIC_METADATA_TOO_LARGE');
    return;
}
try {
    $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    respond(400, 'SYNTHETIC_JSON_INVALID');
    return;
}
if (!is_array($body) || array_is_list($body)) {
    respond(422, 'SYNTHETIC_ENVELOPE_INVALID');
    return;
}
$issued = parseSeconds($_SERVER['HTTP_X_LAB_ISSUED_AT'] ?? null);
$expires = parseSeconds($_SERVER['HTTP_X_LAB_EXPIRES_AT'] ?? null);
$nonce = $_SERVER['HTTP_X_LAB_NONCE'] ?? null;
$signature = $_SERVER['HTTP_X_LAB_SIGNATURE'] ?? null;
if ($issued === null || $expires === null
    || !is_string($nonce) || !is_string($signature)) {
    respond(401, 'SYNTHETIC_AUTH_HEADER_REQUIRED');
    return;
}
$hex = getenv('PORTAL_F120_TEST_KEY');
if (!is_string($hex) || preg_match('/\A[0-9a-f]{96}\z/D', $hex) !== 1) {
    respond(503, 'SYNTHETIC_TEST_KEY_UNAVAILABLE');
    return;
}
$key = hex2bin($hex);
if ($key === false) {
    respond(503, 'SYNTHETIC_TEST_KEY_UNAVAILABLE');
    return;
}

// The context below is fixed exclusively for CI, never drawn from user
// input. An actual receiver must resolve tenant/binding in its trusted DB.
$binding = [
    'organization_reference' => '11111111-1111-4111-8111-111111111111',
    'binding_reference' => '22222222-2222-4222-8222-222222222222',
    'site_reference' => '44444444-4444-4444-8444-444444444444',
    'form_reference' => '55555555-5555-4555-8555-555555555555',
    'destination_reference' => '66666666-6666-4666-8666-666666666666',
];
try {
    $pdo = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
        'postgres', (string)getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );
    $receiver = new Reservation($key);
    $result = $receiver->observe($pdo, $body, $binding, [
        'issued_at' => $issued,
        'expires_at' => $expires,
        'nonce' => $nonce,
        'mac' => $signature,
    ]);
    $reason = $result['reasonCode'];
    if (in_array($reason, [
        'LAB_METADATA_AND_OUTBOX_WRITTEN',
        'LAB_EXISTING_SUBMISSION_NOT_DELIVERED',
    ], true)) {
        respond(202, $reason);
    } elseif ($reason === 'SYNTHETIC_NONCE_REPLAY'
        || str_starts_with($reason, 'LOGICAL_SUBMISSION_')) {
        respond(409, $reason);
    } elseif ($reason === 'PINNED_METADATA_REJECTED') {
        respond(403, $reason);
    } elseif (str_starts_with($reason, 'SYNTHETIC_AUTH_')) {
        respond(401, 'SYNTHETIC_AUTH_REJECTED');
    } else {
        respond(503, 'SYNTHETIC_REVIEW_REQUIRED');
    }
} catch (Throwable) {
    // No SQL error, key, UUID or personal data ever appears in an HTTP error.
    respond(503, 'SYNTHETIC_REVIEW_REQUIRED');
}
