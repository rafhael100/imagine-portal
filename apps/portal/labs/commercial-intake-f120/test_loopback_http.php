<?php

declare(strict_types=1);

// F120: signed HTTP requests to PHP built-in server bound 127.0.0.1 ONLY.
// All data and keys are synthetic; no real Portal, CRM or public visitor.
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('PORTAL_F120_HTTP_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'portal_commercial_lab') {
    fwrite(STDERR, "F120 refuses external or non-ephemeral CI.\n");
    exit(2);
}
$hex = getenv('PORTAL_F120_TEST_KEY');
if (!is_string($hex) || preg_match('/\A[0-9a-f]{96}\z/D', $hex) !== 1) {
    throw new RuntimeException('F120 missing test-only key');
}
$key = hex2bin($hex);
if ($key === false) {
    throw new RuntimeException('F120 invalid test-only key');
}
$checks = 0;
function check(bool $value, string $description): void
{
    global $checks;
    $checks++;
    if (!$value) {
        throw new RuntimeException('F120 failed: ' . $description);
    }
}
function sign(array $envelope, string $key, string $nonce, int $iat, int $expires): array
{
    $refs = [
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ];
    $message = ['imagine.portal.synthetic-service-request.v1'];
    foreach ($refs as $ref) {
        $message[] = strtolower($envelope[$ref]);
    }
    $message[] = (string)$iat;
    $message[] = (string)$expires;
    $message[] = $nonce;
    return [
        'X-Lab-Issued-At'=>(string)$iat,
        'X-Lab-Expires-At'=>(string)$expires,
        'X-Lab-Nonce'=>$nonce,
        'X-Lab-Signature'=>hash_hmac('sha256', implode("\n", $message), $key),
    ];
}

/** @return array{0:int,1:array<string,mixed>} */
function request(
    string $raw, array $auth = [], string $method = 'POST',
    string $path = '/__lab/comercial/internal-intake',
    string $type = 'application/json'
): array {
    $lines = ['Content-Type: '.$type, 'Connection: close'];
    foreach ($auth as $name=>$value) {
        // Only test-controlled headers, not arbitrary user strings.
        check(preg_match('/\AX-Lab-[A-Za-z-]+\z/D', $name) === 1, 'safe header name');
        check(is_string($value) && preg_match('/\A[0-9a-f-]+\z/iD', $value) === 1, 'safe header data');
        $lines[] = $name.': '.$value;
    }
    $opts = stream_context_create([
        'http'=>[
            'method'=>$method, 'header'=>implode("\r\n", $lines),
            'content'=>$raw, 'ignore_errors'=>true,
            'timeout'=>7, 'follow_location'=>0,
        ],
    ]);
    $response = @file_get_contents('http://127.0.0.1:19119'.$path, false, $opts);
    $headers = $http_response_header ?? [];
    check(is_string($response) && isset($headers[0]), 'HTTP loopback request returned');
    check(preg_match('/\AHTTP\/[0-9.]+\s+([0-9]{3})\b/', $headers[0], $match) === 1,
        'HTTP status parsed');
    $parsed = json_decode($response, true, 12, JSON_THROW_ON_ERROR);
    check(is_array($parsed), 'JSON response');
    return [(int)$match[1], $parsed];
}
function expect(
    string $raw, array $auth, int $status, string $reason,
    string $method='POST', string $path='/__lab/comercial/internal-intake',
    string $type='application/json'
): void {
    [$actualStatus,$result] = request($raw,$auth,$method,$path,$type);
    check($actualStatus === $status, 'status '.$status.' for '.$reason.', got '.$actualStatus);
    check($result['reasonCode'] === $reason,
        'reason '.$reason.', got '.$result['reasonCode']);
    check($result['mode'] === 'TEST_ONLY_LOOPBACK_NOT_A_CRM_ACK',
        'no production endpoint/receipt');
    foreach ([
        'canAcceptVisitorData','tenantAuthenticatedForProduction',
        'crmPersisted','canAcknowledgeVisitor','canDeliverToCrm',
        'productionAuthorized'
    ] as $field) {
        check($result[$field] === false, 'no authority '.$field);
    }
    check(!isset($result['nonce']) && !isset($result['mac'])
        && !isset($result['submission_reference']), 'no signed context leaked');
    check(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'example.test'),
        'no contact values echoed');
}
function json(array $body): string
{
    return json_encode($body, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
$db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
    'postgres',(string)getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES=>false]);
