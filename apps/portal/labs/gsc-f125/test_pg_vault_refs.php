<?php

declare(strict_types=1);

function f125Assert(bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('GSC-F125: '.$label);
    }
}

function f125Fails(callable $action, string $state, string $label): void {
    try {
        $action();
    } catch (PDOException $error) {
        f125Assert($error->getCode() === $state, $label.' SQLSTATE '.$error->getCode());
        return;
    }
    throw new RuntimeException('GSC-F125 unexpectedly allowed '.$label);
}

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F125_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('GSC-F125 isolated PostgreSQL CI only');
}
$password = getenv('F125_PG_ADMIN_PASSWORD');
f125Assert(is_string($password) && strlen($password) > 12, 'test credential present');

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab';
$admin = new PDO($dsn, 'postgres', $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$orgA = '123e4567-e89b-42d3-a456-426614176001';
$orgB = '123e4567-e89b-42d3-a456-426614176002';
$actorA = '123e4567-e89b-42d3-a456-426614176003';
$actorB = '123e4567-e89b-42d3-a456-426614176004';
$memberA = '123e4567-e89b-42d3-a456-426614176005';
$memberB = '123e4567-e89b-42d3-a456-426614176006';
$propertyA = '123e4567-e89b-42d3-a456-426614176007';
$propertyB = '123e4567-e89b-42d3-a456-426614176008';
$propertyA2 = '123e4567-e89b-42d3-a456-426614176009';
$ref = hash('sha256','synthetic-vault-locator-ci-only');
$scope = 'https://www.googleapis.com/auth/webmasters.readonly';

foreach ([[$actorA,'gsc-f125-a@example.invalid'],[$actorB,'gsc-f125-b@example.invalid']] as [$id,$mail]) {
    $stmt = $admin->prepare(
        "INSERT INTO portal_identities(id,email_normalized,status,created_at,updated_at) "
        ."VALUES(:id,:email,'active',now(),now())"
    );
    $stmt->execute(['id'=>$id,'email'=>$mail]);
}
$orgInsert = $admin->prepare(
    "INSERT INTO portal_organizations(id,reference,display_name,status,created_at,updated_at) "
    ."VALUES(:id,:ref,:name,'active',now(),now())"
);
$orgInsert->execute(['id'=>$orgA,'ref'=>'gsc-f125-a','name'=>'Synthetic F125 A']);
$orgInsert->execute(['id'=>$orgB,'ref'=>'gsc-f125-b','name'=>'Synthetic F125 B']);

$memberInsert = $admin->prepare(
    "INSERT INTO portal_memberships(id,identity_id,organization_id,role,status,created_at,updated_at) "
    ."VALUES(:id,:person,:org,'owner','active',now(),now())"
);
$memberInsert->execute(['id'=>$memberA,'person'=>$actorA,'org'=>$orgA]);
$memberInsert->execute(['id'=>$memberB,'person'=>$actorB,'org'=>$orgB]);

$propertyInsert = $admin->prepare(
    "INSERT INTO portal_gsc_property_candidates "
    ."(id,organization_id,created_by_identity_id,site_url,site_fingerprint_sha256,"
    ."site_type,status,created_at,updated_at) "
    ."VALUES(:id,:org,:actor,:site,:digest,:type,'candidate',now(),now())"
);
foreach ([
    [$propertyA,$orgA,$actorA,'sc-domain:example.com','domain'],
    [$propertyA2,$orgA,$actorA,'https://www.example.com/','url_prefix'],
    [$propertyB,$orgB,$actorB,'sc-domain:example.com','domain'],
] as [$id,$org,$actor,$site,$type]) {
    $propertyInsert->execute([
        'id'=>$id,'org'=>$org,'actor'=>$actor,'site'=>$site,
        'digest'=>hash('sha256',$site),'type'=>$type,
    ]);
}

$cols = $admin->query(
    "SELECT column_name FROM information_schema.columns "
    ."WHERE table_schema='public' "
    ."AND table_name='portal_gsc_vault_reference_candidates'"
)->fetchAll(PDO::FETCH_COLUMN);
foreach (['access_token','refresh_token','client_secret','token_ciphertext',
    'vault_uri','vault_reference','api_key'] as $sensitive) {
    f125Assert(!in_array($sensitive,$cols,true),'never persist secret column '.$sensitive);
}
$policies=(int)$admin->query(
    "SELECT count(*) FROM pg_policies WHERE schemaname='public' "
    ."AND tablename='portal_gsc_vault_reference_candidates'"
)->fetchColumn();
f125Assert($policies===0,'no application RLS policy');
$rls=$admin->query(
    "SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE "
    ."oid='public.portal_gsc_vault_reference_candidates'::regclass"
)->fetch(PDO::FETCH_NUM);
f125Assert($rls!==false
    && in_array($rls[0],[true,'t','1',1],true)
    && in_array($rls[1],[true,'t','1',1],true),'RLS enabled and forced');
f125Assert((int)$admin->query(
    'SELECT count(*) FROM portal_gsc_vault_reference_candidates'
)->fetchColumn()===0,'no vault reference created automatically');

$insert = $admin->prepare(
    "INSERT INTO portal_gsc_vault_reference_candidates "
    ."(id,organization_id,candidate_id,vault_reference_sha256,key_version,"
    ."scope,status,created_at,updated_at) "
    ."VALUES(:id,:org,:candidate,:ref,:key_version,:scope,:status,now(),now())"
);
$make = static function (
    string $id,string $org,string $candidate,string $refHash,
    string $key='gsc-ci-v1',string $currentScope=$scope,string $status='pending_review'
) use ($insert): void {
    $insert->execute([
        'id'=>$id,'org'=>$org,'candidate'=>$candidate,'ref'=>$refHash,
        'key_version'=>$key,'scope'=>$currentScope,'status'=>$status,
    ]);
};

$make('123e4567-e89b-42d3-a456-426614176010',$orgA,$propertyA,$ref);
$make('123e4567-e89b-42d3-a456-426614176011',$orgB,$propertyB,$ref);
f125Assert((int)$admin->query(
    "SELECT count(*) FROM portal_gsc_vault_reference_candidates "
    ."WHERE vault_reference_sha256='$ref'"
)->fetchColumn()===2,'digest may be a candidate for distinct tenants without granting access');

f125Fails(
    fn()=> $make('123e4567-e89b-42d3-a456-426614176012',$orgB,$propertyA,hash('sha256','other-ref')),
    '23503','cross-tenant vault to property foreign key',
);
f125Fails(
    fn()=> $make('123e4567-e89b-42d3-a456-426614176013',$orgA,$propertyA,hash('sha256','another-ref')),
    '23505','duplicate property binding in same tenant',
);
f125Fails(
    fn()=> $make('123e4567-e89b-42d3-a456-426614176014',$orgA,$propertyA2,$ref),
    '23505','same locator digest twice within tenant',
);
f125Fails(
    fn()=> $make('123e4567-e89b-42d3-a456-426614176015',$orgA,$propertyA2,'bad-hash'),
    '23514','noncanonical vault locator digest',
);
f125Fails(
    fn()=> $make('123e4567-e89b-42d3-a456-426614176016',$orgA,$propertyA2,
        hash('sha256','foo'),'../../../secret'),
    '23514','malformed key version',
);
f125Fails(
    fn()=> $make('123e4567-e89b-42d3-a456-426614176017',$orgA,$propertyA2,
        hash('sha256','bar'),'gsc-ci-v1','https://www.googleapis.com/auth/webmasters'),
    '23514','wider Google scope rejected',
);
f125Fails(
    fn()=> $make('123e4567-e89b-42d3-a456-426614176018',$orgA,$propertyA2,
        hash('sha256','baz'),'gsc-ci-v1',$scope,'active'),
    '23514','no authorized active credential status exists',
);

$admin->exec(
    "CREATE ROLE portal_gsc_f125_reader LOGIN PASSWORD 'ci-f125-reader-only' "
    ."NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS"
);
$admin->exec('GRANT CONNECT ON DATABASE portal_identity_lab TO portal_gsc_f125_reader');
$admin->exec('GRANT USAGE ON SCHEMA public TO portal_gsc_f125_reader');
$admin->exec(
    'GRANT SELECT, INSERT ON public.portal_gsc_vault_reference_candidates TO portal_gsc_f125_reader'
);
$reader = new PDO($dsn,'portal_gsc_f125_reader','ci-f125-reader-only',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
]);
f125Assert((int)$reader->query(
    'SELECT count(*) FROM public.portal_gsc_vault_reference_candidates'
)->fetchColumn()===0,'SQL SELECT cannot bypass no-policy RLS');
f125Fails(
    static function () use ($reader,$orgA,$propertyA2,$scope): void {
        $stmt=$reader->prepare(
            "INSERT INTO portal_gsc_vault_reference_candidates "
            ."(id,organization_id,candidate_id,vault_reference_sha256,key_version,"
            ."scope,status,created_at,updated_at) "
            ."VALUES(:id,:org,:candidate,:ref,'gsc-ci-v1',:scope,'pending_review',now(),now())"
        );
        $stmt->execute([
            'id'=>'123e4567-e89b-42d3-a456-426614176019',
            'org'=>$orgA,'candidate'=>$propertyA2,'ref'=>hash('sha256','nope'),
            'scope'=>$scope,
        ]);
    },'42501','SQL INSERT cannot bypass no-policy RLS',
);
$reader=null;
$admin->exec('DROP OWNED BY portal_gsc_f125_reader');
$admin->exec('DROP ROLE portal_gsc_f125_reader');

f125Assert((int)$admin->query(
    'SELECT count(*) FROM portal_gsc_vault_reference_candidates'
)->fetchColumn()===2,'bad writes left the registry intact');

echo "GSC-F125 tenant-safe vault reference digests and closed RLS: PASS\n";
