<?php

declare(strict_types=1);

function f126Assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('GSC-F126: '.$label);
    }
}

function f126Denied(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (PDOException $error) {
        f126Assert($error->getCode() === '42501', $label.' (SQLSTATE '.$error->getCode().')');
        return;
    }
    throw new RuntimeException('GSC-F126: unexpectedly allowed '.$label);
}

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F126_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('GSC-F126 only permits disposable PG18 CI.');
}

$password = getenv('F125_PG_ADMIN_PASSWORD');
f126Assert(is_string($password) && strlen($password) > 12, 'synthetic CI credentials');

$db = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab',
    'postgres',
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false]
);

$first = '123e4567-e89b-42d3-a456-426614176010';
$second = '123e4567-e89b-42d3-a456-426614176011';
$companyA = '123e4567-e89b-42d3-a456-426614176001';
$companyB = '123e4567-e89b-42d3-a456-426614176002';
$propertyA = '123e4567-e89b-42d3-a456-426614176007';
$propertyB = '123e4567-e89b-42d3-a456-426614176008';
$readonly = 'https://www.googleapis.com/auth/webmasters.readonly';

f126Assert((int)$db->query(
    'SELECT count(*) FROM public.portal_gsc_vault_reference_candidates'
)->fetchColumn() === 2, 'F125 must install two synthetic metadata rows');

f126Assert((int)$db->query(
    "SELECT count(*) FROM pg_trigger WHERE tgrelid="
    ."'public.portal_gsc_vault_reference_candidates'::regclass "
    ."AND tgname='portal_gsc_vault_binding_guard' AND NOT tgisinternal"
)->fetchColumn() === 1, 'one insert/update mutation guard exists');

f126Denied(static function () use ($db, $companyA, $propertyA, $readonly): void {
    $stmt = $db->prepare(
        'INSERT INTO public.portal_gsc_vault_reference_candidates '
        .'(id,organization_id,candidate_id,vault_reference_sha256,key_version,'
        .'scope,status,binding_revision,revoked_at,created_at,updated_at) '
        .'VALUES (:id,:org,:property,:digest,:version,:scope,'
        ."'revoked',1,now(),now(),now())"
    );
    $stmt->execute([
        'id'=>'123e4567-e89b-42d3-a456-426614176020',
        'org'=>$companyA,
        'property'=>$propertyA,
        'digest'=>hash('sha256','fake-second-locator'),
        'version'=>'gsc-ci-v1',
        'scope'=>$readonly,
    ]);
}, 'direct insertion of an already revoked binding');

f126Denied(static function () use ($db, $companyA, $propertyA, $readonly): void {
    $stmt = $db->prepare(
        'INSERT INTO public.portal_gsc_vault_reference_candidates '
        .'(id,organization_id,candidate_id,vault_reference_sha256,key_version,'
        .'scope,status,binding_revision,created_at,updated_at) '
        .'VALUES (:id,:org,:property,:digest,:version,:scope,'
        ."'pending_review',9,now(),now())"
    );
    $stmt->execute([
        'id'=>'123e4567-e89b-42d3-a456-426614176021',
        'org'=>$companyA,
        'property'=>$propertyA,
        'digest'=>hash('sha256','fake-third-locator'),
        'version'=>'gsc-ci-v1',
        'scope'=>$readonly,
    ]);
}, 'direct insertion with abnormal binding revision');

foreach ([
    ["organization_id='$companyB'", 'company reassignment'],
    ["candidate_id='$propertyB'", 'property reassignment'],
    ["vault_reference_sha256='".hash('sha256', 'different-locator')."'", 'vault locator swap'],
    ["key_version='gsc-ci-v2'", 'key version rewrite'],
    ["scope='https://www.googleapis.com/auth/webmasters'", 'Google scope escalation'],
    ["status='pending_review',binding_revision=2", 'no-op revision change'],
    ["status='revoked',binding_revision=1,revoked_at=now()", 'stale revision on revoke'],
    ["status='revoked',binding_revision=3,revoked_at=now()", 'skipped revision on revoke'],
    ["status='revoked',binding_revision=2,revoked_at=now()+interval '1 day'", 'future revocation timestamp'],
    ["created_at=now()+interval '1 day'", 'created time rewrite'],
] as [$change, $label]) {
    f126Denied(
        fn() => $db->exec(
            'UPDATE public.portal_gsc_vault_reference_candidates SET '
            .$change.",updated_at=now() WHERE id='$first'"
        ),
        $label,
    );
}

$db->exec(
    "UPDATE public.portal_gsc_vault_reference_candidates "
    ."SET status='revoked',revoked_at=now(),binding_revision=2,updated_at=now() "
    ."WHERE id='$first'"
);

f126Assert((int)$db->query(
    "SELECT binding_revision FROM public.portal_gsc_vault_reference_candidates WHERE id='$first'"
)->fetchColumn() === 2, 'valid revoke increments the revision by exactly one');

f126Denied(
    fn() => $db->exec(
        "UPDATE public.portal_gsc_vault_reference_candidates "
        ."SET status='pending_review',revoked_at=NULL,binding_revision=3 "
        ."WHERE id='$first'"
    ),
    'recreating pending from revoked',
);

f126Denied(
    fn() => $db->exec(
        "UPDATE public.portal_gsc_vault_reference_candidates "
        ."SET status='revoked',binding_revision=3 "
        ."WHERE id='$first'"
    ),
    'changing terminally revoked binding',
);

f126Assert((int)$db->query(
    'SELECT count(*) FROM public.portal_gsc_vault_reference_candidates'
)->fetchColumn() === 2, 'all mutation probes left only the original rows');
f126Assert((int)$db->query(
    "SELECT count(*) FROM public.portal_gsc_vault_reference_candidates "
    ."WHERE status='revoked' AND binding_revision=2"
)->fetchColumn() === 1, 'one permanently revoked binding');
f126Assert((int)$db->query(
    "SELECT count(*) FROM public.portal_gsc_vault_reference_candidates "
    ."WHERE status='pending_review' AND binding_revision=1"
)->fetchColumn() === 1, 'other tenant remains pending');

echo "GSC-F126 immutable binding and single irreversible revocation: PASS\n";