check($db->query('SELECT current_database()')->fetchColumn() === 'portal_commercial_lab',
    'dedicated database');
function observed(PDO $db, string $table, string $sub): int
{
    check(in_array($table,['metadata_ledger','pending_outbox','service_request_nonce'],true),
        'only synthetic metadata tables');
    $stmt=$db->prepare("SELECT count(*) FROM portal_commercial_ci.{$table} WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid AND submission_reference=CAST(:sub AS uuid)");
    $stmt->execute(['sub'=>$sub]);
    return (int)$stmt->fetchColumn();
}

$env=[
    'protocol'=>'imagine.lead-intake','schema_version'=>1,
    'organization_reference'=>'11111111-1111-4111-8111-111111111111',
    'binding_reference'=>'22222222-2222-4222-8222-222222222222',
    'submission_reference'=>'c1c1c1c1-c1c1-4c1c-8c1c-c1c1c1c1c1c1',
    'site_reference'=>'44444444-4444-4444-8444-444444444444',
    'form_reference'=>'55555555-5555-4555-8555-555555555555',
    'destination_reference'=>'66666666-6666-4666-8666-666666666666'
];
$now=time();
$nonce1='91919191-9191-4919-8919-919191919191';
$nonce2='92929292-9292-4929-8929-929292929292';
$auth=sign($env,$key,$nonce1,$now,$now+120);
$payload=json($env);
check(observed($db,'metadata_ledger',$env['submission_reference'])===0,'fresh HTTP fixture');

expect($payload,[],401,'SYNTHETIC_AUTH_HEADER_REQUIRED');
$bad=$auth;
$bad['X-Lab-Signature']=str_repeat('a',64);
expect($payload,$bad,401,'SYNTHETIC_AUTH_REJECTED');
$late=sign($env,$key,'93939393-9393-4939-8939-939393939393',$now-500,$now-300);
expect($payload,$late,401,'SYNTHETIC_AUTH_REJECTED');
expect($payload,$auth,404,'NO_PUBLIC_ROUTE','GET');
expect($payload,$auth,404,'NO_PUBLIC_ROUTE','POST','/__lab/comercial/internal-intake?insecure=1');
expect($payload,$auth,404,'NO_PUBLIC_ROUTE','POST','/api/leads');
expect($payload,$auth,415,'JSON_CONTENT_TYPE_REQUIRED','POST','/__lab/comercial/internal-intake','text/plain');
expect('{"truncated":',$auth,400,'SYNTHETIC_JSON_INVALID');
expect(json([...$env,'oversized'=>str_repeat('x',4096)]),
    $auth,413,'SYNTHETIC_METADATA_TOO_LARGE');
$extra=[...$env,'email'=>'not-a-real-contact@example.test'];
expect(json($extra),$auth,403,'PINNED_METADATA_REJECTED');
$forged=$env;
$forged['organization_reference']='99999999-9999-4999-8999-999999999999';
expect(json($forged),sign($forged,$key,'94949494-9494-4949-8949-949494949494',$now,$now+120),
    403,'PINNED_METADATA_REJECTED');
check(observed($db,'metadata_ledger',$env['submission_reference'])===0,
    'rejected requests left database unchanged');

expect($payload,$auth,202,'LAB_METADATA_AND_OUTBOX_WRITTEN');
check(observed($db,'metadata_ledger',$env['submission_reference'])===1,
    'one logical request after HTTP POST');
check(observed($db,'pending_outbox',$env['submission_reference'])===1,
    'one pending_review outbox event after HTTP POST');
check(observed($db,'service_request_nonce',$env['submission_reference'])===1,
    'first nonce persisted in same transaction');

expect($payload,$auth,409,'SYNTHETIC_NONCE_REPLAY');
expect($payload,sign($env,$key,$nonce2,$now,$now+120),
    202,'LAB_EXISTING_SUBMISSION_NOT_DELIVERED');
check(observed($db,'metadata_ledger',$env['submission_reference'])===1,
    'fresh nonce does not create duplicate request');
check(observed($db,'pending_outbox',$env['submission_reference'])===1,
    'fresh nonce does not duplicate outbox');
check(observed($db,'service_request_nonce',$env['submission_reference'])===2,
    'two separate attempt nonces retained');

echo "F120 PASS: {$checks} loopback HTTP/PHP/PG18 checks; MAC/replay/tenant/outbox, no CRM ACK or visitor data.\n";
