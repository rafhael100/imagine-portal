<?php

declare(strict_types=1);

// F118 PostgreSQL 18 disposable CI only. No Laravel routes, CRM credentials
// or visitor data. DO NOT run against any persistent database.
require_once __DIR__ . '/PortalF118SyntheticMetadataReservation.php';

use Imagine\Portal\Labs\CommercialIntakeF118\PortalF118SyntheticMetadataReservation as Reservation;

if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
    || getenv('PORTAL_F118_PG_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'portal_commercial_lab'
    || !extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "F118 refuses non-disposable GitHub-hosted PostgreSQL.\n");
    exit(2);
}

$checks = 0;
function check(bool $ok, string $description): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException('F118 failed: ' . $description);
    }
}
function assertNoAuthority(array $result): void
{
    foreach ([
        'visitorInputAccepted','tenantAuthenticated','crmPersisted',
        'canAcknowledgeVisitor','canDeliverToCrm','productionAuthorized',
    ] as $flag) {
        check($result[$flag] === false, $flag . ' must remain false');
    }
    check($result['mode'] === 'POSTGRES18_DISPOSABLE_SYNTHETIC_METADATA_ONLY', 'fixture mode');
}
function connection(): PDO
{
    $pdo = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
        'postgres',
        (string)getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );
    check($pdo->query('SELECT current_database()')->fetchColumn() === 'portal_commercial_lab', 'dedicated database');
    return $pdo;
}
function asRole(PDO $pdo, string $role, string $sql): array
{
    // Whitelist solely immutable CI roles; caller cannot supply SQL identity.
    check(in_array($role,['portal_commercial_f118_a','portal_commercial_f118_b'],true),'fixed role');
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET LOCAL ROLE ' . $role);
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
        $pdo->commit();
        return $rows;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
function countRows(PDO $pdo, string $table, string $role='portal_commercial_f118_a'): int
{
    check(in_array($table,['metadata_ledger','pending_outbox'],true),'fixed table');
    $rows=asRole($pdo,$role,'SELECT count(*) FROM portal_commercial_ci.'.$table);
    return (int)$rows[0][0];
}

$pdo=connection();
$already=(int)$pdo->query(<<<'SQL'
SELECT count(*) FROM pg_namespace WHERE nspname='portal_commercial_ci'
SQL)->fetchColumn();
check($already===0,'refuse pre-existing fixture schema');
// No migrations. Create disposable fixture objects ONLY on this isolated DB.
$sql=(string)file_get_contents(__DIR__ . '/fixture_pg18.sql');
check($sql!=='','fixture exists');
$pdo->exec($sql);

$env=[
    'protocol'=>'imagine.lead-intake',
    'schema_version'=>1,
    'organization_reference'=>'11111111-1111-4111-8111-111111111111',
    'binding_reference'=>'22222222-2222-4222-8222-222222222222',
    'submission_reference'=>'33333333-3333-4333-8333-333333333333',
    'site_reference'=>'44444444-4444-4444-8444-444444444444',
    'form_reference'=>'55555555-5555-4555-8555-555555555555',
    'destination_reference'=>'66666666-6666-4666-8666-666666666666'
];
$binding=[
    'organization_reference'=>$env['organization_reference'],
    'binding_reference'=>$env['binding_reference'],
    'site_reference'=>$env['site_reference'],
    'form_reference'=>$env['form_reference'],
    'destination_reference'=>$env['destination_reference']
];

check(countRows($pdo,'metadata_ledger')===0,'new ledger empty');
check(countRows($pdo,'pending_outbox')===0,'new outbox empty');
$first=Reservation::inspectAndReserve($pdo,$env,$binding);
check($first['reasonCode']==='LAB_METADATA_AND_OUTBOX_WRITTEN','atomic first write');
assertNoAuthority($first);
check(countRows($pdo,'metadata_ledger')===1,'exactly one ledger');
check(countRows($pdo,'pending_outbox')===1,'exactly one outbox');
$second=Reservation::inspectAndReserve($pdo,$env,$binding);
check($second['reasonCode']==='LAB_DUPLICATE_METADATA_NOT_DELIVERED','idempotent replay');
assertNoAuthority($second);
check(countRows($pdo,'metadata_ledger')===1,'replay ledger still one');
check(countRows($pdo,'pending_outbox')===1,'replay outbox still one');

foreach ([
    'organization_reference','binding_reference','site_reference',
    'form_reference','destination_reference'
] as $field) {
    $forged=$env;
    $forged[$field]='77777777-7777-4777-8777-777777777777';
    $result=Reservation::inspectAndReserve($pdo,$forged,$binding);
    check($result['reasonCode']==='STRUCTURAL_OR_BINDING_REJECTED','untrusted '.$field);
    assertNoAuthority($result);
}
foreach (['name','email','phone','message','consent','mobile','role'] as $field) {
    $forged=$env;
    $forged[$field]='synthetic-value';
    $result=Reservation::inspectAndReserve($pdo,$forged,$binding);
    check($result['reasonCode']==='STRUCTURAL_OR_BINDING_REJECTED','unexpected '.$field);
    assertNoAuthority($result);
}

$changed=$env;
$changed['destination_reference']='77777777-7777-4777-8777-777777777777';
$changedBinding=$binding;
$changedBinding['destination_reference']=$changed['destination_reference'];
$wrongDestination=Reservation::inspectAndReserve($pdo,$changed,$changedBinding);
check($wrongDestination['reasonCode']==='DUPLICATE_PINNED_CONTEXT_CONFLICT',
    'a changed server fixture may not reroute the original submission');
assertNoAuthority($wrongDestination);
check(countRows($pdo,'metadata_ledger')===1,'destination conflict leaves original row');
check(countRows($pdo,'pending_outbox')===1,'destination conflict leaves original queue');

$otherTenant=$env;
$otherTenant['organization_reference']='99999999-9999-4999-8999-999999999999';
$otherBinding=$binding;
$otherBinding['organization_reference']=$otherTenant['organization_reference'];
$untrusted=Reservation::inspectAndReserve($pdo,$otherTenant,$otherBinding);
check($untrusted['reasonCode']==='EPHEMERAL_PG_OPERATION_REJECTED',
    'A-only role must reject B org even with matching untrusted fixture');
assertNoAuthority($untrusted);
check(countRows($pdo,'metadata_ledger','portal_commercial_f118_b')===0,
    'B cannot see A tenant rows');
check(countRows($pdo,'pending_outbox','portal_commercial_f118_b')===0,
    'B cannot see A tenant outbox');
check(countRows($pdo,'metadata_ledger')===1,'B failed insert leaves A row intact');

// Physical role cannot rewrite the pinned original or drop data.
foreach ([
    'UPDATE portal_commercial_ci.metadata_ledger SET destination_reference=destination_reference',
    'DELETE FROM portal_commercial_ci.metadata_ledger',
    'TRUNCATE portal_commercial_ci.pending_outbox',
] as $statement) {
    try {
        asRole($pdo,'portal_commercial_f118_a',$statement);
        throw new RuntimeException('F118 failed: destructive statement had rights');
    } catch (PDOException) {
        ++$checks;
    }
}

// The FK and CHECK rollback both inserts if the queue event is invalid.
$testSubmission='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$pdo->beginTransaction();
try {
    $pdo->exec('SET LOCAL ROLE portal_commercial_f118_a');
    $bad=<<<'SQL'
WITH created AS (
 INSERT INTO portal_commercial_ci.metadata_ledger
 (organization_reference,submission_reference,binding_reference,
  site_reference,form_reference,destination_reference)
 VALUES ('11111111-1111-4111-8111-111111111111',
         'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
         '22222222-2222-4222-8222-222222222222',
         '44444444-4444-4444-8444-444444444444',
         '55555555-5555-4555-8555-555555555555',
         '66666666-6666-4666-8666-666666666666')
 RETURNING organization_reference,submission_reference
)
INSERT INTO portal_commercial_ci.pending_outbox
 (organization_reference,submission_reference,event_kind)
SELECT organization_reference,submission_reference,'forged_delivered' FROM created
SQL;
    $pdo->exec($bad);
    throw new RuntimeException('F118 failed: invalid event inserted');
} catch (PDOException) {
    $pdo->rollBack();
    ++$checks;
}
check(countRows($pdo,'metadata_ledger')===1,'invalid outbox rolled back metadata');
check(countRows($pdo,'pending_outbox')===1,'invalid outbox left no queued event');
check(!$pdo->inTransaction(),'no open transaction remains');
echo "F118 PASS: {$checks} synthetic PHP/PDO checks, disposable PG18 atomicity, RLS, pinning and rollback; no CRM receipt.\n";
