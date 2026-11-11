<?php

declare(strict_types=1);

// F119: synthetic HMAC + PostgreSQL 18 nonce and outbox in a throwaway CI DB.
// No Laravel route, real key, PII or CRM delivery. Never point to another DB.
require_once __DIR__ . '/PortalF119SyntheticServiceReservation.php';

use Imagine\Portal\Labs\CommercialIntakeF119\PortalF119SyntheticServiceReservation as Reservation;

if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('PORTAL_F119_PG_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'portal_commercial_lab'
    || !extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "F119 refuses any non-disposable CI database.\n");
    exit(2);
}
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('F119 FAIL: ' . $message);
    }
}
function noAuthority(array $result): void
{
    check($result['evidenceClass'] === 'SYNTHETIC_SERVICE_MAC_AND_DISPOSABLE_PG_ONLY', 'lab evidence only');
    foreach ([
        'canAcceptVisitorData','tenantAuthenticatedForProduction',
        'crmPersisted','canAcknowledgeVisitor','canDeliverToCrm',
        'canAutoRetryUnknownOutcome','productionAuthorized',
    ] as $key) {
        check($result[$key] === false, 'no authority: ' . $key);
    }
    foreach (['secret','signature','nonce','organization_reference','submission_reference'] as $field) {
        check(!array_key_exists($field, $result), 'no leakage: ' . $field);
    }
}
function expect(Reservation $receiver, PDO $db, array $envelope, array $binding, array $auth, string $code): void
{
    $value = $receiver->observe($db, $envelope, $binding, $auth);
    check($value['reasonCode'] === $code, $code . ': got ' . $value['reasonCode']);
    noAuthority($value);
}
function sign(array $envelope, string $key, string $nonce, int $iat, int $exp): array
{
    $keys = [
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ];
    $message = ['imagine.portal.synthetic-service-request.v1'];
    foreach ($keys as $k) {
        $message[] = strtolower($envelope[$k]);
    }
    $message[] = (string)$iat;
    $message[] = (string)$exp;
    $message[] = $nonce;
    return [
        'issued_at' => $iat, 'expires_at' => $exp,
        'nonce' => $nonce,
        'mac' => hash_hmac('sha256', implode("\n", $message), $key),
    ];
}
function countRows(PDO $pdo, string $table, string $org): int
{
    check(in_array($table, ['metadata_ledger','pending_outbox','service_request_nonce'], true),
        'fixed table');
    $stmt = $pdo->prepare("SELECT count(*) FROM portal_commercial_ci.{$table} WHERE organization_reference=CAST(:org AS uuid)");
    $stmt->execute(['org'=>$org]);
    return (int)$stmt->fetchColumn();
}
function deniedSql(PDO $pdo, string $command): void
{
    global $checks;
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET LOCAL ROLE portal_commercial_f118_a');
        $pdo->exec($command);
        $pdo->rollBack();
        throw new RuntimeException('F119 worker was allowed destructive SQL: ' . $command);
    } catch (PDOException) {
        $pdo->rollBack();
        $checks++;
    }
}
$db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
    'postgres',(string)getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES=>false,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
check($db->query('SELECT current_database()')->fetchColumn() === 'portal_commercial_lab',
    'dedicated disposable database');
check((int)$db->query("SELECT count(*) FROM pg_namespace WHERE nspname='portal_commercial_ci'")->fetchColumn() === 1,
    'F118 schema exists from prior test');
check((int)$db->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='portal_commercial_ci' AND table_name='service_request_nonce'")->fetchColumn() === 0,
    'new nonce table does not exist');
$db->exec((string)file_get_contents(__DIR__ . '/nonce_pg18.sql'));

$key = random_bytes(48);
$receiver = new Reservation($key);
$wrongReceiver = new Reservation(random_bytes(48));
$org = '11111111-1111-4111-8111-111111111111';
$env = [
    'protocol'=>'imagine.lead-intake','schema_version'=>1,
    'organization_reference'=>$org,
    'binding_reference'=>'22222222-2222-4222-8222-222222222222',
    'submission_reference'=>'f7f7f7f7-f7f7-4f7f-8f7f-f7f7f7f7f7f7',
    'site_reference'=>'44444444-4444-4444-8444-444444444444',
    'form_reference'=>'55555555-5555-4555-8555-555555555555',
    'destination_reference'=>'66666666-6666-4666-8666-666666666666',
];
$binding = [
    'organization_reference'=>$env['organization_reference'],
    'binding_reference'=>$env['binding_reference'],
    'site_reference'=>$env['site_reference'],
    'form_reference'=>$env['form_reference'],
    'destination_reference'=>$env['destination_reference'],
];
$iat = time();
$exp = $iat + 120;
$nonce1 = '81818181-8181-4818-8818-818181818181';
$nonce2 = '82828282-8282-4828-8828-828282828282';
$nonce3 = '83838383-8383-4838-8838-838383838383';
$auth1 = sign($env,$key,$nonce1,$iat,$exp);
check(countRows($db,'service_request_nonce',$org)===0,'fresh nonce ledger');

expect($wrongReceiver,$db,$env,$binding,$auth1,'SYNTHETIC_AUTH_MAC_MISMATCH');
check(countRows($db,'service_request_nonce',$org)===0,'bad key no insert');

