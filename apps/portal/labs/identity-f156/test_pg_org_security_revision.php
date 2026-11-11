<?php

declare(strict_types=1);

if (getenv('PORTAL_F156_PG_LAB') !== 'NON_OPERATIONAL_F156'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGDATABASE') !== 'portal_f156_lab'
    || getenv('PGUSER') !== 'postgres'
    || getenv('PGPASSWORD') !== 'f156-review-admin-only') {
    throw new RuntimeException('F156 requires disposable localhost PostgreSQL18, never customer data.');
}
$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_f156_lab;connect_timeout=3;sslmode=disable',
    'postgres','f156-review-admin-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false],
);
function f156Assert(bool $condition,string $reason): void {
    if (!$condition) throw new RuntimeException('F156 SECURITY FAIL: '.$reason);
}
function f156Revision(PDO $db): int {
    return (int)$db->query(
        "SELECT security_revision FROM public.portal_organizations
         WHERE id='22222222-2222-4222-8222-222222222222'"
    )->fetchColumn();
}
function f156Reject(PDO $db,string $sql): void {
    try { $db->exec($sql); }
    catch (PDOException $exception) {
        f156Assert((string)$exception->getCode()==='42501',
            'unexpected denied SQLSTATE: '.$exception->getCode());
        return;
    }
    throw new RuntimeException('F156 SECURITY FAIL: invalid mutation succeeded.');
}
foreach (['portal_organizations','portal_auth_sessions'] as $table) {
    $forced = $pdo->query(
        "SELECT relrowsecurity AND relforcerowsecurity FROM pg_class
         WHERE oid=('public.".$table."')::regclass"
    )->fetchColumn();
    f156Assert(in_array($forced,[true,'t','1',1],true),
        'FORCE RLS required on '.$table);
    f156Assert((int)$pdo->query(
        "SELECT count(*) FROM pg_policies
         WHERE schemaname='public' AND tablename='".$table."'"
    )->fetchColumn()===0,'No RLS ALLOW policies in '.$table);
}

$pdo->exec(<<<'SQL'
INSERT INTO public.portal_identities
    (id,email_normalized,status,security_revision,created_at,updated_at)
VALUES ('11111111-1111-4111-8111-111111111111','f156@example.invalid','active',1,now(),now());
INSERT INTO public.portal_organizations
    (id,reference,display_name,status,security_revision,created_at,updated_at)
VALUES ('22222222-2222-4222-8222-222222222222','f156-synthetic','Only a F156 test', 'active',1,now(),now());
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
f156Assert(f156Revision($pdo)===1,'initial organization revision');

f156Reject($pdo,
    "UPDATE public.portal_organizations SET reference='another-tenant'
     WHERE id='22222222-2222-4222-8222-222222222222'");
$pdo->exec(
    "UPDATE public.portal_organizations SET status='suspended'
     WHERE id='22222222-2222-4222-8222-222222222222'"
);
f156Assert(f156Revision($pdo)===2,'suspension must bump revision');
f156Assert((int)$pdo->query(<<<'SQL'
SELECT count(*) FROM public.portal_auth_sessions s
JOIN public.portal_organizations o ON o.id=s.organization_id
WHERE s.id='55555555-5555-4555-8555-555555555555'
AND s.organization_revision < o.security_revision
SQL
)->fetchColumn()===1,'old session revision must be stale after suspension');

f156Reject($pdo,
    "UPDATE public.portal_organizations SET status='active'
     WHERE id='22222222-2222-4222-8222-222222222222'");
f156Reject($pdo,
    "UPDATE public.portal_organizations SET security_revision=1
     WHERE id='22222222-2222-4222-8222-222222222222'");
$pdo->exec(
    "UPDATE public.portal_organizations SET status='closed'
     WHERE id='22222222-2222-4222-8222-222222222222'"
);
f156Assert(f156Revision($pdo)===3,'closure must bump revision');
f156Reject($pdo,
    "UPDATE public.portal_organizations SET status='active'
     WHERE id='22222222-2222-4222-8222-222222222222'");

// A non-bypass app role cannot read or mutate state even with SQL privileges.
$pdo->exec(<<<'SQL'
CREATE ROLE portal_f156_worker NOLOGIN NOBYPASSRLS;
GRANT USAGE ON SCHEMA public TO portal_f156_worker;
GRANT SELECT,UPDATE ON public.portal_organizations TO portal_f156_worker;
SQL);
try {
    $pdo->exec('SET ROLE portal_f156_worker');
    try {
        f156Assert((int)$pdo->query(
            "SELECT count(*) FROM public.portal_organizations"
        )->fetchColumn()===0,'worker cannot read org rows');
        f156Reject($pdo,
            "UPDATE public.portal_organizations SET status='active'
             WHERE id='22222222-2222-4222-8222-222222222222'");
    } catch (RuntimeException $error) {
        // With denied RLS UPDATE on a nonexistent visible row, PG may
        // return affected=0 rather than a 42501 SQLSTATE. Verify no
        // actual data was changed instead of requiring a thrown error.
        if (!str_contains($error->getMessage(),'invalid mutation succeeded')) {
            throw $error;
        }
    } finally {
        $pdo->exec('RESET ROLE');
    }
} finally {
    $pdo->exec('DROP OWNED BY portal_f156_worker');
    $pdo->exec('DROP ROLE portal_f156_worker');
}
f156Assert(f156Revision($pdo)===3,'worker may not reopen closed organization');
f156Assert((string)$pdo->query(
    "SELECT status FROM public.portal_organizations
     WHERE id='22222222-2222-4222-8222-222222222222'"
)->fetchColumn()==='closed','closed status must be terminal');
echo "F156 PASS: status revision monotonic, old sessions stale, RLS remains deny-all.\n";
