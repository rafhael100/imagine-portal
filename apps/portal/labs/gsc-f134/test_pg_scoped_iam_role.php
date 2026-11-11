<?php

declare(strict_types=1);

/**
 * GSC-F134 — PostgreSQL 18 CI ONLY.
 *
 * Proves F133's reader can return a positive diagnostic witness with a
 * NOLOGIN? No: it uses a LOGIN test role that is strictly NOSUPERUSER,
 * NOBYPASSRLS, NOINHERIT and may UPDATE only the harmless updated_at column
 * for SELECT FOR SHARE's PG18 privilege requirement. This role and the
 * narrowly fixture-scoped RLS policies are never part of any migration,
 * application service-provider or production deployment.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\GoogleSearchConsoleIamWitness;

function f134Assert(bool $ok, string $reason): void
{
    if (!$ok) {
        throw new RuntimeException('GSC-F134: '.$reason);
    }
}

function f134Sqlstate(callable $action, string $state, string $label): void
{
    try {
        $action();
    } catch (PDOException $exception) {
        f134Assert(
            $exception->getCode() === $state,
            $label.' unexpected SQLSTATE '.$exception->getCode(),
        );
        return;
    }
    throw new RuntimeException('GSC-F134 unexpectedly allowed '.$label);
}

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F134_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('GSC-F134 restricted to disposable PostgreSQL 18 CI');
}
$password = getenv('F125_PG_ADMIN_PASSWORD');
f134Assert(is_string($password) && strlen($password) > 12, 'synthetic admin credentials');

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab';
$connect = static fn(string $user, string $secret): PDO =>
    new PDO($dsn, $user, $secret, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

$admin = $connect('postgres', $password);

$role = 'portal_gsc_f134_reader';
$orgA = '123e4567-e89b-42d3-a456-426614176001';
$orgB = '123e4567-e89b-42d3-a456-426614176002';
$actorA = '123e4567-e89b-42d3-a456-426614176003';
$memberA = '123e4567-e89b-42d3-a456-426614176005';
$propertyA = '123e4567-e89b-42d3-a456-426614176007';
$sessionId = '123e4567-e89b-42d3-a456-426614176025';
$sessionDigest = hash('sha256', 'f134-only-synthetic-first-party-portal-session');
$site = 'sc-domain:example.com';

// F133 cleaned up its synthetic session. F130's explicit SEO grant and
// F125's tenant property fixtures remain until the integrated rollback.
f134Assert((int)$admin->query(
    "SELECT count(*) FROM public.portal_membership_grants "
    ."WHERE membership_id='$memberA' AND permission_code='seo.gsc.connect'"
)->fetchColumn() === 1, 'F130 explicit grant fixture is present');

$admin->exec(
    "UPDATE public.portal_identities "
    ."SET email_verified_at=clock_timestamp()-interval '1 day', "
    ."mfa_confirmed_at=clock_timestamp()-interval '2 hours' "
    ."WHERE id='$actorA'"
);
$issue = $admin->prepare(<<<'SQL'
INSERT INTO public.portal_auth_sessions (
    id,session_sha256,identity_id,organization_id,
    status,identity_revision,membership_revision,organization_revision,
    issued_at,expires_at,mfa_verified_at,created_at,updated_at
) VALUES (
    :id,:digest,:actor,:org,
    'active',1,1,1,
    clock_timestamp()-interval '1 hour',
    clock_timestamp()+interval '2 hours',
    clock_timestamp()-interval '30 minutes',
    clock_timestamp(),clock_timestamp()
)
SQL);
$issue->execute([
    'id'=>$sessionId,
    'digest'=>$sessionDigest,
    'actor'=>$actorA,
    'org'=>$orgA,
]);

$admin->exec(
    "CREATE ROLE $role LOGIN PASSWORD 'ci-f134-reader-only' "
    ."NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS"
);
$admin->exec("GRANT CONNECT ON DATABASE portal_identity_lab TO $role");
$admin->exec("GRANT USAGE ON SCHEMA public TO $role");

$scopedTables = [
    'portal_auth_sessions' => "organization_id='$orgA'",
    'portal_identities' => "id='$actorA'",
    'portal_organizations' => "id='$orgA'",
    'portal_memberships' => "identity_id='$actorA' AND organization_id='$orgA'",
    'portal_membership_grants' => "membership_id='$memberA'",
    'portal_gsc_property_candidates' => "organization_id='$orgA'",
];
foreach ($scopedTables as $table => $condition) {
    // PG18 requires UPDATE privilege on >=1 column for SELECT FOR SHARE.
    // Only updated_at can be modified by this temporary role, not status,
    // secret, permission_code, revisions, owner IDs or property digests.
    $admin->exec("GRANT SELECT ON public.$table TO $role");
    $admin->exec("GRANT UPDATE(updated_at) ON public.$table TO $role");

    $name = 'gsc_f134_'.str_replace('portal_', '', $table);
    $admin->exec(
        "CREATE POLICY {$name}_read ON public.$table "
        ."FOR SELECT TO $role USING ($condition)"
    );
    $admin->exec(
        "CREATE POLICY {$name}_write ON public.$table "
        ."FOR UPDATE TO $role USING ($condition) WITH CHECK ($condition)"
    );
}

$reader = $connect($role,'ci-f134-reader-only');
f134Assert((int)$reader->query(
    'SELECT count(*) FROM public.portal_auth_sessions'
)->fetchColumn() === 1, 'non-superuser can read only its scoped synthetic session');
f134Assert((int)$reader->query(
    "SELECT count(*) FROM public.portal_organizations WHERE id='$orgB'"
)->fetchColumn() === 0, 'RLS hides the other organization');
f134Assert((int)$reader->query(
    "SELECT count(*) FROM public.portal_gsc_property_candidates "
    ."WHERE organization_id='$orgB'"
)->fetchColumn() === 0, 'RLS hides other tenant property candidates');

f134Sqlstate(
    fn() => $reader->exec(
        "UPDATE public.portal_auth_sessions SET status='revoked' "
        ."WHERE id='$sessionId'"
    ),
    '42501',
    'least-privilege role cannot alter authorization status',
);
f134Sqlstate(
    fn() => $reader->exec(
        "UPDATE public.portal_membership_grants SET permission_code='services.manage' "
        ."WHERE membership_id='$memberA'"
    ),
    '42501',
    'least-privilege role cannot rewrite SEO grants',
);

$review = static function (
    PDO $db, string $digest, string $org, string $property,
): bool {
    $witness = (new GoogleSearchConsoleIamWitness($db))->inspect(
        $digest, $org, $property,
    );
    f134Assert(!$witness->canStartOAuth()
        && !$witness->canReadGoogle()
        && !$witness->canIssueOrStoreTokens(),
        'a diagnostic DB witness is never an operational OAuth authorization');
    return $witness->syntheticDatabaseRowsMatch;
};

f134Assert(!$review($reader, $sessionDigest, $orgA, $site),
    'transaction required even with a scoped database role');
$reader->beginTransaction();
f134Assert($review($reader, $sessionDigest, $orgA, $site),
    'positive database witness works with scoped NOSUPERUSER NOBYPASSRLS role');
f134Assert(!$review($reader, $sessionDigest, $orgB, $site),
    'session and property cannot be reused across tenants');
f134Assert(!$review($reader, $sessionDigest, $orgA, 'https://example.com/'),
    'URL-prefix and domain property remain distinct');
$reader->commit();

$writer = $connect('postgres', $password);
foreach ([
    [
        'revocation of explicit SEO grant',
        "DELETE FROM public.portal_membership_grants "
        ."WHERE membership_id='$memberA' AND permission_code='seo.gsc.connect'",
    ],
    [
        'revocation of Search Console property',
        "UPDATE public.portal_gsc_property_candidates "
        ."SET status='revoked',revoked_at=clock_timestamp(),security_revision=2 "
        ."WHERE id='$propertyA'",
    ],
] as [$label,$revoke]) {
    $reader->beginTransaction();
    f134Assert($review($reader,$sessionDigest,$orgA,$site),
        'scoped reader must hold current row locks before '.$label);

    $writer->beginTransaction();
    $writer->exec("SET LOCAL lock_timeout = '300ms'");
    f134Sqlstate(
        fn() => $writer->exec($revoke),
        '55P03',
        $label.' must not overtake the active FOR SHARE witness',
    );
    $writer->rollBack();
    $reader->commit();
}

// After the locks are released, an independently committed grant
// revocation must immediately invalidate all subsequent witness checks.
$writer->beginTransaction();
$writer->exec(
    "DELETE FROM public.portal_membership_grants "
    ."WHERE membership_id='$memberA' AND permission_code='seo.gsc.connect'"
);
$writer->commit();
$reader->beginTransaction();
f134Assert(!$review($reader,$sessionDigest,$orgA,$site),
    'committed grant revocation denies subsequent restricted-role witness');
$reader->commit();

// The F130 rollback expects exactly one synthetic SEO grant. Recreate
// only this CI fixture with its original ID, never an operational grant.
$grant = $admin->prepare(
    "INSERT INTO public.portal_membership_grants "
    ."(id,membership_id,permission_code,created_at,updated_at) "
    ."VALUES (:id,:member,'seo.gsc.connect',now(),now())"
);
$grant->execute([
    'id'=>'123e4567-e89b-42d3-a456-426614176022',
    'member'=>$memberA,
]);

// Remove all test policies/roles before the F130 schema rollback.
// No test-only role, RLS policy, or session is left on this branch.
$reader = null;
$writer = null;
foreach ($scopedTables as $table=>$condition) {
    $name='gsc_f134_'.str_replace('portal_', '', $table);
    $admin->exec("DROP POLICY {$name}_read ON public.$table");
    $admin->exec("DROP POLICY {$name}_write ON public.$table");
}
$admin->exec("DROP OWNED BY $role");
$admin->exec("DROP ROLE $role");
$admin->exec("DELETE FROM public.portal_auth_sessions WHERE id='$sessionId'");
$admin->exec(
    "UPDATE public.portal_identities SET email_verified_at=NULL,"
    ."mfa_confirmed_at=NULL WHERE id='$actorA'"
);

f134Assert((int)$admin->query(
    "SELECT count(*) FROM pg_policies WHERE schemaname='public' "
    ."AND policyname LIKE 'gsc_f134_%'"
)->fetchColumn() === 0, 'ephemeral reader RLS policies must be removed');
f134Assert((int)$admin->query(
    "SELECT count(*) FROM pg_roles WHERE rolname='$role'"
)->fetchColumn() === 0, 'ephemeral reader SQL role must be removed');
f134Assert((int)$admin->query(
    "SELECT count(*) FROM public.portal_membership_grants "
    ."WHERE membership_id='$memberA' AND permission_code='seo.gsc.connect'"
)->fetchColumn() === 1, 'synthetic SEO grant restored for F130 rollback');

echo "GSC-F134 non-superuser scoped-RLS IAM witness + concurrent revoke: PASS\n";