$wrongMac = $auth1;
$wrongMac['mac']=str_repeat('a',64);
expect($receiver,$db,$env,$binding,$wrongMac,'SYNTHETIC_AUTH_MAC_MISMATCH');
$badNonce = $auth1;
$badNonce['nonce'] = 'UPPERCASE-NONCE';
expect($receiver,$db,$env,$binding,$badNonce,'SYNTHETIC_AUTH_FORMAT_INVALID');
$extra = $auth1;
$extra['actor_role'] = 'admin';
expect($receiver,$db,$env,$binding,$extra,'SYNTHETIC_AUTH_FORMAT_INVALID');
$expired = sign($env,$key,'a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1',$iat-400,$iat-100);
expect($receiver,$db,$env,$binding,$expired,'SYNTHETIC_AUTH_WINDOW_INVALID');
$future = sign($env,$key,'a2a2a2a2-a2a2-4a2a-8a2a-a2a2a2a2a2a2',$iat+60,$iat+200);
expect($receiver,$db,$env,$binding,$future,'SYNTHETIC_AUTH_WINDOW_INVALID');
$tooLong = sign($env,$key,'a3a3a3a3-a3a3-4a3a-8a3a-a3a3a3a3a3a3',$iat,$iat+301);
expect($receiver,$db,$env,$binding,$tooLong,'SYNTHETIC_AUTH_WINDOW_INVALID');
check(countRows($db,'service_request_nonce',$org)===0,'bad auth no nonce');

expect($receiver,$db,$env,$binding,$auth1,'LAB_METADATA_AND_OUTBOX_WRITTEN');
check(countRows($db,'metadata_ledger',$org)===1,'exactly one logical submission (F119)');
check(countRows($db,'pending_outbox',$org)===1,'exactly one atomic outbox event');
check(countRows($db,'service_request_nonce',$org)===1,'one nonce');
expect($receiver,$db,$env,$binding,$auth1,'SYNTHETIC_NONCE_REPLAY');
check(countRows($db,'service_request_nonce',$org)===1,'same nonce rejected');

$auth2 = sign($env,$key,$nonce2,$iat,$exp);
expect($receiver,$db,$env,$binding,$auth2,'LAB_EXISTING_SUBMISSION_NOT_DELIVERED');
check(countRows($db,'metadata_ledger',$org)===1,'new nonce did not create second logical lead');
check(countRows($db,'pending_outbox',$org)===1,'new nonce did not duplicate outbox');
check(countRows($db,'service_request_nonce',$org)===2,'separate attempt nonce tracked');

// Another logical submission must NOT reuse a consumed nonce in this tenant.
$other=$env;
$other['submission_reference']='f8f8f8f8-f8f8-4f8f-8f8f-f8f8f8f8f8f8';
$otherAuth=sign($other,$key,$nonce1,$iat,$exp);
expect($receiver,$db,$other,$binding,$otherAuth,'SYNTHETIC_NONCE_REPLAY');
check(countRows($db,'metadata_ledger',$org)===1,'no second lead via used nonce');

// A new nonce with a changed destination cannot reroute the original
// submission, even if an operator erroneously supplies a matching fixture.
$changed=$env;
$changed['destination_reference']='77777777-7777-4777-8777-777777777777';
$changedBinding=$binding;
$changedBinding['destination_reference']=$changed['destination_reference'];
$changedAuth=sign($changed,$key,$nonce3,$iat,$exp);
expect($receiver,$db,$changed,$changedBinding,$changedAuth,'LOGICAL_SUBMISSION_CONTEXT_CONFLICT');
check(countRows($db,'service_request_nonce',$org)===2,
    'destination conflict rolled back attempted nonce');
$auth3=sign($env,$key,$nonce3,$iat,$exp);
expect($receiver,$db,$env,$binding,$auth3,'LAB_EXISTING_SUBMISSION_NOT_DELIVERED');
check(countRows($db,'service_request_nonce',$org)===3,'valid retry retained clean nonce');

foreach (['organization_reference','binding_reference','site_reference','form_reference','destination_reference'] as $field) {
    $forged=$env;
    $forged[$field]='99999999-9999-4999-8999-999999999999';
    $auth=sign($forged,$key,'a4a4a4a4-a4a4-4a4a-8a4a-a4a4a4a4a4a4',$iat,$exp);
    expect($receiver,$db,$forged,$binding,$auth,'PINNED_METADATA_REJECTED');
}
foreach (['email','phone','name','message','consent','role','mobile'] as $field) {
    $extraEnv=$env;
    $extraEnv[$field]='TEST_ONLY';
    expect($receiver,$db,$extraEnv,$binding,$auth1,'PINNED_METADATA_REJECTED');
}
$b=$env;
$b['organization_reference']='99999999-9999-4999-8999-999999999999';
$bBinding=$binding;
$bBinding['organization_reference']=$b['organization_reference'];
$bAuth=sign($b,$key,'a5a5a5a5-a5a5-4a5a-8a5a-a5a5a5a5a5a5',$iat,$exp);
expect($receiver,$db,$b,$bBinding,$bAuth,'EPHEMERAL_PG_OPERATION_REJECTED');
check(countRows($db,'metadata_ledger','99999999-9999-4999-8999-999999999999')===0,
    'fixed role A cannot write tenant B');
check(countRows($db,'service_request_nonce','99999999-9999-4999-8999-999999999999')===0,
    'B nonce never created');

deniedSql($db,'DELETE FROM portal_commercial_ci.service_request_nonce');
deniedSql($db,'TRUNCATE portal_commercial_ci.service_request_nonce');
deniedSql($db,'UPDATE portal_commercial_ci.service_request_nonce SET observed_at=now()');

check(countRows($db,'metadata_ledger',$org)===1,'final ledger count');
check(countRows($db,'pending_outbox',$org)===1,'final outbox count');
check(countRows($db,'service_request_nonce',$org)===3,'final nonce count');
echo "F119 PASS: {$checks} checks; test-server HMAC, real PG18 nonce replay, tenant RLS and atomic outbox. No CRM receipt.\n";
