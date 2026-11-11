<?php

declare(strict_types=1);

// F121: INDEPENDENT MOCK CRM RECEIVER inside a PHP CI-only loopback server.
// NEVER route through Laravel or deploy to a public/reachable HTTP server.
// It has its own disposable PostgreSQL database, no customer data or CRM API.
require_once __DIR__ . '/../commercial-intake-f117/PortalCommercialMetadataInspector.php';

use Imagine\Portal\Labs\CommercialIntakeF117\PortalCommercialMetadataInspector as Inspector;

function f121_reply(int $status, string $reason, ?array $receipt = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $response = [
        'schema' => 'imagine.portal.crm-mock-receiver.v1',
        'reasonCode' => $reason,
        'mode' => 'TWO_DISPOSABLE_DB_MOCK_NOT_REAL_CRM',
        'mockReceiverCommitted' => $receipt !== null,
        'realCrmPersisted' => false,
        'canAcknowledgeVisitor' => false,
        'productionAuthorized' => false,
    ];
    if ($receipt !== null) {
        $response['receipt'] = $receipt;
    }
    echo json_encode($response, JSON_THROW_ON_ERROR);
}
function f121_uuid(): string
{
    $hex = bin2hex(random_bytes(16));
    $hex[12] = '4';
    $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);
    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.
        substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
}
function f121_receipt(array $envelope, string $key): array
{
    $iat = time();
    $exp = $iat + 120;
    $nonce = f121_uuid();
    $fields = ['imagine.lead-intake.synthetic-receipt.v1'];
    foreach ([
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ] as $reference) {
        $fields[] = strtolower($envelope[$reference]);
    }
    $fields[] = (string)$iat;
    $fields[] = (string)$exp;
    $fields[] = $nonce;
    return [
        'issued_at'=>$iat,
        'expires_at'=>$exp,
        'nonce'=>$nonce,
        'mac'=>hash_hmac('sha256',implode("\n",$fields),$key),
    ];
}
function f121_auth(array $envelope, string $domain, string $key): bool
{
    $iat = $_SERVER['HTTP_X_LAB_ISSUED_AT'] ?? null;
    $exp = $_SERVER['HTTP_X_LAB_EXPIRES_AT'] ?? null;
    $nonce = $_SERVER['HTTP_X_LAB_NONCE'] ?? null;
    $mac = $_SERVER['HTTP_X_LAB_SIGNATURE'] ?? null;
    if (!is_string($iat) || !is_string($exp)
        || !preg_match('/\A[1-9][0-9]{0,11}\z/D',$iat)
        || !preg_match('/\A[1-9][0-9]{0,11}\z/D',$exp)
        || !is_string($nonce)
        || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$nonce) !== 1
        || $nonce === '00000000-0000-0000-0000-000000000000'
        || !is_string($mac)
        || preg_match('/\A[0-9a-f]{64}\z/D',$mac) !== 1) {
        return false;
    }
    $issued = (int)$iat;
    $expires = (int)$exp;
    $now = time();
    if ((string)$issued !== $iat || (string)$expires !== $exp
        || $issued <= 0 || $expires <= $issued
        || $expires-$issued > 300 || $issued > $now+30
        || $expires <= $now) {
        return false;
    }
    $parts = [$domain];
    foreach ([
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ] as $ref) {
        $parts[] = strtolower($envelope[$ref]);
    }
    $parts[] = $iat;
    $parts[] = $exp;
    $parts[] = $nonce;
    return hash_equals(hash_hmac('sha256',implode("\n",$parts),$key),$mac);
}
function f121_record(PDO $pdo, array $envelope, string $nonce): string
{
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET LOCAL ROLE portal_f121_mock_crm_a');
        $stmt=$pdo->prepare(<<<'SQL'
WITH seen AS (
 INSERT INTO mock_crm_ci.request_nonces
   (organization_reference,nonce,submission_reference)
 VALUES (CAST(:org AS uuid),CAST(:nonce AS uuid),CAST(:submission AS uuid))
 ON CONFLICT (organization_reference,nonce) DO NOTHING
 RETURNING organization_reference,submission_reference
), created AS (
 INSERT INTO mock_crm_ci.received_submissions
 (organization_reference,submission_reference,binding_reference,
  site_reference,form_reference,destination_reference)
 SELECT organization_reference,submission_reference,
  CAST(:binding AS uuid),CAST(:site AS uuid),
  CAST(:form AS uuid),CAST(:destination AS uuid)
 FROM seen
 ON CONFLICT (organization_reference,submission_reference) DO NOTHING
 RETURNING submission_reference
)
SELECT (SELECT count(*) FROM seen) AS seen_rows,
       (SELECT count(*) FROM created) AS created_rows
SQL);
        $stmt->execute([
            'org'=>strtolower($envelope['organization_reference']),
            'nonce'=>$nonce,
            'submission'=>strtolower($envelope['submission_reference']),
            'binding'=>strtolower($envelope['binding_reference']),
            'site'=>strtolower($envelope['site_reference']),
            'form'=>strtolower($envelope['form_reference']),
            'destination'=>strtolower($envelope['destination_reference']),
        ]);
        $counts=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($counts) || (int)$counts['seen_rows'] !== 1) {
            $pdo->rollBack();
            return 'NONCE_REPLAY_OR_UNVERIFIED';
        }
        $check=$pdo->prepare(<<<'SQL'
SELECT binding_reference,site_reference,form_reference,destination_reference
FROM mock_crm_ci.received_submissions
WHERE organization_reference=CAST(:org AS uuid)
  AND submission_reference=CAST(:submission AS uuid)
SQL);
        $check->execute([
            'org'=>strtolower($envelope['organization_reference']),
            'submission'=>strtolower($envelope['submission_reference']),
        ]);
        $stored=$check->fetch(PDO::FETCH_ASSOC);
        if (!is_array($stored)) {
            $pdo->rollBack();
            return 'RECEIVER_PERSISTENCE_UNVERIFIED';
        }
        foreach ([
            'binding_reference','site_reference','form_reference','destination_reference'
        ] as $field) {
            if (strtolower($stored[$field])!==strtolower($envelope[$field])) {
                $pdo->rollBack();
                return 'RECEIVER_CONTEXT_CONFLICT';
            }
        }
        $pdo->commit();
        return (int)$counts['created_rows']===1
            ? 'MOCK_COMMITTED_FIRST' : 'MOCK_COMMITTED_REPLAY_OF_LOGICAL_SUBMISSION';
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return 'RECEIVER_PERSISTENCE_UNVERIFIED';
    }
}
function f121_lookup(PDO $pdo, array $envelope): bool
{
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET LOCAL ROLE portal_f121_mock_crm_a');
        $stmt=$pdo->prepare(<<<'SQL'
SELECT binding_reference,site_reference,form_reference,destination_reference
FROM mock_crm_ci.received_submissions
WHERE organization_reference=CAST(:org AS uuid)
  AND submission_reference=CAST(:submission AS uuid)
SQL);
        $stmt->execute([
            'org'=>strtolower($envelope['organization_reference']),
            'submission'=>strtolower($envelope['submission_reference'])
        ]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        $matches=is_array($row);
        foreach ([
            'binding_reference','site_reference','form_reference','destination_reference'
        ] as $field) {
            $matches=$matches && strtolower($row[$field])===strtolower($envelope[$field]);
        }
        $pdo->commit();
        return $matches;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

if (PHP_SAPI!=='cli-server'
    || getenv('GITHUB_ACTIONS')!=='true'
    || getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    || getenv('PORTAL_F121_CRM_MOCK_LAB')!=='1'
    || getenv('DB_HOST')!=='127.0.0.1'
    || getenv('DB_DATABASE')!=='portal_commercial_lab'
    || (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR']!=='127.0.0.1')
    || ($_SERVER['REMOTE_ADDR'] ?? '')!=='127.0.0.1') {
    f121_reply(503,'DISPOSABLE_LOOPBACK_ONLY');
    return;
}
$path=$_SERVER['REQUEST_URI'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'
    || !in_array($path,['/__lab/crm/deliver','/__lab/crm/lookup'],true)) {
    f121_reply(404,'NOT_PUBLICLY_AVAILABLE');
    return;
}
if (($_SERVER['CONTENT_TYPE'] ?? '')!=='application/json') {
    f121_reply(415,'JSON_REQUIRED');
    return;
}
$length=$_SERVER['CONTENT_LENGTH'] ?? null;
if ($length!==null && (!is_string($length)
    || !ctype_digit($length) || (int)$length>2048)) {
    f121_reply(413,'BODY_TOO_LARGE');
    return;
}
$raw=file_get_contents('php://input',false,null,0,2049);
if (!is_string($raw) || strlen($raw)>2048) {
    f121_reply(413,'BODY_TOO_LARGE');
    return;
}
try {
    $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
} catch (Throwable) {
    f121_reply(400,'INVALID_JSON');
    return;
}
$binding=[
    'organization_reference'=>'11111111-1111-4111-8111-111111111111',
    'binding_reference'=>'22222222-2222-4222-8222-222222222222',
    'site_reference'=>'44444444-4444-4444-8444-444444444444',
    'form_reference'=>'55555555-5555-4555-8555-555555555555',
    'destination_reference'=>'66666666-6666-4666-8666-666666666666',
];
if (!is_array($body) || array_is_list($body)
    || Inspector::inspect($body,$binding)['reasonCode']!=='STRUCTURE_MATCH_UNTRUSTED') {
    f121_reply(403,'PINNED_CONTEXT_REJECTED');
    return;
}
$requestKeyHex=getenv('PORTAL_F121_REQ_KEY_HEX');
$receiptKeyHex=getenv('PORTAL_F121_RECEIPT_KEY_HEX');
if (!is_string($requestKeyHex)||!is_string($receiptKeyHex)
    || !preg_match('/\A[0-9a-f]{96}\z/D',$requestKeyHex)
    || !preg_match('/\A[0-9a-f]{96}\z/D',$receiptKeyHex)) {
    f121_reply(503,'KEYS_UNAVAILABLE');
    return;
}
$requestKey=hex2bin($requestKeyHex);
$receiptKey=hex2bin($receiptKeyHex);
if (!is_string($requestKey)||!is_string($receiptKey)) {
    f121_reply(503,'KEYS_UNAVAILABLE');
    return;
}
$domain=$path==='/__lab/crm/lookup'
    ? 'imagine.portal.synthetic-crm-lookup.v1'
    : 'imagine.portal.synthetic-service-request.v1';
if (!f121_auth($body,$domain,$requestKey)) {
    f121_reply(401,'SIGNED_REQUEST_REJECTED');
    return;
}
try {
    $pdo=new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_crm_receiver_lab',
        'postgres',(string)getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    if ($pdo->query('SELECT current_database()')->fetchColumn()
        !== 'portal_crm_receiver_lab') {
        f121_reply(503,'RECEIVER_DATABASE_UNAVAILABLE');
        return;
    }
    if ($path==='/__lab/crm/lookup') {
        if (!f121_lookup($pdo,$body)) {
            f121_reply(404,'NOT_VERIFIED_IN_MOCK_RECEIVER');
            return;
        }
        f121_reply(200,'LAB_MOCK_RECEIPT_FROM_READ_BACK',f121_receipt($body,$receiptKey));
        return;
    }
    $nonce=$_SERVER['HTTP_X_LAB_NONCE'];
    $code=f121_record($pdo,$body,$nonce);
    if ($code==='NONCE_REPLAY_OR_UNVERIFIED'
        || $code==='RECEIVER_CONTEXT_CONFLICT') {
        f121_reply(409,$code);
        return;
    }
    if (!in_array($code,[
        'MOCK_COMMITTED_FIRST','MOCK_COMMITTED_REPLAY_OF_LOGICAL_SUBMISSION'
    ],true)) {
        f121_reply(503,'RECEIVER_PERSISTENCE_UNVERIFIED');
        return;
    }
    // Simulate an ACK lost AFTER an actual PG commit. No receipt or positive
    // assertion is returned to the caller; it must reconcile via trusted lookup.
    if (($_SERVER['HTTP_X_LAB_DROP_RECEIPT'] ?? null)==='1') {
        f121_reply(503,'UNKNOWN_OUTCOME_NO_RECEIPT');
        return;
    }
    f121_reply(202,$code,f121_receipt($body,$receiptKey));
} catch (Throwable) {
    f121_reply(503,'RECEIVER_PERSISTENCE_UNVERIFIED');
}
