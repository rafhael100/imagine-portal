<?php

declare(strict_types=1);

/**
 * INT-F158: test PostgreSQL row-lock serialization under two independent
 * connections. Disposable localhost PG18 ONLY. This is not trusted IAM.
 */
if (getenv('PORTAL_F158_PG_LAB') !== 'NON_OPERATIONAL_F158'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGDATABASE') !== 'portal_f158_lab'
    || getenv('PGUSER') !== 'postgres'
    || getenv('PGPASSWORD') !== 'f158-review-admin-only') {
    throw new RuntimeException('F158 refuses non-disposable PostgreSQL.');
}

function f158Connection(): PDO
{
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_f158_lab;connect_timeout=3;sslmode=disable',
        'postgres', 'f158-review-admin-only',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

function f158Expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException('F158 SECURITY FAIL: '.$message);
    }
}

function f158Scalar(PDO $pdo, string $sql): string
{
    $result = $pdo->query($sql)?->fetchColumn();
    f158Expect($result !== false && is_scalar($result), 'missing SQL scalar');
    return (string) $result;
}

function f158LockTimeout(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // PostgreSQL lock_timeout -> 55P03 lock_not_available.
        f158Expect((string) $e->getCode() === '55P03',
            'unexpected lock failure SQLSTATE '.$e->getCode());
        return;
    }
    throw new RuntimeException(
        'F158 SECURITY FAIL: concurrent mutation succeeded before first transaction settled.'
    );
}

$a = f158Connection();
$b = f158Connection();
$b->exec("SET lock_timeout='450ms'");
$a->exec("SET statement_timeout='3500ms'");
$b->exec("SET statement_timeout='3500ms'");

foreach (['portal_memberships', 'portal_membership_grants', 'portal_organizations', 'portal_auth_sessions'] as $table) {
    f158Expect(f158Scalar($a,
        "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND tablename='".$table."'"
    ) === '0', 'unreviewed RLS policies present: '.$table);
    f158Expect(in_array(f158Scalar($a,
        "SELECT relforcerowsecurity FROM pg_class WHERE oid=('public.".$table."')::regclass"
    ), ['t','1'], true), 'FORCE RLS disabled: '.$table);
}

$a->exec(<<<'SQL'
INSERT INTO public.portal_identities
    (id,email_normalized,status,security_revision,created_at,updated_at)
VALUES ('11111111-1111-4111-8111-111111111111','f158@example.invalid','active',1,now(),now());
INSERT INTO public.portal_organizations
    (id,reference,display_name,status,security_revision,created_at,updated_at)
VALUES ('22222222-2222-4222-8222-222222222222','f158-org','F158 synthetic org','active',1,now(),now());
INSERT INTO public.portal_memberships
    (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
VALUES ('33333333-3333-4333-8333-333333333333',
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222','member','active',1,now(),now());
INSERT INTO public.portal_auth_sessions
    (id,session_sha256,identity_id,organization_id,status,
     identity_revision,membership_revision,organization_revision,issued_at,expires_at,
     created_at,updated_at)
VALUES ('55555555-5555-4555-8555-555555555555',
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
        'pending_mfa',1,1,1,now(),now()+interval '1 hour',now(),now());
SQL);

$memberRev = "SELECT membership_revision FROM public.portal_memberships WHERE id='33333333-3333-4333-8333-333333333333'";
$orgRev = "SELECT security_revision FROM public.portal_organizations WHERE id='22222222-2222-4222-8222-222222222222'";
f158Expect(f158Scalar($a,$memberRev)==='1', 'initial membership revision');
f158Expect(f158Scalar($a,$orgRev)==='1', 'initial org revision');

// A grant insertion must acquire the membership row lock from its trigger.
$a->beginTransaction();
try {
    $a->exec(<<<'SQL'
INSERT INTO public.portal_membership_grants
    (id,membership_id,permission_code,created_at,updated_at)
VALUES ('44444444-4444-4444-8444-444444444444',
        '33333333-3333-4333-8333-333333333333','services.view',now(),now());
SQL);
    f158Expect(f158Scalar($a,$memberRev)==='2','grant increments revision within A transaction');
    f158Expect(f158Scalar($b,$memberRev)==='1','B must not read uncommitted membership revision');
    f158LockTimeout($b,
        "UPDATE public.portal_memberships SET status='suspended' ".
        "WHERE id='33333333-3333-4333-8333-333333333333'");
    f158Expect(f158Scalar($b,$memberRev)==='1','failed concurrent update did not change revision');
    $a->commit();
} finally {
    if ($a->inTransaction()) $a->rollBack();
}
f158Expect(f158Scalar($b,$memberRev)==='2','grant commit made revision 2 visible');
f158Expect(f158Scalar($b,
    "SELECT count(*) FROM public.portal_membership_grants WHERE membership_id='33333333-3333-4333-8333-333333333333'")==='1',
    'exactly one grant persisted after commit');
$b->exec(
    "UPDATE public.portal_memberships SET status='suspended' ".
    "WHERE id='33333333-3333-4333-8333-333333333333'"
);
f158Expect(f158Scalar($b,$memberRev)==='3','later mutation serialized at revision 3');

// Concurrent organization update: A holds a new uncommitted suspension,
// B must not close the organization using old revision/status.
$a->beginTransaction();
try {
    $a->exec(
        "UPDATE public.portal_organizations SET status='suspended' ".
        "WHERE id='22222222-2222-4222-8222-222222222222'"
    );
    f158Expect(f158Scalar($a,$orgRev)==='2','suspension increments within transaction');
    f158Expect(f158Scalar($b,$orgRev)==='1','B cannot read uncommitted organization revision');
    f158LockTimeout($b,
        "UPDATE public.portal_organizations SET status='closed' ".
        "WHERE id='22222222-2222-4222-8222-222222222222'");
    $a->rollBack();
} finally {
    if ($a->inTransaction()) $a->rollBack();
}
f158Expect(f158Scalar($b,$orgRev)==='1','rolled-back org transition did not advance revision');
f158Expect(f158Scalar($b,
    "SELECT status FROM public.portal_organizations WHERE id='22222222-2222-4222-8222-222222222222'")==='active',
    'rollback restores active organization');
$b->exec(
    "UPDATE public.portal_organizations SET status='closed' ".
    "WHERE id='22222222-2222-4222-8222-222222222222'"
);
f158Expect(f158Scalar($b,$orgRev)==='2','retry closes organization and increments exactly once');
f158Expect(f158Scalar($b,
    "SELECT count(*) FROM public.portal_auth_sessions s ".
    "JOIN public.portal_memberships m ON m.identity_id=s.identity_id AND m.organization_id=s.organization_id ".
    "JOIN public.portal_organizations o ON o.id=s.organization_id ".
    "WHERE s.id='55555555-5555-4555-8555-555555555555' ".
    "AND s.membership_revision < m.membership_revision ".
    "AND s.organization_revision < o.security_revision")==='1',
    'original session invalidated by independent member and organization revisions');

echo "F158 PASS: two simultaneous PostgreSQL connections serialize grant/status changes, avoid lost revisions and invalidate stale session.\n";
