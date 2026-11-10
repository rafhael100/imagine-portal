<?php

declare(strict_types=1);

require_once __DIR__.'/../../app/Domain/SeoReview/GoogleSearchConsolePropertyIdentifier.php';

use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier as SiteUrl;

function f123Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('GSC-F123: '.$message);
    }
}

function f123FailsWith(callable $operation, string $sqlstate, string $message): void
{
    try {
        $operation();
    } catch (PDOException $e) {
        f123Assert($e->getCode() === $sqlstate, $message.': '.$e->getCode());
        return;
    }
    throw new RuntimeException('GSC-F123: unexpectedly accepted '.$message);
}

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F123_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('GSC-F123 runs ONLY in disposable PG18 CI.');
}
$secret = getenv('F123_PG_ADMIN_PASSWORD');
f123Assert(is_string($secret) && strlen($secret) > 12, 'test-only admin credential');

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab';
$admin = new PDO($dsn, 'postgres', $secret, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$orgA = '123e4567-e89b-42d3-a456-426614175001';
$orgB = '123e4567-e89b-42d3-a456-426614175002';
$actorA = '123e4567-e89b-42d3-a456-426614175003';
$actorB = '123e4567-e89b-42d3-a456-426614175004';
$membershipA = '123e4567-e89b-42d3-a456-426614175005';
$membershipB = '123e4567-e89b-42d3-a456-426614175006';
$site = 'sc-domain:imaginecomunicacao.com.br';
$prefix = 'https://www.imaginecomunicacao.com.br/';

f123Assert((int) $admin->query(
    'SELECT count(*) FROM public.portal_gsc_property_candidates'
)->fetchColumn() === 0, 'migration adds NO candidates by default');

$rls = $admin->query(
    "SELECT relrowsecurity,relforcerowsecurity FROM pg_class "
    ."WHERE oid='public.portal_gsc_property_candidates'::regclass"
)->fetch(PDO::FETCH_ASSOC);
f123Assert($rls !== false
    && in_array($rls['relrowsecurity'], ['t', true, '1', 1], true)
    && in_array($rls['relforcerowsecurity'], ['t', true, '1', 1], true),
    'table enables FORCE RLS');

f123Assert((int) $admin->query(
    "SELECT count(*) FROM pg_policies WHERE schemaname='public' "
    ."AND tablename='portal_gsc_property_candidates'"
)->fetchColumn() === 0, 'table has no RLS policies');

f123Assert(
    $admin->query(
        "SELECT encode(sha256(convert_to('abc','UTF8')),'hex')"
    )->fetchColumn() === hash('sha256', 'abc'),
    'native PG18 sha256 is consistent with PHP',
);

$identityInsert = $admin->prepare(
    "INSERT INTO portal_identities "
    ."(id,email_normalized,status,created_at,updated_at) "
    ."VALUES (:id,:email,'active',now(),now())"
);
$identityInsert->execute(['id'=>$actorA,'email'=>'gsc-f123-a@example.invalid']);
$identityInsert->execute(['id'=>$actorB,'email'=>'gsc-f123-b@example.invalid']);

$orgInsert = $admin->prepare(
    "INSERT INTO portal_organizations "
    ."(id,reference,display_name,status,created_at,updated_at) "
    ."VALUES (:id,:ref,:name,'active',now(),now())"
);
$orgInsert->execute(['id'=>$orgA,'ref'=>'gsc-f123-org-a','name'=>'Synthetic GSC A']);
$orgInsert->execute(['id'=>$orgB,'ref'=>'gsc-f123-org-b','name'=>'Synthetic GSC B']);

$membershipInsert = $admin->prepare(
    "INSERT INTO portal_memberships "
    ."(id,identity_id,organization_id,role,status,created_at,updated_at) "
    ."VALUES (:id,:identity,:org,'owner','active',now(),now())"
);
$membershipInsert->execute([
    'id'=>$membershipA,'identity'=>$actorA,'org'=>$orgA,
]);
$membershipInsert->execute([
    'id'=>$membershipB,'identity'=>$actorB,'org'=>$orgB,
]);

$insert = $admin->prepare(
    'INSERT INTO portal_gsc_property_candidates '
    .'(id,organization_id,created_by_identity_id,site_url,site_fingerprint_sha256,'
    .'site_type,status,created_at,updated_at) '
    ."VALUES (:id,:org,:actor,:url,:digest,:type,:status,now(),now())"
);
$make = static function (
    string $id,
    string $org,
    string $actor,
    string $url,
    string $type,
    string $status = 'candidate',
    ?string $digestOverride = null,
) use ($insert): void {
    $digest = $digestOverride ?? hash('sha256', $url);
    $insert->execute([
        'id'=>$id,'org'=>$org,'actor'=>$actor,'url'=>$url,
        'digest'=>$digest,'type'=>$type,'status'=>$status,
    ]);
};

$make('123e4567-e89b-42d3-a456-426614175007',$orgA,$actorA,$site,'domain');
$make('123e4567-e89b-42d3-a456-426614175008',$orgB,$actorB,$site,'domain');
$make('123e4567-e89b-42d3-a456-426614175009',$orgA,$actorA,$prefix,'url_prefix');

f123Assert(SiteUrl::fingerprint($site) === hash('sha256', $site), 'F120 exact property digest');
f123Assert((int)$admin->query(
    "SELECT count(*) FROM portal_gsc_property_candidates "
    ."WHERE site_fingerprint_sha256='".hash('sha256', $site)."'"
)->fetchColumn() === 2, 'same site may be merely proposed by two different tenants');

f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175010',$orgA,$actorA,$site,'domain'),
    '23505','same tenant and exact property duplicate rejected',
);
f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175011',$orgB,$actorA,$prefix,'url_prefix'),
    '23503','founder cannot be attributed to another tenant',
);
f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175012',$orgA,$actorA,$site,'domain',
        'candidate', str_repeat('a',64)),
    '23514','mismatched site_url and fingerprint rejected in DB',
);
f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175013',$orgA,$actorA,
        'https://example.com/','domain'),
    '23514','domain type cannot masquerade as URL prefix',
);
f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175014',$orgA,$actorA,
        'sc-domain:example.com','url_prefix'),
    '23514','URL prefix type cannot masquerade as domain',
);
f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175015',$orgA,$actorA,
        'sc-domain:example.com','domain','approved'),
    '23514','no approved status exists in this schema',
);
f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175016',$orgA,$actorA,
        'https://example.com/%2e%2e/','url_prefix'),
    '23514','encoded URL path rejected',
);
f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175017',$orgA,$actorA,
        'https://example.com/?token=x','url_prefix'),
    '23514','query params blocked',
);
f123FailsWith(
    fn()=> $make('123e4567-e89b-42d3-a456-426614175018',$orgA,$actorA,
        'https://example.com/a b/','url_prefix'),
    '23514','whitespace blocked',
);

