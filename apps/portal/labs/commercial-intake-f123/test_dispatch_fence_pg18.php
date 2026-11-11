<?php

declare(strict_types=1);

// F123: ONLY after F117-F122 in a disposable GitHub Actions PG18 database.
// No HTTP sender, no real CRM, and no synthetic receipt promoted to an ACK.
require_once __DIR__.'/../commercial-intake-f119/PortalF119SyntheticServiceReservation.php';

use Imagine\Portal\Labs\CommercialIntakeF119\PortalF119SyntheticServiceReservation as Stage;

if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('PORTAL_F119_PG_LAB') !== '1'
    || getenv('PORTAL_F122_GATE_LAB') !== '1'
    || getenv('PORTAL_F123_DISPATCH_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'portal_commercial_lab'
    || !extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "F123 refuses non-disposable CI PostgreSQL.\n");
    exit(2);
}

$checks = 0;
function verify(bool $ok, string $why): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException('F123 FAIL: '.$why);
    }
}
function syntheticEnvelope(string $submission): array
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
function stageSynthetic(PDO $db, string $submission, string $nonce): void
{
    $env = syntheticEnvelope($submission);
    $bindings = [];
    foreach (['organization_reference','binding_reference','site_reference',
        'form_reference','destination_reference'] as $field) {
        $bindings[$field] = $env[$field];
    }
    $iat = time();
    $exp = $iat + 120;
    $parts = ['imagine.portal.synthetic-service-request.v1'];
    foreach (['organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'] as $field) {
        $parts[] = strtolower($env[$field]);
    }
    array_push($parts, (string)$iat, (string)$exp, $nonce);
    $key = random_bytes(48);
    $stage = new Stage($key);
    $result = $stage->observe($db, $env, $bindings, [
        'issued_at'=>$iat,
        'expires_at'=>$exp,
        'nonce'=>$nonce,
        'mac'=>hash_hmac('sha256', implode("\n",$parts),$key),
    ]);
    verify($result['reasonCode'] === 'LAB_METADATA_AND_OUTBOX_WRITTEN',
        'real PG18 fixture contains the synthetic outbox event');
    verify($result['crmPersisted'] === false,
        'writing the source outbox does not prove CRM delivery');
}
function callAs(PDO $db, string $role, string $function, string $sub, int $version): mixed
{
    verify(in_array($role,['portal_f122_worker_a','portal_f122_worker_b'],true),
        'only fixed fixture roles');
    verify(in_array($function,
        ['claim_lab_a','begin_dispatch_lab_a','note_unknown_lab_a'],true),
        'only fixed fixture functions');
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE '.$role);
        $stmt = $db->prepare('SELECT portal_commercial_ci.'.$function.
            '(CAST(:sub AS uuid), CAST(:ver AS bigint))');
        $stmt->execute(['sub'=>$sub,'ver'=>$version]);
        $value = $stmt->fetchColumn();
        $db->commit();
        if (is_bool($value)) {
            return $value ? 't' : 'f';
        }
        return $value === null ? null : (string)$value;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
function gate(PDO $db, string $sub): array
{
    $stmt = $db->prepare("SELECT state,claim_version,attempts,lease_until
        FROM portal_commercial_ci.delivery_gate
        WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
          AND submission_reference=CAST(:sub AS uuid)");
    $stmt->execute(['sub'=>$sub]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    verify(is_array($row), 'gate row persisted');
    return $row;
}
function expireLease(PDO $db, string $sub): void
{
    $stmt = $db->prepare("UPDATE portal_commercial_ci.delivery_gate
        SET lease_until=transaction_timestamp()-interval '2 seconds'
        WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
          AND submission_reference=CAST(:sub AS uuid)
          AND state IN ('claimed','dispatching')");
    $stmt->execute(['sub'=>$sub]);
    verify($stmt->rowCount() === 1,
        'only CI superuser fixture can simulate elapsed lease');
}

$db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
    'postgres',(string)getenv('DB_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
verify($db->query('SELECT current_database()')->fetchColumn()
    === 'portal_commercial_lab', 'dedicated source CI database');
verify((int)$db->query("SELECT count(*) FROM information_schema.tables
    WHERE table_schema='portal_commercial_ci' AND table_name='delivery_gate'")
    ->fetchColumn() === 1, 'F122 must have run before F123');
verify($db->query("SELECT to_regprocedure(
    'portal_commercial_ci.begin_dispatch_lab_a(uuid,bigint)')")->fetchColumn()
    === null, 'F123 functions not preinstalled');
$db->exec((string)file_get_contents(__DIR__.'/dispatch_fence_pg18.sql'));

$sent = 'e4e4e4e4-e4e4-4e4e-8e4e-e4e4e4e4e4e4';
$unsent = 'e5e5e5e5-e5e5-4e5e-8e5e-e5e5e5e5e5e5';
$abandoned = 'e6e6e6e6-e6e6-4e6e-8e6e-e6e6e6e6e6e6';
foreach ([
    [$sent,'b4b4b4b4-b4b4-4b4b-8b4b-b4b4b4b4b4b4'],
    [$unsent,'b5b5b5b5-b5b5-4b5b-8b5b-b5b5b5b5b5b5'],
    [$abandoned,'b6b6b6b6-b6b6-4b6b-8b6b-b6b6b6b6b6b6']
] as [$sub,$nonce]) {
    stageSynthetic($db,$sub,$nonce);
}

verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$sent,1)
    === 'f', 'cannot dispatch without lease');
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$sent,0)
    === '1', 'lease acquired before any dispatch');
verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$sent,0)
    === 'f', 'zero version never dispatches');
verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$sent,2)
    === 'f', 'stale or invented version never dispatches');
verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$sent,1)
    === 't', 'current unexpired claim starts the irreversible dispatch phase');
verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$sent,1)
    === 'f', 'duplicate begin_dispatch is rejected');
verify(gate($db,$sent)['state'] === 'dispatching',
    'dispatch phase is durable in first database');
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$sent,1)
    === null, 'even current dispatch cannot claim another lease');
expireLease($db,$sent);
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$sent,1)
    === null, 'expired in-flight dispatch never retries automatically');
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$sent,0)
    === null, 'initial claim cannot reset an in-flight dispatch');
verify(callAs($db,'portal_f122_worker_a','note_unknown_lab_a',$sent,1)
    === 't', 'current fencing owner can record lost reply after expiry');
verify(gate($db,$sent)['state'] === 'unknown',
    'uncertain delivery is retained, not acknowledged');
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$sent,1)
    === null, 'uncertainty remains a permanent automatic retry hold');

// An expired lease that NEVER entered dispatch can be recovered with CAS.
// The old owner cannot later begin HTTP or mark a new owner's lease unknown.
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$unsent,0)
    === '1', 'another request reserves work');
expireLease($db,$unsent);
verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$unsent,1)
    === 'f', 'expired first owner cannot begin HTTP');
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$unsent,1)
    === '2', 'only never-dispatched expired lease can be reclaimed');
verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$unsent,1)
    === 'f', 'fenced-out owner cannot dispatch after new claim');
verify(callAs($db,'portal_f122_worker_a','note_unknown_lab_a',$unsent,1)
    === 'f', 'fenced-out owner cannot set unknown on another claim');
verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$unsent,2)
    === 't', 'new owner may start once with its own fencing version');
verify((int)gate($db,$unsent)['attempts'] === 2,
    'bounded attempts remain recorded');
expireLease($db,$unsent);
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$unsent,2)
    === null, 'no third attempt after dispatch began');

// Conservative F122 remains valid: an owner may record uncertainty even
// without reaching the dispatch phase, but cannot fabricate an ACK.
verify(callAs($db,'portal_f122_worker_a','claim_lab_a',$abandoned,0)
    === '1', 'third synthetic submission leased');
verify(callAs($db,'portal_f122_worker_a','note_unknown_lab_a',$abandoned,1)
    === 't', 'pre-send uncertainty may be held as before');
verify(gate($db,$abandoned)['lease_until'] === null,
    'unknown hold has no live lease');
verify(callAs($db,'portal_f122_worker_a','begin_dispatch_lab_a',$abandoned,1)
    === 'f', 'unknown hold cannot be promoted to dispatch');

// Both roles have RLS-restricted SELECT. B cannot mutate A's gate through
// a SECURITY DEFINER function even by spoofing the application tenant GUC.
$db->beginTransaction();
try {
    $db->exec('SET LOCAL ROLE portal_f122_worker_b');
    $db->exec("SET LOCAL app.lab_tenant_id='11111111-1111-4111-8111-111111111111'");
    verify((int)$db->query('SELECT count(*) FROM portal_commercial_ci.delivery_gate')
        ->fetchColumn() === 0, 'cross-tenant read stays denied');
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
try {
    callAs($db,'portal_f122_worker_b','begin_dispatch_lab_a',$unsent,2);
    throw new RuntimeException('F123 FAIL: foreign worker dispatched tenant A');
} catch (PDOException) {
    ++$checks;
}
foreach ([
    "UPDATE portal_commercial_ci.delivery_gate SET state='claimed'",
    "DELETE FROM portal_commercial_ci.delivery_gate",
    "TRUNCATE portal_commercial_ci.delivery_gate"
] as $sql) {
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE portal_f122_worker_a');
        $db->exec($sql);
        $db->rollBack();
        throw new RuntimeException('F123 FAIL: worker bypassed guarded DB function');
    } catch (PDOException) {
        $db->rollBack();
        ++$checks;
    }
}
verify((int)$db->query("SELECT count(*) FROM information_schema.columns
    WHERE table_schema='portal_commercial_ci' AND table_name='delivery_gate'")
    ->fetchColumn() === 7, 'no personal data, receipt, or token column added');
verify(gate($db,$sent)['state'] === 'unknown',
    'negative tests never removed hold');
echo "F123 PASS: {$checks} PG18 pre-send fencing assertions; NO CRM ACK/worker/deploy.\n";
