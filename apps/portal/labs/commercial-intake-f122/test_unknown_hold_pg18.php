<?php

declare(strict_types=1);

// F122: PG18 CI-only delivery hold tests. Not a live queue worker or CRM ACK.
require_once __DIR__.'/../commercial-intake-f119/PortalF119SyntheticServiceReservation.php';

use Imagine\Portal\Labs\CommercialIntakeF119\PortalF119SyntheticServiceReservation as Stage;

if (getenv('GITHUB_ACTIONS')!=='true'
    || getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    || getenv('PORTAL_F119_PG_LAB')!=='1'
    || getenv('PORTAL_F122_GATE_LAB')!=='1'
    || getenv('DB_HOST')!=='127.0.0.1'
    || getenv('DB_DATABASE')!=='portal_commercial_lab'
    || !extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "F122 refuses non-disposable PostgreSQL CI.\n");
    exit(2);
}
$checks=0;
function check(bool $condition,string $description): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('F122 FAIL: '.$description);
    }
}
function envFor(string $submission): array
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
function bindingFor(array $env): array
{
    $result=[];
    foreach ([
        'organization_reference','binding_reference','site_reference',
        'form_reference','destination_reference'
    ] as $key) {
        $result[$key]=$env[$key];
    }
    return $result;
}
function stage(PDO $db,string $submission,string $nonce): void
{
    $e=envFor($submission);
    $key=random_bytes(48);
    $iat=time();
    $exp=$iat+120;
    $parts=['imagine.portal.synthetic-service-request.v1'];
    foreach ([
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ] as $ref) {
        $parts[]=strtolower($e[$ref]);
    }
    array_push($parts,(string)$iat,(string)$exp,$nonce);
    $mac=hash_hmac('sha256',implode("\n",$parts),$key);
    $service=new Stage($key);
    $value=$service->observe($db,$e,bindingFor($e),[
        'issued_at'=>$iat,'expires_at'=>$exp,'nonce'=>$nonce,'mac'=>$mac
    ]);
    check($value['reasonCode']==='LAB_METADATA_AND_OUTBOX_WRITTEN',
        'metadata/outbox staged for isolated gate test');
    check($value['crmPersisted']===false,'staging never confirms CRM');
}
function fnAs(PDO $db,string $role,string $fn,string $sub,int $version): ?string
{
    check(in_array($role,['portal_f122_worker_a','portal_f122_worker_b'],true),
        'fixed CI role allowlist');
    check(in_array($fn,['claim_lab_a','note_unknown_lab_a'],true),
        'fixed function allowlist');
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE '.$role);
        $stmt=$db->prepare(
            'SELECT portal_commercial_ci.'.$fn.
            '(CAST(:submission AS uuid), CAST(:version AS bigint))'
        );
        $stmt->execute(['submission'=>$sub,'version'=>$version]);
        $result=$stmt->fetchColumn();
        $db->commit();
        // PDO pgsql converts boolean SQL results into PHP bool. Normalise
        // only for the test's stable SQL assertion format (t/f).
        if ($result===null) {
            return null;
        }
        if (is_bool($result)) {
            return $result?'t':'f';
        }
        return (string)$result;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
function entry(PDO $db,string $sub): ?array
{
    $stmt=$db->prepare(<<<'SQL'
SELECT state,claim_version,attempts,lease_until
FROM portal_commercial_ci.delivery_gate
WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
  AND submission_reference=CAST(:sub AS uuid)
SQL);
    $stmt->execute(['sub'=>$sub]);
    $value=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($value)?$value:null;
}
function forceExpiry(PDO $db,string $sub): void
{
    $stmt=$db->prepare(<<<'SQL'
UPDATE portal_commercial_ci.delivery_gate
SET lease_until=transaction_timestamp()-interval '1 second'
WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
  AND submission_reference=CAST(:sub AS uuid) AND state='claimed'
SQL);
    $stmt->execute(['sub'=>$sub]);
    check($stmt->rowCount()===1,'only privileged disposable CI fixture advances lease');
}

$db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
    'postgres',(string)getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES=>false,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
check($db->query('SELECT current_database()')->fetchColumn()
    ==='portal_commercial_lab','dedicated source database');
$prior=(int)$db->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='portal_commercial_ci' AND table_name='delivery_gate'")->fetchColumn();
check($prior===0,'no pre-existing gate table');
$db->exec((string)file_get_contents(__DIR__.'/unknown_hold_pg18.sql'));

$uncertain='e1e1e1e1-e1e1-4e1e-8e1e-e1e1e1e1e1e1';
$exhausted='e2e2e2e2-e2e2-4e2e-8e2e-e2e2e2e2e2e2';
$orphan='e3e3e3e3-e3e3-4e3e-8e3e-e3e3e3e3e3e3';

stage($db,$uncertain,'a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1');
stage($db,$exhausted,'a2a2a2a2-a2a2-4a2a-8a2a-a2a2a2a2a2a2');

check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$orphan,0)===null,
    'cannot claim missing outbox event');
