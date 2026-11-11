<?php

declare(strict_types=1);

// CI-only F128: append-only synthetic review-attempt audit, NOT a real
// security log, real user identification or CRM evidence.
foreach ([
    'GITHUB_ACTIONS'=>'true',
    'RUNNER_ENVIRONMENT'=>'github-hosted',
    'PORTAL_F119_PG_LAB'=>'1',
    'PORTAL_F122_GATE_LAB'=>'1',
    'PORTAL_F123_DISPATCH_LAB'=>'1',
    'PORTAL_F125_REVIEW_QUEUE_LAB'=>'1',
    'PORTAL_F127_RESTRICTED_REVIEW_LAB'=>'1',
    'PORTAL_F128_REVIEW_AUDIT_LAB'=>'1',
    'DB_HOST'=>'127.0.0.1',
    'DB_DATABASE'=>'portal_commercial_lab',
] as $key=>$expected) {
    if (getenv($key)!==$expected) {
        fwrite(STDERR,"F128 refuses non-disposable PostgreSQL CI.\n");
        exit(2);
    }
}
if (!extension_loaded('pdo_pgsql')) {
    exit(2);
}
$checks=0;
function f128check(bool $pass,string $reason): void
{
    global $checks;
    ++$checks;
    if (!$pass) {
        throw new RuntimeException('F128 FAIL: '.$reason);
    }
}
function asA(PDO $db,callable $callback,bool $commit=true): mixed
{
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE portal_f127_reviewer_a');
        $answer=$callback($db);
        if ($commit) {
            $db->commit();
        } else {
            $db->rollBack();
        }
        return $answer;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
function forbidden(PDO $db,string $role,string $sql): void
{
    f128check(in_array($role,[
        'portal_f127_reviewer_a','portal_f127_reviewer_b',
        'portal_f122_worker_a'
    ],true),'fixed reviewer role');
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE '.$role);
        $db->exec($sql);
        $db->rollBack();
        throw new RuntimeException('F128 FAIL: unauthorized SQL was accepted');
    } catch (PDOException) {
        $db->rollBack();
        global $checks;
        ++$checks;
    }
}
function auditCount(PDO $db): int
{
    return (int)$db->query(
        'SELECT count(*) FROM portal_commercial_ci.review_access_audit_lab'
    )->fetchColumn();
}
function reviewRows(PDO $db,bool $commit=true): array
{
    return asA($db,static function(PDO $db): array {
        return $db->query(
            'SELECT * FROM portal_commercial_ci.review_and_audit_lab_a()
             ORDER BY submission_reference'
        )->fetchAll(PDO::FETCH_ASSOC);
    },$commit);
}
$db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
    'postgres',(string)getenv('DB_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
f128check($db->query('SELECT current_database()')->fetchColumn()
    ==='portal_commercial_lab','isolated source test database');
f128check($db->query("SELECT to_regprocedure(
    'portal_commercial_ci.review_items_lab_a()')")->fetchColumn()!==null,
    'F127 reader already installed');
f128check((int)$db->query("SELECT count(*) FROM portal_commercial_ci.review_authority_lab
    WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid
      AND permission_code='commercial.delivery-review.view'")->fetchColumn()===1,
    'F127 explicit synthetic fixture granted by lab administrator');
$db->exec((string)file_get_contents(__DIR__.'/audited_reader_pg18.sql'));

f128check(auditCount($db)===0,'audit starts empty');
forbidden($db,'portal_f127_reviewer_a',
    'SELECT count(*) FROM portal_commercial_ci.review_items_lab_a()');
forbidden($db,'portal_f127_reviewer_a',
    'SELECT count(*) FROM portal_commercial_ci.review_access_audit_lab');
forbidden($db,'portal_f127_reviewer_b',
    'SELECT count(*) FROM portal_commercial_ci.review_and_audit_lab_a()');
forbidden($db,'portal_f122_worker_a',
    'SELECT count(*) FROM portal_commercial_ci.review_and_audit_lab_a()');

$items=reviewRows($db);
f128check(count($items)===5,'valid synthetic grant returns five unresolved items');
f128check(auditCount($db)===1,'successful committed invocation logs one attempt');
foreach ($items as $row) {
    f128check($row['organization_reference']
        ==='11111111-1111-4111-8111-111111111111',
        'no foreign organization data in reader output');
    foreach (['automatic_retry_allowed','real_crm_persisted_verified',
        'can_acknowledge_visitor'] as $flag) {
        f128check($row[$flag]===false || $row[$flag]==='f',
            'review event never permits remote delivery acknowledgement');
    }
}
$event=$db->query('SELECT * FROM portal_commercial_ci.review_access_audit_lab')
    ->fetch(PDO::FETCH_ASSOC);
f128check(is_array($event)
    && $event['organization_reference']
        ==='11111111-1111-4111-8111-111111111111'
    && $event['reviewer_reference']
        ==='a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7'
    && $event['event_kind']==='synthetic_review_requested',
    'only fixed synthetic actor/tenant/kind in committed audit');
f128check(count($event)===5,'audit includes only minimal technical metadata');

// A read rolled back by its caller is not durably logged in PostgreSQL.
// This limitation must remain explicit for future operational design.
$rolledBackRows=reviewRows($db,false);
f128check(count($rolledBackRows)===5,'rolled back read still has results locally');
f128check(auditCount($db)===1,
    'caller rollback undoes same-transaction audit (not full logging)');

// Revocation does not turn unauthorized access into an exception or leak data.
// The attempt is logged, and the F127 boundary still returns an empty set.
$db->exec("UPDATE portal_commercial_ci.review_authority_lab
    SET session_status='revoked'
    WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid");
f128check(count(reviewRows($db))===0,
    'revoked synthetic session cannot see review rows');
f128check(auditCount($db)===2,
    'committed denied/empty review attempt also logs an event');
$db->exec("UPDATE portal_commercial_ci.review_authority_lab
    SET session_status='active',permission_code='services.view'
    WHERE organization_reference='11111111-1111-4111-8111-111111111111'::uuid");
f128check(count(reviewRows($db))===0,
    'non-review grant cannot see rows');
f128check(auditCount($db)===3,
    'non-review grant attempt is observed, without claiming read success');

forbidden($db,'portal_f127_reviewer_a',
    "DELETE FROM portal_commercial_ci.review_access_audit_lab");
forbidden($db,'portal_f127_reviewer_a',
    "UPDATE portal_commercial_ci.review_access_audit_lab SET event_kind='invalid'");
forbidden($db,'portal_f127_reviewer_a',
    "INSERT INTO portal_commercial_ci.review_access_audit_lab (
      organization_reference,reviewer_reference,event_kind)
      VALUES ('11111111-1111-4111-8111-111111111111',
      'a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7','synthetic_review_requested')");
f128check(auditCount($db)===3,
    'reviewer cannot forge, modify or delete committed audit events');
f128check((int)$db->query("SELECT count(*) FROM information_schema.columns
    WHERE table_schema='portal_commercial_ci'
      AND table_name='review_access_audit_lab'")->fetchColumn()===5,
    'audit only event id, two synthetic UUIDs, event kind and timestamp');

echo "F128 PASS: {$checks} metadata-only committed audit attempts; no real IAM, customer data or CRM ACK.\n";
