<?php

declare(strict_types=1);

// F121: TWO independent throwaway DBs, loopback-only PHP CRM mock, synthetic
// UUID metadata. Neither a CRM API nor a durable production lead receipt.
require_once __DIR__.'/../commercial-intake-f119/PortalF119SyntheticServiceReservation.php';

use Imagine\Portal\Labs\CommercialIntakeF119\PortalF119SyntheticServiceReservation as Stage;

if (getenv('GITHUB_ACTIONS')!=='true'
    || getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    || getenv('PORTAL_F119_PG_LAB')!=='1'
    || getenv('PORTAL_F121_CRM_MOCK_LAB')!=='1'
    || getenv('DB_HOST')!=='127.0.0.1'
    || getenv('DB_DATABASE')!=='portal_commercial_lab'
    || !extension_loaded('pdo_pgsql')) {
    fwrite(STDERR,"F121 refuses non-disposable DB or non-GitHub runner.\n");
    exit(2);
}
$requestHex=getenv('PORTAL_F121_REQ_KEY_HEX');
$receiptHex=getenv('PORTAL_F121_RECEIPT_KEY_HEX');
if (!is_string($requestHex)||!is_string($receiptHex)
    || !preg_match('/\A[0-9a-f]{96}\z/D',$requestHex)
    || !preg_match('/\A[0-9a-f]{96}\z/D',$receiptHex)
    || $requestHex===$receiptHex) {
    throw new RuntimeException('F121 needs two distinct ephemeral test keys');
}
$requestKey=hex2bin($requestHex);
$receiptKey=hex2bin($receiptHex);
if (!is_string($requestKey)||!is_string($receiptKey)) {
    throw new RuntimeException('F121 test keys malformed');
}
$checks=0;
function check(bool $ok,string $msg): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        throw new RuntimeException('F121 FAIL: '.$msg);
    }
}
function makeDb(string $name): PDO
{
    check(in_array($name,['portal_commercial_lab','portal_crm_receiver_lab'],true),
        'database must be one of two ephemeral CI databases');
    $db=new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname='.$name,
        'postgres',(string)getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    check($db->query('SELECT current_database()')->fetchColumn()===$name,
        'database identity '.$name);
    return $db;
}
function envelope(string $submission): array
{
    return [
        'protocol'=>'imagine.lead-intake',
        'schema_version'=>1,
        'organization_reference'=>'11111111-1111-4111-8111-111111111111',
        'binding_reference'=>'22222222-2222-4222-8222-222222222222',
        'submission_reference'=>$submission,
        'site_reference'=>'44444444-4444-4444-8444-444444444444',
        'form_reference'=>'55555555-5555-4555-8555-555555555555',
        'destination_reference'=>'66666666-6666-4666-8666-666666666666',
    ];
}
function binding(array $env): array
{
    $b=[];
    foreach ([
        'organization_reference','binding_reference','site_reference',
        'form_reference','destination_reference'
    ] as $field) {
        $b[$field]=$env[$field];
    }
    return $b;
}
function uuid(): string
{
    $hex=bin2hex(random_bytes(16));
    $hex[12]='4';
    $hex[16]=dechex((hexdec($hex[16])&3)|8);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.
        substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
}
function signRequest(array $env,string $key,string $nonce,string $domain): array
{
    $iat=time();
    $exp=$iat+120;
    $fields=[$domain];
    foreach ([
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ] as $ref) {
        $fields[]=strtolower($env[$ref]);
    }
    array_push($fields,(string)$iat,(string)$exp,$nonce);
    return [
        'X-Lab-Issued-At'=>(string)$iat,
        'X-Lab-Expires-At'=>(string)$exp,
        'X-Lab-Nonce'=>$nonce,
        'X-Lab-Signature'=>hash_hmac('sha256',implode("\n",$fields),$key),
    ];
}
function stagePortal(PDO $portal,array $env,string $key): void
{
    $nonce=uuid();
    $auth=signRequest($env,$key,$nonce,'imagine.portal.synthetic-service-request.v1');
    $stage=new Stage($key);
    $result=$stage->observe($portal,$env,binding($env),[
        'issued_at'=>(int)$auth['X-Lab-Issued-At'],
        'expires_at'=>(int)$auth['X-Lab-Expires-At'],
        'nonce'=>$nonce,
        'mac'=>$auth['X-Lab-Signature'],
    ]);
    check($result['reasonCode']==='LAB_METADATA_AND_OUTBOX_WRITTEN',
        'Portal staged synthetic request and outbox');
    check($result['crmPersisted']===false,'Portal cannot claim CRM persistence');
}
function countDb(PDO $db,string $table,string $submission,string $database): int
{
    $tables=$database==='portal'
        ? ['metadata_ledger','pending_outbox']
        : ['received_submissions','request_nonces'];
    check(in_array($table,$tables,true),'table from explicit ephemeral allowlist');
    $schema=$database==='portal'?'portal_commercial_ci':'mock_crm_ci';
    $stmt=$db->prepare("SELECT count(*) FROM {$schema}.{$table} WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid AND submission_reference=CAST(:sub AS uuid)");
    $stmt->execute(['sub'=>$submission]);
    return (int)$stmt->fetchColumn();
}
/** @return array{0:int,1:array<string,mixed>} */
function callMock(array $env,array $auth,string $path='/__lab/crm/deliver',
    bool $drop=false): array
{
    $lines=['Content-Type: application/json','Connection: close'];
    foreach ($auth as $key=>$value) {
        check(preg_match('/\AX-Lab-[A-Za-z-]+\z/D',$key)===1,'safe test header key');
        check(is_string($value)&&preg_match('/\A[0-9a-f-]+\z/iD',$value)===1,
            'safe test header value');
        $lines[]=$key.': '.$value;
    }
    if ($drop) {
        $lines[]='X-Lab-Drop-Receipt: 1';
    }
    $context=stream_context_create([
        'http'=>[
            'method'=>'POST','header'=>implode("\r\n",$lines),
            'content'=>json_encode($env,JSON_THROW_ON_ERROR),
            'ignore_errors'=>true,'timeout'=>8,'follow_location'=>0,
        ]
    ]);
    $raw=@file_get_contents('http://127.0.0.1:19121'.$path,false,$context);
    $headers=$http_response_header??[];
    check(is_string($raw)&&isset($headers[0]),'HTTP mock response available');
    check(preg_match('/\AHTTP\/[0-9.]+\s+([0-9]{3})\b/',$headers[0],$match)===1,
        'HTTP status readable');
    $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    check(is_array($body),'JSON mock response');
    check($body['mode']==='TWO_DISPOSABLE_DB_MOCK_NOT_REAL_CRM','no real CRM identity');
    check($body['realCrmPersisted']===false,'never claim real CRM persistence');
    check($body['canAcknowledgeVisitor']===false,'never ack visitor');
    check($body['productionAuthorized']===false,'never authorize production');
    if (isset($body['receipt'])) {
        check(count($body['receipt'])===4,'receipt only metadata signature+time+nonce');
    }
    return [(int)$match[1],$body];
}
function verifyFixtureReceipt(array $response,array $expected,string $key): bool
{
    if (!isset($response['receipt'])||!is_array($response['receipt'])
        || count($response['receipt'])!==4) {
        return false;
    }
    $r=$response['receipt'];
    if (!isset($r['issued_at'],$r['expires_at'],$r['nonce'],$r['mac'])
        || !is_int($r['issued_at']) || !is_int($r['expires_at'])
        || !is_string($r['nonce']) || !is_string($r['mac'])
        || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$r['nonce'])
        || !preg_match('/\A[0-9a-f]{64}\z/D',$r['mac'])
        || $r['issued_at']<=0 || $r['issued_at']>time()+30
        || $r['expires_at']<=time()
        || $r['expires_at']-$r['issued_at']>300
        || $r['expires_at']<=$r['issued_at']) {
        return false;
    }
    $parts=['imagine.lead-intake.synthetic-receipt.v1'];
    foreach ([
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ] as $ref) {
        $parts[]=strtolower($expected[$ref]);
    }
    array_push($parts,(string)$r['issued_at'],(string)$r['expires_at'],$r['nonce']);
    return hash_equals(hash_hmac('sha256',implode("\n",$parts),$key),$r['mac']);
}
function expectStatus(array $response,int $status,string $reason): void
{
    check($response[0]===$status,'HTTP status '.$status);
    check($response[1]['reasonCode']===$reason,'reason '.$reason);
}