check(fnAs($db,'portal_f122_worker_a','note_unknown_lab_a',$uncertain,1)==='f',
    'cannot fabricate unknown before a real claim');
check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$uncertain,0)==='1',
    'first guarded lease version');
check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$uncertain,0)===null,
    'parallel/duplicate first lease claim is not granted');
check(fnAs($db,'portal_f122_worker_a','note_unknown_lab_a',$uncertain,2)==='f',
    'wrong fencing token cannot register uncertainty');

// A crashed client may report uncertainty AFTER lease expiry, provided no
// new worker already obtained an incremented fencing token.
forceExpiry($db,$uncertain);
check(fnAs($db,'portal_f122_worker_a','note_unknown_lab_a',$uncertain,1)==='t',
    'current fencing owner records unknown even after expiry');
check(fnAs($db,'portal_f122_worker_a','note_unknown_lab_a',$uncertain,1)==='f',
    'replaying the same unknown observation cannot rewrite the record');
check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$uncertain,1)===null,
    'unknown result permanently blocks automatic expired-lease retry');
check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$uncertain,0)===null,
    'unknown result cannot be reset via initial claim');
$held=entry($db,$uncertain);
check(is_array($held)
    && $held['state']==='unknown'
    && (int)$held['claim_version']===1
    && (int)$held['attempts']===1
    && $held['lease_until']===null,
    'durable unknown hold cannot claim delivery or reset attempts');

// Separate logical submission still proceeds, with a bounded retry count.
check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$exhausted,0)==='1',
    'independent submission gets first lease');
forceExpiry($db,$exhausted);
check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$exhausted,1)==='2',
    'second lease version after simulated crash');
check(fnAs($db,'portal_f122_worker_a','note_unknown_lab_a',$exhausted,1)==='f',
    'expired first version cannot block newer claimant');
forceExpiry($db,$exhausted);
check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$exhausted,2)==='3',
    'third bounded attempt with fencing token');
forceExpiry($db,$exhausted);
check(fnAs($db,'portal_f122_worker_a','claim_lab_a',$exhausted,3)===null,
    'fourth automatic attempt forbidden');
$last=entry($db,$exhausted);
check(is_array($last)
    && $last['state']==='claimed'
    && (int)$last['claim_version']===3
    && (int)$last['attempts']===3,
    'exhausted entry kept in ledger, never marked delivered');

// RLS and grants: B may not read A's holds or invoke tenant-A functions.
$db->beginTransaction();
try {
    $db->exec('SET LOCAL ROLE portal_f122_worker_b');
    check((int)$db->query('SELECT count(*) FROM portal_commercial_ci.delivery_gate')->fetchColumn()===0,
        'B cannot read A entries');
    $db->exec("SET app.lab_tenant_id='11111111-1111-4111-8111-111111111111'");
    check((int)$db->query('SELECT count(*) FROM portal_commercial_ci.delivery_gate')->fetchColumn()===0,
        'tenant GUC spoofing cannot change RLS');
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
try {
    fnAs($db,'portal_f122_worker_b','claim_lab_a',$exhausted,3);
    throw new RuntimeException('F122 FAIL: unauthorized B worker executed A function');
} catch (PDOException) {
    ++$checks;
}
foreach ([
    "DELETE FROM portal_commercial_ci.delivery_gate",
    "TRUNCATE portal_commercial_ci.delivery_gate",
    "UPDATE portal_commercial_ci.delivery_gate SET attempts=0",
    "INSERT INTO portal_commercial_ci.delivery_gate
       (organization_reference,submission_reference,state,claim_version,attempts,lease_until)
       VALUES ('11111111-1111-4111-8111-111111111111',
               'e3e3e3e3-e3e3-4e3e-8e3e-e3e3e3e3e3e3','claimed',1,1,now())",
] as $sql) {
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE portal_f122_worker_a');
        $db->exec($sql);
        $db->rollBack();
        throw new RuntimeException('F122 FAIL: worker bypassed guarded function');
    } catch (PDOException) {
        $db->rollBack();
        ++$checks;
    }
}
check((int)$db->query("SELECT count(*) FROM information_schema.columns WHERE table_schema='portal_commercial_ci' AND table_name='delivery_gate'")->fetchColumn()===7,
    'gate consists only of seven metadata/state columns');
check(entry($db,$uncertain)['state']==='unknown',
    'negative tests did not erase unknown hold');
echo "F122 PASS: {$checks} CI-only PostgreSQL18 fence/lease/unknown-hold assertions; no fake CRM ACK.\n";
