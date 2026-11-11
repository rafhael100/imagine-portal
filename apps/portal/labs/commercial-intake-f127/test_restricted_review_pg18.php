<?php

declare(strict_types=1);

// F127 is deliberately restricted to a disposable GitHub-hosted PG18 fixture.
// It cannot read or authorize real CRM data.
foreach ([
    'GITHUB_ACTIONS'=>'true',
    'RUNNER_ENVIRONMENT'=>'github-hosted',
    'PORTAL_F119_PG_LAB'=>'1',
    'PORTAL_F122_GATE_LAB'=>'1',
    'PORTAL_F123_DISPATCH_LAB'=>'1',
    'PORTAL_F125_REVIEW_QUEUE_LAB'=>'1',
    'PORTAL_F127_RESTRICTED_REVIEW_LAB'=>'1',
    'DB_HOST'=>'127.0.0.1',
    'DB_DATABASE'=>'portal_commercial_lab',
] as $key=>$expected) {
    if (getenv($key)!==$expected) {
        fwrite(STDERR,"F127 refuses non-disposable PG18 CI.\n");
        exit(2);
    }
}
if (!extension_loaded('pdo_pgsql')) {
    exit(2);
}
$checks=0;
function prove127(bool $ok,string $reason): void
{
    global $checks;
    ++$checks;
    if (!$ok) {
        throw new RuntimeException('F127 FAIL: '.$reason);
    }
}
function withRole127(PDO $db,string $role,callable $callback): mixed
{
    prove127(in_array($role,[
        'portal_f127_reviewer_a','portal_f127_reviewer_b',
        'portal_f122_worker_a','portal_f122_worker_b',
    ],true),'fixed CI role allowlist');
    $db->beginTransaction();
    try {
        $db->exec('SET LOCAL ROLE '.$role);
        $answer=$callback($db);
        $db->commit();
        return $answer;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
function reviewCount(PDO $db): int
{
    return withRole127($db,'portal_f127_reviewer_a',static function(PDO $db): int {
        return (int)$db->query('SELECT count(*)
            FROM portal_commercial_ci.review_items_lab_a()')->fetchColumn();
    });
}
function blockedSQL(PDO $db,string $role,string $sql,string $reason): void
{
    try {
        withRole127($db,$role,static function(PDO $db) use($sql): void {
            $db->exec($sql);
        });
    } catch (PDOException) {
        global $checks;
        ++$checks;
        return;
    }
    throw new RuntimeException('F127 FAIL: '.$reason);
}
function changeLab(PDO $db,string $set): void
{
    prove127(in_array($set,[
        "membership_status='revoked'",
        "membership_status='active'",
        "session_status='revoked'",
        "session_status='active'",
        "mfa_verified_at=NULL",
        "mfa_verified_at=transaction_timestamp()-interval '1 minute'",
        "session_expires_at=transaction_timestamp()-interval '1 second'",
        "session_expires_at=transaction_timestamp()+interval '1 hour'",
        "session_revision=2",
        "session_revision=1",
        "organization_revision=2",
        "organization_revision=1",
        "permission_code='services.view'",
        "permission_code='commercial.delivery-review.view'",
        "reviewer_reference='b7b7b7b7-b7b7-47b7-87b7-b7b7b7b7b7b7'::uuid",
        "reviewer_reference='a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7'::uuid",
    ],true),'only enumerated synthetic security changes');
    $stmt=$db->exec("UPDATE portal_commercial_ci.review_authority_lab SET {$set}
        WHERE organization_reference=
         '11111111-1111-4111-8111-111111111111'::uuid");
    prove127($stmt===1,'update exactly one synthetic review fixture');
}

$db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_commercial_lab',
    'postgres',(string)getenv('DB_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
prove127($db->query('SELECT current_database()')->fetchColumn()
    ==='portal_commercial_lab','approved disposable source');
prove127($db->query("SELECT to_regclass('portal_commercial_ci.delivery_review_queue')")
    ->fetchColumn()!==null,'F125 review view installed');
prove127((int)$db->query('SELECT count(*) FROM portal_commercial_ci.delivery_review_queue')
    ->fetchColumn()===5,'F125 five unresolved fixtures exist');
$db->exec((string)file_get_contents(__DIR__.'/privileged_review_reader_pg18.sql'));

prove127($db->query("SELECT pg_get_userbyid(proowner) FROM pg_proc
    WHERE oid='portal_commercial_ci.review_items_lab_a()'::regprocedure")
    ->fetchColumn()==='portal_f127_review_owner',
    'SECURITY DEFINER owned by dedicated CI role, not superuser');
prove127($db->query("SELECT NOT rolsuper AND NOT rolbypassrls AND NOT rolcanlogin
    FROM pg_roles WHERE rolname='portal_f127_review_owner'")
    ->fetchColumn()===true
    || $db->query("SELECT (NOT rolsuper AND NOT rolbypassrls AND NOT rolcanlogin)::text
    FROM pg_roles WHERE rolname='portal_f127_review_owner'")->fetchColumn()==='true',
    'function owner has no superuser, BYPASSRLS or LOGIN privilege');

prove127(reviewCount($db)===0,'no default review grant or implicit role access');
foreach ([
    'portal_f127_reviewer_a','portal_f127_reviewer_b',
    'portal_f122_worker_a','portal_f122_worker_b'
] as $role) {
    blockedSQL($db,$role,
        'SELECT * FROM portal_commercial_ci.delivery_review_queue',
        'direct view SELECT remains denied: '.$role);
    blockedSQL($db,$role,
        'SELECT * FROM portal_commercial_ci.review_authority_lab',
        'authority ledger direct SELECT remains denied: '.$role);
}
blockedSQL($db,'portal_f127_reviewer_b',
    'SELECT * FROM portal_commercial_ci.review_items_lab_a()',
    'tenant B must not execute tenant A reviewer function');
blockedSQL($db,'portal_f122_worker_a',
    'SELECT * FROM portal_commercial_ci.delivery_gate',
    'old worker A cannot bypass the review boundary via gate table');

$db->exec("INSERT INTO portal_commercial_ci.review_authority_lab (
  organization_reference,reviewer_reference,membership_status,session_status,
  mfa_verified_at,session_expires_at,session_revision,organization_revision,
  permission_code)
VALUES (
 '11111111-1111-4111-8111-111111111111'::uuid,
 'a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7'::uuid,
 'active','active', transaction_timestamp()-interval '1 minute',
 transaction_timestamp()+interval '1 hour',1,1,
 'services.view')");
prove127(reviewCount($db)===0,'unrelated services.view grant is insufficient');
changeLab($db,"permission_code='commercial.delivery-review.view'");
prove127(reviewCount($db)===5,
    'explicit lab review grant with fresh MFA and revisions opens only five rows');

$results=withRole127($db,'portal_f127_reviewer_a',
    static fn(PDO $db): array=>$db->query(
        'SELECT * FROM portal_commercial_ci.review_items_lab_a()
        ORDER BY submission_reference')->fetchAll(PDO::FETCH_ASSOC));
prove127(count($results)===5,'only metadata-only five-row payload');
foreach ($results as $row) {
    prove127($row['organization_reference']
        ==='11111111-1111-4111-8111-111111111111',
        'no cross-tenant item can escape owner RLS');
    foreach (['automatic_retry_allowed','real_crm_persisted_verified',
        'can_acknowledge_visitor'] as $flag) {
        prove127($row[$flag]===false || $row[$flag]==='f',
            'never promote technical review into delivery/ACK: '.$flag);
    }
}

foreach ([
    ["membership_status='revoked'","membership_status='active'"],
    ["session_status='revoked'","session_status='active'"],
    ["mfa_verified_at=NULL",
        "mfa_verified_at=transaction_timestamp()-interval '1 minute'"],
    ["session_expires_at=transaction_timestamp()-interval '1 second'",
        "session_expires_at=transaction_timestamp()+interval '1 hour'"],
    ["session_revision=2","session_revision=1"],
    ["organization_revision=2","organization_revision=1"],
    ["permission_code='services.view'",
        "permission_code='commercial.delivery-review.view'"],
    ["reviewer_reference='b7b7b7b7-b7b7-47b7-87b7-b7b7b7b7b7b7'::uuid",
        "reviewer_reference='a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7'::uuid"]
] as [$revoke,$restore]) {
    changeLab($db,$revoke);
    prove127(reviewCount($db)===0,'revocation/mismatch blocks review instantly');
    changeLab($db,$restore);
    prove127(reviewCount($db)===5,
        'synthetic fixture restored only by CI administrator');
}
// Tenant selector spoofing never grants SELECT or EXECUTE.
try {
    withRole127($db,'portal_f127_reviewer_b',static function(PDO $db): void {
        $db->exec("SET LOCAL app.lab_tenant_id='11111111-1111-4111-8111-111111111111'");
        $db->query('SELECT count(*) FROM portal_commercial_ci.delivery_gate');
    });
    throw new RuntimeException('F127 FAIL: tenant B bypassed direct grant');
} catch (PDOException) {
    ++$checks;
}
blockedSQL($db,'portal_f127_reviewer_a',
    "UPDATE portal_commercial_ci.review_authority_lab
       SET permission_code='services.view'",
    'reviewer must not alter grant');
blockedSQL($db,'portal_f127_reviewer_a',
    'UPDATE portal_commercial_ci.delivery_gate SET attempts=1',
    'reviewer must not mutate dispatch state');
prove127(reviewCount($db)===5,'adversarial tests preserve authorized lab read');
prove127((int)$db->query("SELECT count(*) FROM information_schema.columns
   WHERE table_schema='portal_commercial_ci'
   AND table_name='delivery_gate'")->fetchColumn()===7,
   'no token, personal data or positive CRM receipt added');

echo "F127 PASS: {$checks} PG18 unprivileged reviewer/tenant/revocation assertions; no real IAM or CRM ACK.\\n";
