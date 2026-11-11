<?php

declare(strict_types=1);

// F124: CI-only combined F121 (two independent PG18 databases) + F123 fence.
// This does NOT integrate with the Imagine CRM or acknowledge a visitor.
require_once __DIR__.'/../commercial-intake-f119/PortalF119SyntheticServiceReservation.php';

use Imagine\Portal\Labs\CommercialIntakeF119\PortalF119SyntheticServiceReservation as Stage;

foreach ([
    'GITHUB_ACTIONS'=>'true',
    'RUNNER_ENVIRONMENT'=>'github-hosted',
    'PORTAL_F119_PG_LAB'=>'1',
    'PORTAL_F121_CRM_MOCK_LAB'=>'1',
    'PORTAL_F122_GATE_LAB'=>'1',
    'PORTAL_F123_DISPATCH_LAB'=>'1',
    'PORTAL_F124_RECONCILIATION_LAB'=>'1',
    'DB_HOST'=>'127.0.0.1',
    'DB_DATABASE'=>'portal_commercial_lab',
] as $name=>$expected) {
    if (getenv($name)!==$expected) {
        fwrite(STDERR,"F124 refuses non-disposable CI environment.\n");
        exit(2);
    }
}
if (!extension_loaded('pdo_pgsql')) {
    exit(2);
}
$reqHex=getenv('PORTAL_F121_REQ_KEY_HEX');
$receiptHex=getenv('PORTAL_F121_RECEIPT_KEY_HEX');
if (!is_string($reqHex)||!is_string($receiptHex)
    || !preg_match('/\A[0-9a-f]{96}\z/D',$reqHex)
    || !preg_match('/\A[0-9a-f]{96}\z/D',$receiptHex)
    || $reqHex===$receiptHex) {
    throw new RuntimeException('F124 needs two distinct ephemeral CI keys');
}
$requestKey=hex2bin($reqHex);
$receiptKey=hex2bin($receiptHex);
$checks=0;
function assertLab(bool $pass,string $reason): void
{
    global $checks;
    ++$checks;
    if (!$pass) {
        throw new RuntimeException('F124 FAIL: '.$reason);
    }
}
function openLab(string $database): PDO
{
    assertLab(in_array($database,
        ['portal_commercial_lab','portal_crm_receiver_lab'],true),
        'allowlisted ephemeral PG18 DB');
    $db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname='.$database,
        'postgres',(string)getenv('DB_PASSWORD'),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
    assertLab($db->query('SELECT current_database()')->fetchColumn()===$database,
        'verified database identity');
    return $db;
}
function freshUuid(): string
{
    $b=bin2hex(random_bytes(16));
    $b[12]='4';
    $b[16]=dechex((hexdec($b[16])&3)|8);
    return substr($b,0,8).'-'.substr($b,8,4).'-'
        .substr($b,12,4).'-'.substr($b,16,4).'-'.substr($b,20,12);
}
function fixtureEnvelope(string $submission): array
{
    return [
        'protocol'=>'imagine.lead-intake','schema_version'=>1,
        'organization_reference'=>'11111111-1111-4111-8111-111111111111',
        'binding_reference'=>'22222222-2222-4222-8222-222222222222',
        'submission_reference'=>$submission,
        'site_reference'=>'44444444-4444-4444-8444-444444444444',
        'form_reference'=>'55555555-5555-4555-8555-555555555555',
        'destination_reference'=>'66666666-6666-4666-8666-666666666666',
    ];
}
function signedParts(string $domain,array $env,int $iat,int $exp,string $nonce): string
{
    $fields=[$domain];
    foreach ([
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ] as $field) {
        $fields[]=strtolower($env[$field]);
    }
    array_push($fields,(string)$iat,(string)$exp,$nonce);
    return implode("\n",$fields);
}
function stageSource(PDO $portal,array $env): void
{
    $b=[];
    foreach (['organization_reference','binding_reference',
        'site_reference','form_reference','destination_reference'] as $field) {
        $b[$field]=$env[$field];
    }
    $key=random_bytes(48);
    $iat=time();
    $exp=$iat+120;
    $nonce=freshUuid();
    $stage=new Stage($key);
    $result=$stage->observe($portal,$env,$b,[
        'issued_at'=>$iat,'expires_at'=>$exp,'nonce'=>$nonce,
        'mac'=>hash_hmac('sha256',signedParts(
            'imagine.portal.synthetic-service-request.v1',
            $env,$iat,$exp,$nonce),$key),
    ]);
    assertLab($result['reasonCode']==='LAB_METADATA_AND_OUTBOX_WRITTEN',
        'staged only the source metadata and outbox');
    assertLab($result['crmPersisted']===false,
        'source ledger never constitutes remote proof');
}
function gateCall(PDO $portal,string $function,string $submission,int $version): mixed
{
    assertLab(in_array($function,
        ['claim_lab_a','begin_dispatch_lab_a','note_unknown_lab_a'],true),
        'fixed database function allowlist');
    $portal->beginTransaction();
    try {
        $portal->exec('SET LOCAL ROLE portal_f122_worker_a');
        $stmt=$portal->prepare('SELECT portal_commercial_ci.'.$function.
            '(CAST(:sub AS uuid),CAST(:ver AS bigint))');
        $stmt->execute(['sub'=>$submission,'ver'=>$version]);
        $value=$stmt->fetchColumn();
        $portal->commit();
        return is_bool($value) ? ($value?'t':'f')
            : ($value===null?null:(string)$value);
    } catch (Throwable $e) {
        $portal->rollBack();
        throw $e;
    }
}
function gateState(PDO $portal,string $submission): array
{
    $stmt=$portal->prepare("SELECT state,claim_version,attempts,lease_until
        FROM portal_commercial_ci.delivery_gate
        WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
          AND submission_reference=CAST(:sub AS uuid)");
    $stmt->execute(['sub'=>$submission]);
    $result=$stmt->fetch(PDO::FETCH_ASSOC);
    assertLab(is_array($result),'source fence still exists');
    return $result;
}
function countRemote(PDO $mock,string $table,string $submission): int
{
    assertLab(in_array($table,['received_submissions','request_nonces'],true),
        'remote table fixed');
    $stmt=$mock->prepare("SELECT count(*) FROM mock_crm_ci.{$table}
        WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
          AND submission_reference=CAST(:sub AS uuid)");
    $stmt->execute(['sub'=>$submission]);
    return (int)$stmt->fetchColumn();
}
/** @return array{0:int,1:array<string,mixed>} */
function mockHttp(array $env,string $key,string $domain,bool $loseReply=false): array
{
    $iat=time();
    $exp=$iat+120;
    $nonce=freshUuid();
    $headers=[
        'Content-Type: application/json','Connection: close',
        'X-Lab-Issued-At: '.$iat,'X-Lab-Expires-At: '.$exp,
        'X-Lab-Nonce: '.$nonce,
        'X-Lab-Signature: '.hash_hmac('sha256',
            signedParts($domain,$env,$iat,$exp,$nonce),$key),
    ];
    if ($loseReply) {
        $headers[]='X-Lab-Drop-Receipt: 1';
    }
    $path=$domain==='imagine.portal.synthetic-crm-lookup.v1'
        ? '/__lab/crm/lookup':'/__lab/crm/deliver';
    $context=stream_context_create(['http'=>[
        'method'=>'POST','header'=>implode("\r\n",$headers),
        'content'=>json_encode($env,JSON_THROW_ON_ERROR),
        'timeout'=>8,'ignore_errors'=>true,'follow_location'=>0,
    ]]);
    $raw=@file_get_contents('http://127.0.0.1:19121'.$path,false,$context);
    $responseHeaders=$http_response_header??[];
    assertLab(is_string($raw) && isset($responseHeaders[0]),
        'loopback mock returned HTTP response');
    assertLab(preg_match('/\AHTTP\/[0-9.]+\s+([0-9]{3})\b/',
        $responseHeaders[0],$match)===1,'valid response status');
    $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    assertLab(is_array($body)
        && ($body['mode']??null)==='TWO_DISPOSABLE_DB_MOCK_NOT_REAL_CRM',
        'response comes from disposable mock only');
    foreach (['realCrmPersisted','canAcknowledgeVisitor',
        'productionAuthorized'] as $field) {
        assertLab(($body[$field]??null)===false,
            'mock must never assert '.$field);
    }
    return [(int)$match[1],$body];
}
function verifyMockReceipt(array $response,array $expected,string $receiptKey): bool
{
    $r=$response['receipt']??null;
    if (!is_array($r) || count($r)!==4
        || !is_int($r['issued_at']??null)
        || !is_int($r['expires_at']??null)
        || !is_string($r['nonce']??null)
        || !is_string($r['mac']??null)
        || preg_match('/\A[0-9a-f-]{36}\z/D',$r['nonce'])!==1
        || preg_match('/\A[0-9a-f]{64}\z/D',$r['mac'])!==1
        || $r['issued_at']>time()+30
        || $r['expires_at']<=time()
        || $r['expires_at']-$r['issued_at']>300
        || $r['expires_at']<=$r['issued_at']) {
        return false;
    }
    $expectedMac=hash_hmac('sha256',signedParts(
        'imagine.lead-intake.synthetic-receipt.v1',
        $expected,$r['issued_at'],$r['expires_at'],$r['nonce']),$receiptKey);
    return hash_equals($expectedMac,$r['mac']);
}

$portal=openLab('portal_commercial_lab');
$mock=openLab('portal_crm_receiver_lab');
$submission='d7d7d7d7-d7d7-4d7d-8d7d-d7d7d7d7d7d7';
$env=fixtureEnvelope($submission);
assertLab(countRemote($mock,'received_submissions',$submission)===0,
    'remote mock starts empty for this exact submission');
stageSource($portal,$env);
assertLab(gateCall($portal,'claim_lab_a',$submission,0)==='1',
    'source claims the first lease');
assertLab(gateCall($portal,'begin_dispatch_lab_a',$submission,1)==='t',
    'source commits the dispatch fence BEFORE HTTP');
assertLab(gateState($portal,$submission)['state']==='dispatching',
    'source persisted dispatching before remote request');

[$code,$lost]=mockHttp($env,$requestKey,
    'imagine.portal.synthetic-service-request.v1',true);
assertLab($code===503
    && ($lost['reasonCode']??null)==='UNKNOWN_OUTCOME_NO_RECEIPT',
    'mock intentionally lost response after DB commit');
assertLab(!isset($lost['receipt'])
    && ($lost['mockReceiverCommitted']??null)===false,
    'lost response does not fabricate proof');
assertLab(countRemote($mock,'received_submissions',$submission)===1,
    'independent mock database committed exactly one record');
assertLab(countRemote($mock,'request_nonces',$submission)===1,
    'exactly one remote delivery attempt occurred');
assertLab(gateCall($portal,'claim_lab_a',$submission,1)===null,
    'no retry while dispatch may have committed remotely');
assertLab(gateCall($portal,'note_unknown_lab_a',$submission,1)==='t',
    'uncertain result recorded durably after unknown HTTP status');
assertLab(gateState($portal,$submission)['state']==='unknown',
    'origin still cannot assert CRM delivery');

[$status,$notTrusted]=mockHttp($env,random_bytes(48),
    'imagine.portal.synthetic-crm-lookup.v1');
assertLab($status===401 && !isset($notTrusted['receipt']),
    'wrong lookup key cannot receive a signed mock receipt');
assertLab(gateState($portal,$submission)['state']==='unknown',
    'failed read-back cannot rewrite origin');

[$status,$readBack]=mockHttp($env,$requestKey,
    'imagine.portal.synthetic-crm-lookup.v1');
assertLab($status===200
    && ($readBack['reasonCode']??null)==='LAB_MOCK_RECEIPT_FROM_READ_BACK',
    'separate authenticated lookup finds persisted fixture');
assertLab(verifyMockReceipt($readBack,$env,$receiptKey),
    'read-back receipt matches exact six UUIDs and receipt key');
assertLab(!verifyMockReceipt($readBack,$env,$requestKey),
    'request key cannot authorize receipt verification');
$changed=$env;
$changed['destination_reference']='77777777-7777-4777-8777-777777777777';
assertLab(!verifyMockReceipt($readBack,$changed,$receiptKey),
    'changing destination invalidates the receipt');
$tampered=$readBack;
$tampered['receipt']['mac']=str_repeat('0',64);
assertLab(!verifyMockReceipt($tampered,$env,$receiptKey),
    'altering MAC invalidates the receipt');
assertLab(gateCall($portal,'claim_lab_a',$submission,1)===null,
    'verified mock read-back does not implicitly allow retry');
assertLab(gateState($portal,$submission)['state']==='unknown',
    'no delivered/acknowledged state added to source');
assertLab(countRemote($mock,'received_submissions',$submission)===1
    && countRemote($mock,'request_nonces',$submission)===1,
    'reconciliation did not resend or duplicate remote delivery');
assertLab((int)$portal->query("SELECT count(*) FROM information_schema.columns
    WHERE table_schema='portal_commercial_ci' AND table_name='delivery_gate'")
    ->fetchColumn()===7, 'source stores no receipt or personal payload');

echo "F124 PASS: {$checks} signed read-back + durable unknown hold; realCrmPersisted=false.\n";
