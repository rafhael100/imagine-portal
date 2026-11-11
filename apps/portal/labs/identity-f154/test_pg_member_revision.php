<?php

declare(strict_types=1);

/**
 * Review-only, non-operational PostgreSQL 18 fixture.
 * Never connect to real or shared databases.
 */
if (getenv('PORTAL_F154_PG_LAB') !== 'NON_OPERATIONAL_F154'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGDATABASE') !== 'portal_f154_lab'
    || getenv('PGUSER') !== 'postgres'
    || !is_string(getenv('PGPASSWORD'))
    || getenv('PGPASSWORD') !== 'f154-review-admin-only') {
    throw new RuntimeException('F154 requires the exact disposable local PG18 lab environment.');
}

$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_f154_lab;connect_timeout=3;sslmode=disable',
    'postgres',
    'f154-review-admin-only',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false],
);

function f154Ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('F154 SECURITY FAIL: '.$message);
    }
}

function f154Count(PDO $pdo, string $sql): int
{
    $value = $pdo->query($sql)?->fetchColumn();
    f154Ensure(is_int($value) || (is_string($value) && ctype_digit($value)),
        'expected count cannot be read');
    return (int) $value;
}

function f154Denied(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $error) {
        $state = (string) $error->getCode();
        f154Ensure(in_array($state, ['42501', '23514'], true),
            'unexpected SQLSTATE for rejected mutation: '.$state);
        return;
    }
    throw new RuntimeException('F154 SECURITY FAIL: forbidden mutation succeeded.');
}

f154Ensure(f154Count($pdo, "SELECT count(*) FROM public.portal_memberships") === 0,
    'lab membership table must start empty');
f154Ensure(f154Count($pdo, "SELECT count(*) FROM public.portal_membership_grants") === 0,
    'lab grants table must start empty');
f154Ensure(f154Count($pdo, "SELECT count(*) FROM public.portal_auth_sessions") === 0,
    'lab sessions table must start empty');

foreach (['portal_memberships', 'portal_membership_grants', 'portal_auth_sessions'] as $table) {
    $sql = "SELECT relrowsecurity AND relforcerowsecurity
              FROM pg_class
             WHERE oid = ('public.".$table."')::regclass";
    f154Ensure($pdo->query($sql)?->fetchColumn() === true,
        $table.' must keep FORCE RLS');
    f154Ensure(f154Count($pdo,
        "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND tablename='".$table."'"
    ) === 0, 'no app allow policy may be introduced for '.$table);
}

$pdo->exec(<<<'SQL'
INSERT INTO public.portal_identities
    (id,email_normalized,status,security_revision,created_at,updated_at)
VALUES
    ('11111111-1111-4111-8111-111111111111','f154@example.invalid','active',1,now(),now());
INSERT INTO public.portal_organizations
    (id,reference,display_name,status,security_revision,created_at,updated_at)
VALUES
    ('22222222-2222-4222-8222-222222222222','f154-review-only','F154 synthetic organization','active',1,now(),now());
INSERT INTO public.portal_memberships
    (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
VALUES
    ('33333333-3333-4333-8333-333333333333',
     '11111111-1111-4111-8111-111111111111',
     '22222222-2222-4222-8222-222222222222',
     'member','active',1,now(),now());
SQL);

$memberRev = "SELECT membership_revision FROM public.portal_memberships
                WHERE id='33333333-3333-4333-8333-333333333333'";
f154Ensure(f154Count($pdo, $memberRev) === 1, 'initial member revision');

$pdo->exec(<<<'SQL'
INSERT INTO public.portal_membership_grants
    (id,membership_id,permission_code,created_at,updated_at)
VALUES
    ('44444444-4444-4444-8444-444444444444',
     '33333333-3333-4333-8333-333333333333','services.view',now(),now());
SQL);
f154Ensure(f154Count($pdo, $memberRev) === 2,
    'explicit grant insert must revoke older sessions by incrementing revision');

$pdo->exec(<<<'SQL'
INSERT INTO public.portal_auth_sessions
    (id,session_sha256,identity_id,organization_id,status,
     identity_revision,membership_revision,issued_at,expires_at,created_at,updated_at)
VALUES
    ('55555555-5555-4555-8555-555555555555',
     'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
     '11111111-1111-4111-8111-111111111111',
     '22222222-2222-4222-8222-222222222222',
     'pending_mfa',1,2,now(),now()+interval '1 hour',now(),now());
SQL);

f154Denied($pdo,
    "UPDATE public.portal_membership_grants SET permission_code='billing.view' ".
    "WHERE id='44444444-4444-4444-8444-444444444444'");

$pdo->exec(
    "UPDATE public.portal_memberships SET status='suspended' ".
    "WHERE id='33333333-3333-4333-8333-333333333333'"
);
f154Ensure(f154Count($pdo, $memberRev) === 3,
    'suspension must increment membership revision');

$pdo->exec(
    "UPDATE public.portal_memberships SET role='manager' ".
    "WHERE id='33333333-3333-4333-8333-333333333333'"
);
f154Ensure(f154Count($pdo, $memberRev) === 4,
    'role change must increment membership revision');
f154Denied($pdo,
    "UPDATE public.portal_memberships SET membership_revision=1 ".
    "WHERE id='33333333-3333-4333-8333-333333333333'");

f154Ensure(f154Count($pdo, <<<SQL
SELECT count(*) FROM public.portal_auth_sessions s
JOIN public.portal_memberships m
  ON m.identity_id=s.identity_id AND m.organization_id=s.organization_id
WHERE s.id='55555555-5555-4555-8555-555555555555'
  AND s.membership_revision < m.membership_revision
SQL
) === 1, 'previous session revision must become stale');

$pdo->exec(
    "DELETE FROM public.portal_membership_grants ".
    "WHERE id='44444444-4444-4444-8444-444444444444'"
);
f154Ensure(f154Count($pdo, $memberRev) === 5,
    'grant deletion must increment revision');

echo "F154 PASS: grant insertion, mutation denial, status/role/revocation and FORCE RLS.\n";