$portal=makeDb('portal_commercial_lab');
$mock=makeDb('portal_crm_receiver_lab');
$portalKey=random_bytes(48);

// No mock CRM store exists in the Portal database, and vice versa.
$portalStore=$portal->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='mock_crm_ci'")->fetchColumn();
$mockStore=$mock->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='portal_commercial_ci'")->fetchColumn();
check((int)$portalStore===0&& (int)$mockStore===0,'independent database schemas');

$first=envelope('d1d1d1d1-d1d1-4d1d-8d1d-d1d1d1d1d1d1');
stagePortal($portal,$first,$portalKey);
check(countDb($portal,'pending_outbox',$first['submission_reference'],'portal')===1,
    'Portal has exactly one pending outbox row');
check(countDb($mock,'received_submissions',$first['submission_reference'],'mock')===0,
    'separate CRM mock database starts empty');

$bad=signRequest($first,random_bytes(48),uuid(),
    'imagine.portal.synthetic-service-request.v1');
expectStatus(callMock($first,$bad),401,'SIGNED_REQUEST_REJECTED');
check(countDb($mock,'received_submissions',$first['submission_reference'],'mock')===0,
    'bad signature may not write mock CRM');

$auth=signRequest($first,$requestKey,uuid(),
    'imagine.portal.synthetic-service-request.v1');