f123FailsWith(
    fn()=> $admin->exec(
        "UPDATE public.portal_gsc_property_candidates SET status='revoked' "
        ."WHERE id='123e4567-e89b-42d3-a456-426614175007'"
    ), '23514','revocation must record timestamp',
);
$admin->exec(
    "UPDATE public.portal_gsc_property_candidates "
    ."SET status='revoked',revoked_at=now(),security_revision=2 "
    ."WHERE id='123e4567-e89b-42d3-a456-426614175007'"
);
f123FailsWith(
    fn()=> $admin->exec(
        "UPDATE public.portal_gsc_property_candidates SET status='candidate' "
        ."WHERE id='123e4567-e89b-42d3-a456-426614175007'"
    ), '23514','revoked record cannot regain candidate state without clearing timestamp',
);

$admin->exec(
    "CREATE ROLE portal_gsc_f123_reader LOGIN PASSWORD 'ci-f123-reader-only' "
    ."NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS"
);
$admin->exec('GRANT CONNECT ON DATABASE portal_identity_lab TO portal_gsc_f123_reader');
$admin->exec('GRANT USAGE ON SCHEMA public TO portal_gsc_f123_reader');
$admin->exec(
    'GRANT SELECT, INSERT ON public.portal_gsc_property_candidates TO portal_gsc_f123_reader'
);

$reader = new PDO($dsn, 'portal_gsc_f123_reader', 'ci-f123-reader-only', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
f123Assert((int)$reader->query(
    'SELECT count(*) FROM public.portal_gsc_property_candidates'
)->fetchColumn() === 0, 'ordinary SQL GRANT cannot reveal any tenant candidates without RLS');

f123FailsWith(static function () use ($reader,$orgA,$actorA): void {
    $stmt = $reader->prepare(
        "INSERT INTO portal_gsc_property_candidates "
        ."(id,organization_id,created_by_identity_id,site_url,site_fingerprint_sha256,"
        ."site_type,status,created_at,updated_at) "
        ."VALUES (:id,:org,:actor,:url,:digest,'domain','candidate',now(),now())"
    );
    $url = 'sc-domain:untrusted.example';
    $stmt->execute([
        'id'=>'123e4567-e89b-42d3-a456-426614175019',
        'org'=>$orgA,'actor'=>$actorA,
        'url'=>$url,'digest'=>hash('sha256',$url),
    ]);
}, '42501','ordinary INSERT blocked by FORCE RLS');

$reader = null;
$admin->exec('DROP OWNED BY portal_gsc_f123_reader');
$admin->exec('DROP ROLE portal_gsc_f123_reader');

f123Assert((int)$admin->query(
    'SELECT count(*) FROM public.portal_gsc_property_candidates'
)->fetchColumn() === 3, 'all failed writes left database intact');

echo "GSC-F123 closed multi-tenant property candidate schema: PASS\n";
