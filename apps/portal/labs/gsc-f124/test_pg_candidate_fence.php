<?php

declare(strict_types=1);

/**
 * GSC-F124: test the actual Laravel migration under disposable PostgreSQL 18.
 * No Google, Portal auth route, credential, session, or production data.
 */
function f124Check(bool $condition, string $why): void
{
    if (!$condition) {
        throw new RuntimeException('GSC-F124: '.$why);
    }
}

function f124Denied(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (PDOException $e) {
        f124Check($e->getCode() === '42501', $message.' got '.$e->getCode());
        return;
    }
    throw new RuntimeException('GSC-F124: unauthorized update allowed: '.$message);
}

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F124_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F124 requires isolated PostgreSQL 18 CI.');
}

$password = getenv('F123_PG_ADMIN_PASSWORD');
f124Check(is_string($password) && strlen($password) > 12, 'synthetic password required');

$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab',
    'postgres',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

$revoked = '123e4567-e89b-42d3-a456-426614175007';
$orgB = '123e4567-e89b-42d3-a456-426614175002';
$otherOrg = '123e4567-e89b-42d3-a456-426614175001';
$orgBRecord = '123e4567-e89b-42d3-a456-426614175008';
$prefix = '123e4567-e89b-42d3-a456-426614175009';
$actorA = '123e4567-e89b-42d3-a456-426614175003';

$triggers = $pdo->query(
    "SELECT count(*) FROM pg_trigger WHERE tgrelid="
    ."'public.portal_gsc_property_candidates'::regclass "
    ."AND tgname='portal_gsc_candidate_immutable_update' AND NOT tgisinternal"
)->fetchColumn();
f124Check((int)$triggers === 1, 'one active mutation trigger');

f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates "
        ."SET status='candidate',revoked_at=NULL,security_revision=3 "
        ."WHERE id='$revoked'"
    ),
    'terminal revoked candidate cannot return to candidate',
);
f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates "
        ."SET status='revoked',security_revision=3 "
        ."WHERE id='$revoked'"
    ),
    'terminal revoked candidate cannot be changed even without state transition',
);

f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates "
        ."SET organization_id='$otherOrg',security_revision=2 "
        ."WHERE id='$orgBRecord'"
    ),
    'company binding is immutable',
);
f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates "
        ."SET created_by_identity_id='$actorA',security_revision=2 "
        ."WHERE id='$orgBRecord'"
    ),
    'original Portal member is immutable',
);

$other = 'https://changed.example/';
$digest = hash('sha256', $other);
$stmt = $pdo->prepare(
    "UPDATE portal_gsc_property_candidates SET site_url=:site,"
    ."site_fingerprint_sha256=:digest,security_revision=2 WHERE id=:id"
);
f124Denied(
    fn()=> $stmt->execute([
        'site'=>$other,'digest'=>$digest,'id'=>$orgBRecord,
    ]),
    'even matching digest does not permit property replacement',
);
f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates "
        ."SET site_type='url_prefix',security_revision=2 "
        ."WHERE id='$orgBRecord'"
    ),
    'site type remains immutable',
);
f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates SET updated_at=now() "
        ."WHERE id='$orgBRecord'"
    ),
    'every update requires advancing security revision',
);

$pdo->exec(
    "UPDATE portal_gsc_property_candidates "
    ."SET status='disabled',security_revision=2,updated_at=now() "
    ."WHERE id='$prefix'"
);
f124Check((int)$pdo->query(
    "SELECT security_revision FROM portal_gsc_property_candidates WHERE id='$prefix'"
)->fetchColumn() === 2, 'candidate to disabled advances revision');

f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates "
        ."SET status='candidate',security_revision=3 WHERE id='$prefix'"
    ),
    'disabled status cannot be silently restored to candidate',
);
f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates "
        ."SET status='disabled',security_revision=2 WHERE id='$prefix'"
    ),
    'same or stale security revision is denied',
);

$pdo->exec(
    "UPDATE portal_gsc_property_candidates "
    ."SET status='revoked',revoked_at=now(),security_revision=3,updated_at=now() "
    ."WHERE id='$prefix'"
);
f124Denied(
    fn()=> $pdo->exec(
        "UPDATE portal_gsc_property_candidates "
        ."SET status='disabled',revoked_at=NULL,security_revision=4 "
        ."WHERE id='$prefix'"
    ),
    'disabled to revoked is terminal',
);

f124Check((int)$pdo->query(
    "SELECT count(*) FROM portal_gsc_property_candidates"
)->fetchColumn() === 3, 'mutation attacks did not add/delete records');
f124Check((int)$pdo->query(
    "SELECT count(*) FROM portal_gsc_property_candidates WHERE status='revoked'"
)->fetchColumn() === 2, 'two distinct records now have terminal revocation');

echo "GSC-F124 irreversible property candidate mutation guard: PASS\n";