[$status,$answer]=callMock($first,$auth);
expectStatus([$status,$answer],202,'MOCK_COMMITTED_FIRST');
check($answer['mockReceiverCommitted']===true,'mock committed only after PG');
check(verifyFixtureReceipt($answer,$first,$receiptKey),'signed receipt tied to pinned context');
check(!verifyFixtureReceipt($answer,$first,$requestKey),'request key cannot validate receipt');
$wrongExpected=$first;
$wrongExpected['destination_reference']='77777777-7777-4777-8777-777777777777';
check(!verifyFixtureReceipt($answer,$wrongExpected,$receiptKey),'wrong destination cannot validate receipt');
$altered=$answer;
$altered['receipt']['mac']=str_repeat('a',64);
check(!verifyFixtureReceipt($altered,$first,$receiptKey),'tampered receipt cannot validate');
$expired=$answer;
$expired['receipt']['expires_at']=time()-1;
check(!verifyFixtureReceipt($expired,$first,$receiptKey),'expired receipt cannot validate');
check(countDb($mock,'received_submissions',$first['submission_reference'],'mock')===1,
    'mock receiver committed exactly one original row');
check(countDb($mock,'request_nonces',$first['submission_reference'],'mock')===1,
    'mock receiver committed one attempt nonce');

[$status,$repeat]=callMock($first,$auth);
expectStatus([$status,$repeat],409,'NONCE_REPLAY_OR_UNVERIFIED');
check(!isset($repeat['receipt']),'same nonce cannot obtain new receipt');
$secondAuth=signRequest($first,$requestKey,uuid(),
    'imagine.portal.synthetic-service-request.v1');
[$status,$duplicate]=callMock($first,$secondAuth);
expectStatus([$status,$duplicate],202,'MOCK_COMMITTED_REPLAY_OF_LOGICAL_SUBMISSION');
check(verifyFixtureReceipt($duplicate,$first,$receiptKey),'repeat logical submission still pinned');
check(countDb($mock,'received_submissions',$first['submission_reference'],'mock')===1,
    'new nonce must not create another logical lead');
check(countDb($mock,'request_nonces',$first['submission_reference'],'mock')===2,
    'new attempt marker is separate from business submission id');

// The simulated transport failure is AFTER a real commit, while the caller
// receives no receipt and is never permitted to acknowledge visitors.
$unknown=envelope('d2d2d2d2-d2d2-4d2d-8d2d-d2d2d2d2d2d2');
stagePortal($portal,$unknown,$portalKey);
$unknownAuth=signRequest($unknown,$requestKey,uuid(),
    'imagine.portal.synthetic-service-request.v1');
[$status,$lost]=callMock($unknown,$unknownAuth,'/__lab/crm/deliver',true);
expectStatus([$status,$lost],503,'UNKNOWN_OUTCOME_NO_RECEIPT');
check(!isset($lost['receipt']),'lost reply must NOT be fabricated');
check($lost['mockReceiverCommitted']===false,'error response must not claim persistence');
check(countDb($mock,'received_submissions',$unknown['submission_reference'],'mock')===1,
    'mock independently retained the request despite lost response');
check(countDb($mock,'request_nonces',$unknown['submission_reference'],'mock')===1,
    'unknown outcome must not be automatically retried by the client');
$lookupAuth=signRequest($unknown,$requestKey,uuid(),
    'imagine.portal.synthetic-crm-lookup.v1');
[$status,$recovered]=callMock($unknown,$lookupAuth,'/__lab/crm/lookup');
expectStatus([$status,$recovered],200,'LAB_MOCK_RECEIPT_FROM_READ_BACK');
check(verifyFixtureReceipt($recovered,$unknown,$receiptKey),
    'signed lookup read-back proves only mock DB record exists');
check(countDb($mock,'request_nonces',$unknown['submission_reference'],'mock')===1,
    'lookup must not rewrite the attempt nonce ledger');

$absent=envelope('d3d3d3d3-d3d3-4d3d-8d3d-d3d3d3d3d3d3');
$absentAuth=signRequest($absent,$requestKey,uuid(),
    'imagine.portal.synthetic-crm-lookup.v1');
[$status,$notFound]=callMock($absent,$absentAuth,'/__lab/crm/lookup');
expectStatus([$status,$notFound],404,'NOT_VERIFIED_IN_MOCK_RECEIVER');
check(!isset($notFound['receipt']),'absent record never returns receipt');

$otherTenant=$first;
$otherTenant['organization_reference']='99999999-9999-4999-8999-999999999999';
$forgedAuth=signRequest($otherTenant,$requestKey,uuid(),
    'imagine.portal.synthetic-service-request.v1');
[$status,$forged]=callMock($otherTenant,$forgedAuth);
expectStatus([$status,$forged],403,'PINNED_CONTEXT_REJECTED');
check(countDb($mock,'received_submissions',$first['submission_reference'],'mock')===1,
    'forgery cannot change original persisted record');

$badRoute=callMock($first,$secondAuth,'/api/crm/lead');
expectStatus($badRoute,404,'NOT_PUBLICLY_AVAILABLE');
check(countDb($portal,'metadata_ledger',$first['submission_reference'],'portal')===1,
    'source record remains separate from mock receiver');
check(countDb($portal,'pending_outbox',$unknown['submission_reference'],'portal')===1,
    'unknown outcome cannot remove original pending event');
echo "F121 PASS: {$checks} checks; two distinct real ephemeral PG18 databases, signed mock post-commit receipt, unknown-outcome lookup, no real CRM delivery.\n";
