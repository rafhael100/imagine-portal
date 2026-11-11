<?php

declare(strict_types=1);

// F125: disposable CI-only view of unresolved metadata, never CRM delivery.
foreach ([
    'GITHUB_ACTIONS'=>'true',
    'RUNNER_ENVIRONMENT'=>'github-hosted',
    'PORTAL_F119_PG_LAB'=>'1',
    'PORTAL_F122_GATE_LAB'=>'1',
    'PORTAL_F123_DISPATCH_LAB'=>'1',
    'PORTAL_F125_REVIEW_QUEUE_LAB'=>'1',
    'DB_HOST'=>'127.0.0.1',
    'DB_DATABASE'=>'portal_commercial_lab'
] as $key=>$expected) {
    if (getenv($key)!==$expected) {
        fwrite(STDERR,"F125 refuses non-disposable PG18 CI.\n");
        exit(2);
    }
}
if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR,"F125 requires PDO PostgreSQL.\n");
    exit(2);
}

$checks=0;
function prove(bool $condition,string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('F125 FAIL: '.$message);
    }
}
function asRole(PDO $db,string $role,callable $callback): mixed
{
    prove(in_array($role,['portal_f122_worker_a','portal_f122_worker_b'],true),
        'role allowlist does not accept arbitrary SQL identifiers');
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE '.$role);
        $result=$callback($db);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
function expectReadDenied(PDO $db,string $sql): void
{
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE portal_f122_worker_a');
        $db->exec($sql);
        $db->rollBack();
        throw new RuntimeException('F125 FAIL: worker changed the review queue');
    } catch (PDOException) {
        $db->rollBack();
        global $checks;
        ++$checks;
    }
}

$db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
    'postgres',(string)getenv('DB_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
prove($db->query('SELECT current_database()')->fetchColumn()
    === 'portal_commercial_lab', 'source-only PG database');
prove($db->query("SELECT to_regclass('portal_commercial_ci.delivery_review_queue')")
    ->fetchColumn()===null, 'view not installed before F125');
prove((int)$db->query("SELECT count(*) FROM portal_commercial_ci.delivery_gate")
    ->fetchColumn()===5,'F122 + F123 synthetic rows exist');
$db->exec((string)file_get_contents(__DIR__.'/review_queue_pg18.sql'));

$options=$db->query("SELECT reloptions FROM pg_class
    WHERE oid='portal_commercial_ci.delivery_review_queue'::regclass")
    ->fetchColumn();
// PostgreSQL returns a native text[] representation in PDO.
prove(is_string($options) && str_contains($options,'security_invoker=true')
    && str_contains($options,'security_barrier=true'),
    'security-invoker and barrier are both active');
prove($db->query("SELECT relrowsecurity AND relforcerowsecurity
    FROM pg_class WHERE oid='portal_commercial_ci.delivery_gate'::regclass")
    ->fetchColumn()===true || $db->query("SELECT
      (relrowsecurity AND relforcerowsecurity)::text
      FROM pg_class WHERE oid='portal_commercial_ci.delivery_gate'::regclass")
      ->fetchColumn()==='true',
    'underlying table retains FORCE RLS');

$rows=asRole($db,'portal_f122_worker_a',static function(PDO $db): array {
    $stmt=$db->query('SELECT * FROM portal_commercial_ci.delivery_review_queue
        ORDER BY submission_reference');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
});
prove(count($rows)===5,'all five unresolved synthetic submissions visible to tenant A');
$expected=[
    'e1e1e1e1-e1e1-4e1e-8e1e-e1e1e1e1e1e1'=>'unknown_outcome',
    'e2e2e2e2-e2e2-4e2e-8e2e-e2e2e2e2e2e2'=>'claim_attempt_limit_reached',
    'e4e4e4e4-e4e4-4e4e-8e4e-e4e4e4e4e4e4'=>'unknown_outcome',
    'e5e5e5e5-e5e5-4e5e-8e5e-e5e5e5e5e5e5'=>'expired_dispatch_may_have_committed',
    'e6e6e6e6-e6e6-4e6e-8e6e-e6e6e6e6e6e6'=>'unknown_outcome',
];
foreach ($rows as $row) {
    $sub=$row['submission_reference'];
    prove(isset($expected[$sub]),'known synthetic submission, no foreign tenant');
    prove($row['review_reason']===$expected[$sub],
        'reason derived only from persisted source state');
    prove($row['organization_reference']
        ==='11111111-1111-4111-8111-111111111111','fixed tenant A');
    prove($row['requires_manual_review']===true
        || $row['requires_manual_review']==='t',
        'all unresolved items require manual review');
    foreach (['automatic_retry_allowed','real_crm_persisted_verified',
        'can_acknowledge_visitor'] as $flag) {
        prove($row[$flag]===false || $row[$flag]==='f',
            'no false ACK, delivery, or automatic retry: '.$flag);
    }
}
prove(count(array_diff(array_keys($expected),
    array_column($rows,'submission_reference')))===0,
    'all five intended hold classifications confirmed');

$columns=$db->query("SELECT column_name FROM information_schema.columns
    WHERE table_schema='portal_commercial_ci'
      AND table_name='delivery_review_queue' ORDER BY ordinal_position")
    ->fetchAll(PDO::FETCH_COLUMN);
prove($columns===[
    'organization_reference','submission_reference','claim_version','attempts',
    'updated_at','review_reason','requires_manual_review',
    'automatic_retry_allowed','real_crm_persisted_verified',
    'can_acknowledge_visitor'
], 'view has exactly the ten metadata-only approved fields');

$fromB=asRole($db,'portal_f122_worker_b',static function(PDO $db): int {
    return (int)$db->query(
        'SELECT count(*) FROM portal_commercial_ci.delivery_review_queue'
    )->fetchColumn();
});
prove($fromB===0,'RLS denies tenant B observation of tenant A unresolved rows');
$spoof=asRole($db,'portal_f122_worker_b',static function(PDO $db): int {
    $db->exec("SET LOCAL app.lab_tenant_id='11111111-1111-4111-8111-111111111111'");
    return (int)$db->query(
        'SELECT count(*) FROM portal_commercial_ci.delivery_review_queue'
    )->fetchColumn();
});
prove($spoof===0,'GUC tenant spoofing does not bypass invoker RLS');

foreach ([
    "UPDATE portal_commercial_ci.delivery_review_queue
        SET automatic_retry_allowed=true",
    "DELETE FROM portal_commercial_ci.delivery_review_queue",
    "TRUNCATE portal_commercial_ci.delivery_review_queue",
    "INSERT INTO portal_commercial_ci.delivery_review_queue
        (organization_reference,submission_reference)
        VALUES ('11111111-1111-4111-8111-111111111111',
                'e7e7e7e7-e7e7-4e7e-8e7e-e7e7e7e7e7e7')",
] as $sql) {
    expectReadDenied($db,$sql);
}
prove((int)$db->query("SELECT count(*) FROM portal_commercial_ci.delivery_gate")
    ->fetchColumn()===5, 'review and negative tests do not mutate source gate');
prove((int)$db->query("SELECT count(*) FROM information_schema.tables
    WHERE table_schema='portal_commercial_ci'
      AND table_name='delivery_review_queue'")->fetchColumn()===1,
    'CI review interface is a view, not a writable queue table');

echo "F125 PASS: {$checks} PG18 tenant review read-only assertions; no CRM ACK or retry authorization.\n";
