<?php

declare(strict_types=1);

/**
 * PORTAL-PERF-F14 — lab-only outbox dispatcher.
 * Reads the ACTUAL test Portal SQL outbox (never a static export), signs its
 * bytes with ephemeral HMAC, POSTs to loopback and ACKs with a separate least-
 * privilege SQL role. Absolutely no live use, real user identity, or network.
 */
require_once __DIR__.'/SyntheticWireEnvelope.php';

use Imagine\Protocol\Lab\SyntheticWireEnvelope as Wire;

$mode = getenv('PORTAL_PERF_F14_DISPATCH_MODE');
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
    || getenv('PORTAL_PERF_F11_LAB_ENABLED') !== '1'
    || getenv('PORTAL_PERF_F14_LAB_ENABLED') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'portal_lab'
    || !in_array($mode, ['transport_loss','ack_loss','retry','deliver'], true)) {
    throw new RuntimeException('F14 dispatcher denied outside isolated localhost CLI.');
}
$keyHex = getenv('PERF_WIRE_LAB_TEST_KEY_HEX');
if (!is_string($keyHex) || !preg_match('/^[a-f0-9]{64}$/D', $keyHex)) {
    throw new RuntimeException('Missing ephemeral laboratory key.');
}
$key = hex2bin($keyHex);
if (!is_string($key)) {
    throw new RuntimeException('Bad lab key encoding.');
}
$connect = static fn (string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_lab;connect_timeout=3',
    $role, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
// Portal F11 gives the worker only SELECT of the service outbox. A dedicated
// ACK role is created by the disposable CI and cannot mutate service grants.
$reader = $connect('portal_perf_source_reader', 'ci-only-portal-perf-reader');
$acker = $connect('portal_perf_ack_demo', 'ci-only-portal-perf-ack');

$statement = $reader->query(
    "SELECT event_id, service_ref, organization_ref, tenant_ref,
            source_revision, event_type, status, attempts
     FROM lab_portal_perf_revocation_outbox
     WHERE service_ref='svc-demo-b' AND status='pending'
     ORDER BY source_revision ASC LIMIT 1"
);
$event = $statement->fetch(PDO::FETCH_ASSOC);
if (!is_array($event)) {
    echo "PASS F14 already_acked_demo\n";
    exit(0);
}
$ordered = [
    'event_id' => $event['event_id'],
    'service_ref' => $event['service_ref'],
    'organization_ref' => $event['organization_ref'],
    'tenant_ref' => $event['tenant_ref'],
    'source_revision' => (int) $event['source_revision'],
    'event_type' => $event['event_type'],
    'status' => $event['status'],
    'attempts' => (int) $event['attempts'],
];
if (!preg_match('/^evt-demo-[a-f0-9]{24}$/D', $ordered['event_id'])
    || $ordered['service_ref'] !== 'svc-demo-b'
    || $ordered['organization_ref'] !== 'org-demo-b'
    || $ordered['tenant_ref'] !== 'perf-demo-b'
    || $ordered['source_revision'] !== 2
    || $ordered['event_type'] !== 'service.authorization_changed.synthetic'
    || $ordered['status'] !== 'pending'
    || $ordered['attempts'] !== 0) {
    throw new RuntimeException('F14 source grant is not the allowlisted lab event.');
}
$authority = $reader->query(
    "SELECT service_ref,organization_ref,tenant_ref,min_revision,active
     FROM lab_portal_perf_authority WHERE service_ref='svc-demo-b'"
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($authority)
    || $authority['organization_ref'] !== $ordered['organization_ref']
    || $authority['tenant_ref'] !== $ordered['tenant_ref']
    || (int) $authority['min_revision'] !== $ordered['source_revision']
    || !in_array($authority['active'], [false, 'f', '0', 0], true)) {
    throw new RuntimeException('F14 current source authority is not revoked.');
}

if ($mode === 'transport_loss') {
    // Outbox remains pending, and no target HTTP call is attempted.
    echo "PASS F14 relay_transport_deferred_demo\n";
    exit(0);
}

// New nonce per attempt: original nonce replay never grants a second effect,
// while a lost response can be retried and recognized by durable event_id.
$body = json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$headers = Wire::signForLab(
    $body, $key, 1800000000,
    'nonce-demo-direct-'.bin2hex(random_bytes(12))
);
$lines = ['Content-Type: application/json'];
foreach ($headers as $name => $value) {
    $lines[] = $name.': '.$value;
}
$client = curl_init('http://127.0.0.1:18784'.Wire::PATH);
if ($client === false) {
    throw new RuntimeException('No local cURL available.');
}
curl_setopt_array($client, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $lines,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_PROXY => '',
]);
$response = curl_exec($client);
$httpStatus = curl_getinfo($client, CURLINFO_HTTP_CODE);
curl_close($client);
if (!is_string($response)) {
    echo "PASS F14 relay_transport_deferred_demo\n";
    exit(0);
}
$decoded = json_decode($response, true);
if (!is_array($decoded) || !isset($decoded['result'])) {
    throw new RuntimeException('Invalid synthetic HTTP reply.');
}
$result = (string) $decoded['result'];
if ($httpStatus === 202 && $result === 'revocation_applied_synthetic_only') {
    $accepted = 'applied';
} elseif ($httpStatus === 409 && $result === 'wire_event_replay_denied') {
    // In CI this is a known, local-only response. A real system must
    // authenticate ACK and reconcile with current authority before ACK.
    $accepted = 'already_applied';
} else {
    throw new RuntimeException('F14 delivery denied: '.$httpStatus.' '.$result);
}
if ($mode === 'ack_loss') {
    echo "PASS F14 relay_ack_deferred_demo\n";
    exit(0);
}

// Separate transaction from Performance is an intentional at-least-once test.
$ack = $acker->prepare(
    "UPDATE lab_portal_perf_revocation_outbox
     SET status='acked', attempts=attempts+1
     WHERE event_id=? AND status='pending'"
);
$ack->execute([$ordered['event_id']]);
if ($ack->rowCount() !== 1) {
    throw new RuntimeException('F14 ACK did not update exactly one pending outbox row.');
}
echo 'PASS F14 relay_'.$accepted.'_and_ack_demo'."\n";
