<?php

declare(strict_types=1);

// F132 CI-only: current Portal F123 source fence + real CRM F3-41 lab PHP.
// Not operational, no personal data, external CRM API or visitor ACK.
require_once __DIR__.'/../commercial-intake-f119/PortalF119SyntheticServiceReservation.php';
use Imagine\Portal\Labs\CommercialIntakeF119\PortalF119SyntheticServiceReservation as Stage;

foreach ([
    'GITHUB_ACTIONS'=>'true',
    'RUNNER_ENVIRONMENT'=>'github-hosted',
    'PORTAL_F119_PG_LAB'=>'1',
    'PORTAL_F132_REAL_CRM_FENCE_LAB'=>'1',
    'CRM_F342_CROSS_REPO_LAB'=>'1',
    'DB_HOST'=>'127.0.0.1',
    'DB_DATABASE'=>'portal_commercial_lab',
] as $name=>$expected) {
    if (getenv($name)!==$expected) {
        fwrite(STDERR,"F132 refuses non-disposable CI.\n");
        exit(2);
    }
}
if (!extension_loaded('pdo_pgsql')) {
    exit(2);
}
$requestHex=getenv('CRM_F341_REQUEST_KEY_HEX');
$receiptHex=getenv('CRM_F341_RECEIPT_KEY_HEX');
if (!is_string($requestHex)||!is_string($receiptHex)
    || !preg_match('/\A[0-9a-f]{96}\z/D',$requestHex)
    || !preg_match('/\A[0-9a-f]{96}\z/D',$receiptHex)
    || $requestHex===$receiptHex) {
    throw new RuntimeException('F132 requires distinct ephemeral CI HMAC keys');
}
$requestKey=hex2bin($requestHex);
$receiptKey=hex2bin($receiptHex);
$checks=0;
function evidence132(bool $ok,string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException('F132 FAIL: '.$message);
    }
}
function db132(string $name,int $port): PDO
{
    evidence132(
        ($name==='portal_commercial_lab'&&$port===5433)
        ||($name==='imagine_crm_handoff_ci'&&$port===5432),
        'exactly two allowlisted fixture databases');
    $db=new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname='.$name,
        'postgres',(string)getenv('DB_PASSWORD'),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
    evidence132($db->query('SELECT current_database()')->fetchColumn()===$name,
        'database identity fixed');
    return $db;
}
function uuid132(): string
{
    $h=bin2hex(random_bytes(16));
    $h[12]='4';
    $h[16]=dechex((hexdec($h[16])&3)|8);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'
        .substr($h,16,4).'-'.substr($h,20,12);
}
function body132(string $sub): array
{
    return [
        'protocol'=>'imagine.lead-intake','schema_version'=>1,
        'organization_reference'=>'11111111-1111-4111-8111-111111111111',
        'binding_reference'=>'22222222-2222-4222-8222-222222222222',
        'submission_reference'=>$sub,
        'site_reference'=>'44444444-4444-4444-8444-444444444444',
        'form_reference'=>'55555555-5555-4555-8555-555555555555',
        'destination_reference'=>'66666666-6666-4666-8666-666666666666',
    ];
}
function signedText132(array $body,string $domain,int $iat,int $exp,string $nonce): string
{
    $parts=[$domain];
    foreach (['organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'] as $field) {
        $parts[]=strtolower($body[$field]);
    }
    array_push($parts,(string)$iat,(string)$exp,$nonce);
    return implode("\n",$parts);
}
function sourceStage132(PDO $db,array $body): void
{
    $bindings=[];
    foreach (['organization_reference','binding_reference','site_reference',
        'form_reference','destination_reference'] as $field) {
        $bindings[$field]=$body[$field];
    }
    $key=random_bytes(48);
    $iat=time();
    $exp=$iat+120;
    $nonce=uuid132();
    $result=(new Stage($key))->observe($db,$body,$bindings,[
        'issued_at'=>$iat,'expires_at'=>$exp,'nonce'=>$nonce,
        'mac'=>hash_hmac('sha256',signedText132($body,
            Stage::DOMAIN,$iat,$exp,$nonce),$key),
    ]);
    evidence132($result['reasonCode']==='LAB_METADATA_AND_OUTBOX_WRITTEN',
        'Portal F119 actually stages its own metadata/outbox');
    evidence132($result['crmPersisted']===false,'source never claims CRM delivery');
}
function gate132(PDO $db,string $function,string $sub,int $version): ?string
{
    evidence132(in_array($function,['claim_lab_a','begin_dispatch_lab_a',
        'note_unknown_lab_a'],true),'fixed F123 function allowlist');
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE portal_f122_worker_a');
        $stmt=$db->prepare('SELECT portal_commercial_ci.'.$function.
            '(CAST(:sub AS uuid),CAST(:ver AS bigint))');
        $stmt->execute(['sub'=>$sub,'ver'=>$version]);
        $v=$stmt->fetchColumn();
        $db->commit();
        return is_bool($v)?($v?'t':'f'):($v===null?null:(string)$v);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
function call132(array $body,string $key,string $domain,bool $drop=false): array
{
    $iat=time();
    $exp=$iat+120;
    $nonce=uuid132();
    $headers=[
        'Content-Type: application/json','Connection: close',
        'X-Lab-Issued-At: '.$iat,
        'X-Lab-Expires-At: '.$exp,
        'X-Lab-Nonce: '.$nonce,
        'X-Lab-Signature: '.hash_hmac('sha256',
            signedText132($body,$domain,$iat,$exp,$nonce),$key),
    ];
    if ($drop) {
        $headers[]='X-Lab-Drop-Receipt: 1';
    }
    $route=$domain==='imagine.portal.synthetic-crm-lookup.v1'
        ? '/__lab/crm/readback':'/__lab/crm/ingest';
    $context=stream_context_create(['http'=>[
        'method'=>'POST','header'=>implode("\r\n",$headers),
        'content'=>json_encode($body,JSON_THROW_ON_ERROR),
        'ignore_errors'=>true,'timeout'=>8,'follow_location'=>0,
    ]]);
    $raw=@file_get_contents('http://127.0.0.1:19141'.$route,false,$context);
    $headersOut=$http_response_header??[];
    evidence132(is_string($raw)&&isset($headersOut[0]),
        'CRM PHP returned loopback HTTP');
    evidence132(preg_match('/\AHTTP\/[0-9.]+\s+([0-9]{3})\b/',
        $headersOut[0],$match)===1,'valid HTTP status');
    $response=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    evidence132(is_array($response)
        && ($response['schema']??null)==='imagine.crm.synthetic-portal-http.v1'
        && ($response['mode']??null)==='CI_ONLY_REAL_PG16_NOT_REAL_CRM_RECEIPT',
        'actual CRM lab class and schema provenance');
    foreach ([
        'realCrmPersisted','tenantAuthenticatedForProduction',
        'canAcknowledgeVisitor','canAutoRetryUnknownOutcome',
        'productionAuthorized'
    ] as $field) {
        evidence132(($response[$field]??null)===false,
            'operational authority must remain false: '.$field);
    }
    return [(int)$match[1],$response];
}
function remoteCount132(PDO $crm,string $table,string $sub): int
{
    evidence132(in_array($table,['metadata_receipts','request_nonces'],true),
        'exact remote table allowlist');
    $s=$crm->prepare('SELECT count(*) FROM crm_portal_handoff_ci.'.$table.
        " WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid".
        ' AND submission_reference=CAST(:sub AS uuid)');
    $s->execute(['sub'=>$sub]);
    return (int)$s->fetchColumn();
}
function localState132(PDO $db,string $sub): string
{
    $stmt=$db->prepare("SELECT state FROM portal_commercial_ci.delivery_gate
        WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
        AND submission_reference=CAST(:sub AS uuid)");
    $stmt->execute(['sub'=>$sub]);
    return (string)$stmt->fetchColumn();
}

$portal=db132('portal_commercial_lab',5433);
$crm=db132('imagine_crm_handoff_ci',5432);
evidence132($portal->query("SELECT to_regprocedure(
    'portal_commercial_ci.begin_dispatch_lab_a(uuid,bigint)')")->fetchColumn()
    !==null,'F123 pre-send fence installed on current Portal PG18');
$sub='d7d7d7d7-d7d7-4d7d-8d7d-d7d7d7d7d7d7';
$body=body132($sub);
evidence132(remoteCount132($crm,'metadata_receipts',$sub)===0,
    'independent CRM database starts without this logical submission');
sourceStage132($portal,$body);
evidence132(gate132($portal,'claim_lab_a',$sub,0)==='1',
    'lease acquired in the source database');
evidence132(gate132($portal,'begin_dispatch_lab_a',$sub,1)==='t',
    'dispatch flag COMMITTED before any HTTP bytes');
evidence132(gate132($portal,'begin_dispatch_lab_a',$sub,1)==='f',
    'pre-send action is irreversible on replay');
evidence132(localState132($portal,$sub)==='dispatching',
    'source state durably records possible remote IO');

[$code,$lost]=call132($body,$requestKey,
    'imagine.portal.synthetic-service-request.v1',true);
evidence132($code===503
    && ($lost['reasonCode']??null)==='UNKNOWN_OUTCOME_NO_RECEIPT'
    && !isset($lost['receipt']),
    'real CRM PHP commits then deliberately loses response');
evidence132(remoteCount132($crm,'metadata_receipts',$sub)===1
    && remoteCount132($crm,'request_nonces',$sub)===1,
    'CRM PG16 independently committed exactly one logical record/nonce');

// Simulated worker crash BEFORE it could call note_unknown. A later worker
// must NOT reclaim the expired lease, because HTTP could have committed.
$expiry=$portal->prepare("UPDATE portal_commercial_ci.delivery_gate
  SET lease_until=transaction_timestamp()-interval '2 seconds'
  WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
    AND submission_reference=CAST(:sub AS uuid)
    AND state='dispatching'");
$expiry->execute(['sub'=>$sub]);
evidence132($expiry->rowCount()===1,
    'only disposable PG superuser fixture expires remote-in-flight lease');
evidence132(gate132($portal,'claim_lab_a',$sub,1)===null,
    'expired dispatching lease cannot be claimed/retried');
evidence132(gate132($portal,'begin_dispatch_lab_a',$sub,1)==='f',
    'expired old owner cannot start second HTTP request');
evidence132(gate132($portal,'note_unknown_lab_a',$sub,1)==='t',
    'fenced claim owner can preserve unknown after expiry');
evidence132(localState132($portal,$sub)==='unknown',
    'origin retains result uncertainty rather than claiming delivery');

[$code,$lookedUp]=call132($body,$requestKey,
    'imagine.portal.synthetic-crm-lookup.v1');
evidence132($code===200
    && ($lookedUp['reasonCode']??null)==='LAB_MOCK_READBACK_SIGNED'
    && is_array($lookedUp['receipt']??null),
    'separate authenticated read-back returns only a lab receipt');
$receipt=$lookedUp['receipt'];
evidence132(count($receipt)===4
    && is_int($receipt['issued_at']??null)
    && is_int($receipt['expires_at']??null)
    && is_string($receipt['nonce']??null)
    && is_string($receipt['mac']??null)
    && $receipt['expires_at']>time()
    && $receipt['expires_at']>$receipt['issued_at']
    && hash_equals(hash_hmac('sha256',
        signedText132($body,'imagine.lead-intake.synthetic-receipt.v1',
            $receipt['issued_at'],$receipt['expires_at'],$receipt['nonce']),
        $receiptKey),$receipt['mac']),
    'read-back HMAC matches precisely the expected synthetic business context');
evidence132(gate132($portal,'claim_lab_a',$sub,1)===null
    && localState132($portal,$sub)==='unknown',
    'signed lab read-back never releases original unknown hold');
evidence132(remoteCount132($crm,'request_nonces',$sub)===1,
    'read-back never causes a second remote send');

echo "F132 PASS: {$checks} current Portal F123/CRM F3-41 PHP lost-ACK assertions; NO real lead or authorization.\n";
