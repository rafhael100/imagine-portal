<?php

declare(strict_types=1);

/**
 * GSC-F133: disposable PostgreSQL 18 proof of the actual Portal IAM tables,
 * concurrency fence and RLS. Uses synthetically fabricated subjects only.
 */
require_once __DIR__.'/../../vendor/autoload.php';

use App\Domain\SeoReview\GoogleSearchConsoleIamWitness;

function f133Assert(bool $ok, string $why): void
{
    if (!$ok) {
        throw new RuntimeException('GSC-F133: '.$why);
    }
}

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F133_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('GSC-F133 can run only in disposable PG18 CI.');
}
$password = getenv('F125_PG_ADMIN_PASSWORD');
f133Assert(is_string($password) && strlen($password) > 12, 'CI admin only');

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab';
$connection = static function (string $user, string $secret) use ($dsn): PDO {
    return new PDO($dsn, $user, $secret, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
};

$admin = $connection('postgres', $password);
$probe = new GoogleSearchConsoleIamWitness($admin);
$orgA = '123e4567-e89b-42d3-a456-426614176001';
$orgB = '123e4567-e89b-42d3-a456-426614176002';
$actorA = '123e4567-e89b-42d3-a456-426614176003';
$memberA = '123e4567-e89b-42d3-a456-426614176005';
$sessionId = '123e4567-e89b-42d3-a456-426614176024';
$sessionHash = hash('sha256', 'synthetic-f133-portal-only-session-ci-no-browser');
$site = 'sc-domain:example.com';

$admin->exec(
    "UPDATE public.portal_identities "
    ."SET email_verified_at=clock_timestamp()-interval '1 day', "
    ."mfa_confirmed_at=clock_timestamp()-interval '2 hours' "
    ."WHERE id='$actorA'"
);

$issue = $admin->prepare(<<<'SQL'
INSERT INTO public.portal_auth_sessions (
    id, session_sha256, identity_id, organization_id,
    status, identity_revision, membership_revision, organization_revision,
    mfa_verified_at, issued_at, expires_at, created_at, updated_at
) VALUES (
    :id, :digest, :actor, :organization,
    'active', 1, 1, 1,
    clock_timestamp()-interval '30 minutes',
    clock_timestamp()-interval '1 hour',
    clock_timestamp()+interval '2 hours',
    clock_timestamp(), clock_timestamp()
)
SQL);
$issue->execute([
    'id'=>$sessionId,'digest'=>$sessionHash,
    'actor'=>$actorA,'organization'=>$orgA,
]);

$review = static function (
    PDO $db, string $digest, string $organization, string $property,
): bool {
    $witness = (new GoogleSearchConsoleIamWitness($db))->inspect(
        $digest, $organization, $property,
    );
    f133Assert(!$witness->canStartOAuth()
        && !$witness->canReadGoogle()
        && !$witness->canIssueOrStoreTokens(),
        'database witness must NEVER authorize external operations');
    return $witness->syntheticDatabaseRowsMatch;
};

f133Assert(!$review($admin, $sessionHash, $orgA, $site),
    'without caller-managed transaction no witness is allowed');

$admin->beginTransaction();
f133Assert($review($admin, $sessionHash, $orgA, $site),
    'six real Portal IAM rows match an explicit grant and property candidate');
f133Assert(!$review($admin, $sessionHash, $orgB, $site),
    'same site in another tenant does not grant access to an unrelated session');
f133Assert(!$review($admin, $sessionHash, $orgA, 'https://example.com/'),
    'domain candidate never implies other URL-prefix properties');
f133Assert(!$review($admin, hash('sha256','crm-cookie-different'), $orgA, $site),
    'CRM cookie never matches the Portal session hash');
f133Assert(!$review($admin, 'not-a-digest', $orgA, $site),
    'invalid session hash is rejected before SQL');
f133Assert(!$review($admin, $sessionHash, 'invalid-org-id', $site),
    'malformed organization reference rejected');
f133Assert(!$review($admin, $sessionHash, $orgA, 'sc-domain:EXAMPLE.com'),
    'noncanonical property rejected');
$admin->commit();

$changeCases = [
    ['session revoked',
        "UPDATE public.portal_auth_sessions SET status='revoked', "
        ."revoked_at=clock_timestamp() WHERE id='$sessionId'"],
    ['MFA removed',
        "UPDATE public.portal_identities SET mfa_confirmed_at=NULL "
        ."WHERE id='$actorA'"],
    ['identity suspended',
        "UPDATE public.portal_identities SET status='suspended',"
        ."security_revision=2 WHERE id='$actorA'"],
    ['organization suspended',
        "UPDATE public.portal_organizations SET status='suspended',"
        ."security_revision=2 WHERE id='$orgA'"],
    ['membership revoked',
        "UPDATE public.portal_memberships SET status='revoked',"
        ."membership_revision=2 WHERE id='$memberA'"],
    ['grant removed',
        "DELETE FROM public.portal_membership_grants "
        ."WHERE membership_id='$memberA' AND permission_code='seo.gsc.connect'"],
    ['property candidate revoked',
        "UPDATE public.portal_gsc_property_candidates "
        ."SET status='revoked',revoked_at=clock_timestamp(),security_revision=2 "
        ."WHERE organization_id='$orgA' AND site_url='$site'"],
    ['identity revision mismatch',
        "UPDATE public.portal_auth_sessions SET identity_revision=99 "
        ."WHERE id='$sessionId'"],
    ['membership revision mismatch',
        "UPDATE public.portal_auth_sessions SET membership_revision=99 "
        ."WHERE id='$sessionId'"],
    ['organization revision mismatch',
        "UPDATE public.portal_auth_sessions SET organization_revision=99 "
        ."WHERE id='$sessionId'"],
    ['email no longer verified',
        "UPDATE public.portal_identities SET email_verified_at=NULL WHERE id='$actorA'"],
];
foreach ($changeCases as [$label, $sql]) {
    $admin->beginTransaction();
    try {
        $admin->exec($sql);
        f133Assert(!$review($admin, $sessionHash, $orgA, $site),
            'fresh database witness must fail after '.$label);
    } finally {
        $admin->rollBack();
    }
}

// The F66/F76/F113/F123 schema starts with FORCE RLS and NO SELECT
// policies for ordinary roles. Even an accidental SQL GRANT cannot
// expose a session or yield a usable positive database witness.
$admin->exec(
    "CREATE ROLE portal_gsc_f133_reader LOGIN PASSWORD 'ci-f133-reader-only' "
    ."NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS"
);
$admin->exec('GRANT CONNECT ON DATABASE portal_identity_lab TO portal_gsc_f133_reader');
$admin->exec('GRANT USAGE ON SCHEMA public TO portal_gsc_f133_reader');
foreach ([
    'portal_auth_sessions','portal_identities','portal_organizations',
    'portal_memberships','portal_membership_grants',
    'portal_gsc_property_candidates',
] as $table) {
    $admin->exec('GRANT SELECT ON public.'.$table.' TO portal_gsc_f133_reader');
}
$reader = $connection('portal_gsc_f133_reader', 'ci-f133-reader-only');
f133Assert((int)$reader->query(
    'SELECT count(*) FROM public.portal_auth_sessions'
)->fetchColumn() === 0, 'ordinary SQL GRANT cannot bypass session RLS');
$reader->beginTransaction();
f133Assert(!$review($reader, $sessionHash, $orgA, $site),
    'ordinary app role must remain denied without RLS policies');
$reader->rollBack();
$reader = null;
$admin->exec('DROP OWNED BY portal_gsc_f133_reader');
$admin->exec('DROP ROLE portal_gsc_f133_reader');

// A successful witness holds FOR SHARE on all six rows until the caller
// commits. A concurrent writer attempting revocation must wait, not
// silently overtake the approval boundary in another transaction.
$writer = $connection('postgres', $password);
$admin->beginTransaction();
f133Assert($review($admin, $sessionHash, $orgA, $site),
    'pre-lock transaction still matches active session');

$writer->beginTransaction();
$writer->exec("SET LOCAL lock_timeout = '300ms'");
$blocked = false;
try {
    $writer->exec(
        "UPDATE public.portal_auth_sessions SET status='revoked',"
        ."revoked_at=clock_timestamp() WHERE id='$sessionId'"
    );
} catch (PDOException $error) {
    $blocked = $error->getCode() === '55P03';
}
$writer->rollBack();
f133Assert($blocked, 'concurrent session revocation must block behind FOR SHARE');
$admin->commit();

// After releasing the transaction, revocation must take effect and
// all future witnesses must see the new state immediately.
$writer->exec(
    "UPDATE public.portal_auth_sessions SET status='revoked',"
    ."revoked_at=clock_timestamp() WHERE id='$sessionId'"
);
$admin->beginTransaction();
f133Assert(!$review($admin, $sessionHash, $orgA, $site),
    'revocation committed before new read denies the witness');
$admin->commit();

// CI-only cleanup. F130 still expects exactly one synthetic SEO grant,
// two vault reference metadata rows and three candidate properties.
$admin->exec("DELETE FROM public.portal_auth_sessions WHERE id='$sessionId'");
$admin->exec(
    "UPDATE public.portal_identities SET email_verified_at=NULL,"
    ."mfa_confirmed_at=NULL WHERE id='$actorA'"
);
f133Assert((int)$admin->query(
    "SELECT count(*) FROM public.portal_membership_grants "
    ."WHERE membership_id='$memberA' AND permission_code='seo.gsc.connect'"
)->fetchColumn() === 1, 'F130 synthetic grant remains for its own rollback test');

echo "GSC-F133 real Portal IAM schema predicate + RLS + row-lock serialization: PASS\n";
