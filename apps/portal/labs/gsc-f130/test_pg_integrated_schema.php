<?php

declare(strict_types=1);

/**
 * GSC-F130: integrated F66+F76+F113+F123-F126 schema verification.
 * This program executes ONLY on an ephemeral PG18 CI database.
 * It never contacts Google, creates a Portal login or stores credentials.
 */
function f130Assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('GSC-F130: '.$message);
    }
}

function f130ExpectSqlstate(callable $action, string $expected, string $label): void
{
    try {
        $action();
    } catch (PDOException $error) {
        f130Assert(
            $error->getCode() === $expected,
            $label.' got SQLSTATE '.$error->getCode(),
        );
        return;
    }
    throw new RuntimeException('GSC-F130 unexpectedly accepted '.$label);
}

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F130_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('GSC-F130 disposable PG18 CI only.');
}

$password = getenv('F125_PG_ADMIN_PASSWORD');
f130Assert(is_string($password) && strlen($password) > 12, 'ephemeral admin secret');
$db = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab',
    'postgres',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

foreach ([
    'portal_auth_sessions',
    'portal_membership_grants',
    'portal_gsc_property_candidates',
    'portal_gsc_vault_reference_candidates',
] as $table) {
    $stmt = $db->prepare(
        "SELECT relrowsecurity,relforcerowsecurity FROM pg_class "
        ."WHERE oid = to_regclass(:tablename)"
    );
    $stmt->execute(['tablename'=>'public.'.$table]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    f130Assert(
        $row !== false
            && in_array($row[0], [true,'t',1,'1'], true)
            && in_array($row[1], [true,'t',1,'1'], true),
        'FORCE RLS must remain enabled on '.$table,
    );
}

$policies = $db->query(
    "SELECT count(*) FROM pg_policies WHERE schemaname='public' "
    ."AND tablename IN ('portal_membership_grants', "
    ."'portal_gsc_property_candidates', 'portal_gsc_vault_reference_candidates')"
)->fetchColumn();
f130Assert((int)$policies === 0, 'no production RLS policy may be opened by migrations');

$columns = $db->query(
    "SELECT column_name FROM information_schema.columns "
    ."WHERE table_schema='public' "
    ."AND table_name='portal_gsc_vault_reference_candidates'"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    'access_token', 'refresh_token', 'client_secret',
    'token_ciphertext', 'authorization_code', 'vault_locator',
] as $forbidden) {
    f130Assert(!in_array($forbidden, $columns, true), 'no stored credential '.$forbidden);
}
$columnProbe = $db->query(
    "SELECT count(*) FROM information_schema.columns "
    ."WHERE table_schema='public' AND table_name='portal_auth_sessions' "
    ."AND column_name='organization_revision'"
)->fetchColumn();
f130Assert((int)$columnProbe === 1, 'F76 session revision must coexist with GSC migrations');

$constraint = $db->query(
    "SELECT pg_get_constraintdef(oid) FROM pg_constraint "
    ."WHERE conrelid='public.portal_membership_grants'::regclass "
    ."AND conname='portal_grant_permission_ck'"
)->fetchColumn();
f130Assert(is_string($constraint)
    && str_contains($constraint, 'seo.gsc.connect')
    && !str_contains($constraint, 'seo.gsc.manage'),
    'F113 vocabulary must allow only exact readonly connect permission');

$expectedTriggers = [
    'portal_gsc_candidate_immutable_update' => 'portal_gsc_property_candidates',
    'portal_gsc_vault_binding_guard' => 'portal_gsc_vault_reference_candidates',
];
foreach ($expectedTriggers as $trigger => $table) {
    $probe = $db->prepare(
        "SELECT count(*) FROM pg_trigger "
        ."WHERE tgrelid=to_regclass(:tablename) "
        ."AND tgname=:name AND NOT tgisinternal"
    );
    $probe->execute(['tablename'=>'public.'.$table,'name'=>$trigger]);
    f130Assert((int)$probe->fetchColumn() === 1, 'immutability guard '.$trigger);
}

f130Assert((int)$db->query(
    'SELECT count(*) FROM portal_membership_grants'
)->fetchColumn() === 0, 'no SEO grant automatically assigned by any migration');

// F125 has already installed two synthetic tenant members in this CI DB.
// Adding a grant now proves the real F113 constraint accepts that exact code
// while no migration assigned it automatically.
$member = '123e4567-e89b-42d3-a456-426614176005';
$grantId = '123e4567-e89b-42d3-a456-426614176022';
$insert = $db->prepare(
    "INSERT INTO portal_membership_grants "
    ."(id,membership_id,permission_code,created_at,updated_at) "
    ."VALUES (:id,:member,:code,now(),now())"
);
$insert->execute([
    'id'=>$grantId, 'member'=>$member,'code'=>'seo.gsc.connect',
]);

f130ExpectSqlstate(
    static fn() => $insert->execute([
        'id'=>'123e4567-e89b-42d3-a456-426614176023',
        'member'=>$member,'code'=>'seo.gsc.manage',
    ]),
    '23514', 'unapproved broader Search Console permission',
);

f130Assert((int)$db->query(
    "SELECT count(*) FROM portal_membership_grants "
    ."WHERE permission_code='seo.gsc.connect'"
)->fetchColumn() === 1, 'exactly one explicitly added synthetic SEO grant');

f130Assert((int)$db->query(
    "SELECT count(*) FROM portal_gsc_property_candidates"
)->fetchColumn() === 3, 'tenant-bound F125 fixture properties remain separate');
f130Assert((int)$db->query(
    "SELECT count(*) FROM portal_gsc_vault_reference_candidates"
)->fetchColumn() === 2, 'no OAuth token, only two vault reference digests');

echo "GSC-F130 integrated permission, property, revocation and vault schema: PASS\n";
