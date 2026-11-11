<?php

declare(strict_types=1);

/**
 * GSC-F113: disposable PostgreSQL schema grant smoke.
 * No Portal sessions or live Google properties are contacted.
 */
function f113Assert(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException('GSC-F113: '.$message); }
}
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F113_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('GSC-F113 disposable PG18 CI only.');
}
$password=getenv('F113_PG_ADMIN_PASSWORD');
f113Assert(is_string($password) && strlen($password)>12, 'test-only admin password');
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab';
$db=new PDO($dsn,'postgres',$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$constraint=$db->query(
    "SELECT pg_get_constraintdef(oid) FROM pg_constraint
      WHERE conname='portal_grant_permission_ck'
      AND conrelid='public.portal_membership_grants'::regclass"
)->fetchColumn();
f113Assert(is_string($constraint) && str_contains($constraint,'seo.gsc.connect'),
    'migration introduces only the explicit SEO permission code');

$rls=$db->query(
    "SELECT relrowsecurity,relforcerowsecurity
     FROM pg_class WHERE oid='public.portal_membership_grants'::regclass"
)->fetch(PDO::FETCH_ASSOC);
f113Assert($rls === ['relrowsecurity'=>true,'relforcerowsecurity'=>true]
    || $rls === ['relrowsecurity'=>'t','relforcerowsecurity'=>'t'],
    'FORCE RLS stays enabled');
$policies=$db->query(
    "SELECT count(*) FROM pg_policies
     WHERE schemaname='public'
       AND tablename IN ('portal_membership_grants','portal_auth_sessions')"
)->fetchColumn();
f113Assert((int)$policies===0, 'migration does not open a SELECT or UPDATE RLS policy');

$identity='123e4567-e89b-42d3-a456-426614174010';
$organization='123e4567-e89b-42d3-a456-426614174011';
$membership='123e4567-e89b-42d3-a456-426614174012';
$grantId='123e4567-e89b-42d3-a456-426614174013';

$db->prepare(
    "INSERT INTO public.portal_identities
      (id,email_normalized,status,created_at,updated_at)
     VALUES(:id,'gsc-f113@example.invalid','invited',now(),now())"
)->execute(['id'=>$identity]);
$db->prepare(
    "INSERT INTO public.portal_organizations
      (id,reference,display_name,status,created_at,updated_at)
     VALUES(:id,'gsc-f113-test','F113 isolated test','active',now(),now())"
)->execute(['id'=>$organization]);
$db->prepare(
    "INSERT INTO public.portal_memberships
      (id,identity_id,organization_id,role,status,created_at,updated_at)
     VALUES(:id,:identity,:organization,'owner','active',now(),now())"
)->execute(['id'=>$membership,'identity'=>$identity,'organization'=>$organization]);

$noAutoGrant=$db->query(
    "SELECT count(*) FROM public.portal_membership_grants
      WHERE permission_code='seo.gsc.connect'"
)->fetchColumn();
f113Assert((int)$noAutoGrant===0,
    'even owner role does not receive SEO scope automatically');

$db->prepare(
    "INSERT INTO public.portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(:id,:membership,'seo.gsc.connect',now(),now())"
)->execute(['id'=>$grantId,'membership'=>$membership]);

try {
    $db->prepare(
        "INSERT INTO public.portal_membership_grants
         (id,membership_id,permission_code,created_at,updated_at)
         VALUES(:id,:membership,'seo.gsc.manage',now(),now())"
    )->execute([
        'id'=>'123e4567-e89b-42d3-a456-426614174014',
        'membership'=>$membership,
    ]);
    throw new RuntimeException('unexpected broad SEO permission accepted');
} catch (PDOException $e) {
    f113Assert($e->getCode()==='23514','unknown SEO grant rejected by constraint');
}

// Even with SELECT table GRANT, the ordinary role must see no rows due to RLS.
$db->exec(
    "CREATE ROLE portal_gsc_f113_reader LOGIN PASSWORD 'ci-f113-reader-only'
     NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS"
);
$db->exec('GRANT CONNECT ON DATABASE portal_identity_lab TO portal_gsc_f113_reader');
$db->exec('GRANT USAGE ON SCHEMA public TO portal_gsc_f113_reader');
$db->exec('GRANT SELECT ON public.portal_membership_grants TO portal_gsc_f113_reader');

$reader=new PDO($dsn,'portal_gsc_f113_reader','ci-f113-reader-only',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);
$visible=$reader->query(
    "SELECT count(*) FROM public.portal_membership_grants"
)->fetchColumn();
f113Assert((int)$visible===0,'ordinary role cannot read grants despite SQL SELECT');
$reader=null;
$db->exec('DROP OWNED BY portal_gsc_f113_reader');
$db->exec('DROP ROLE portal_gsc_f113_reader');

echo "GSC-F113 additive grant migration and deny-all RLS: PASS\n";
