<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/identity-f100/PortalF100OfflinePermissionProbe.php';
require_once dirname(__DIR__).'/identity-f101/PortalF101OfflineResourceProbe.php';

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F103_PG_LAB') !== '1'
    || getenv('PORTAL_F101_PG_LAB') !== '1'
    || getenv('PORTAL_F100_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('PORTAL-F103 requires isolated PG18 CI and testing gates.');
}
$adminPass = getenv('F66_PG_ADMIN_PASSWORD');
$f100Pass = getenv('F76_PG_READER_PASSWORD');
$f101Pass = getenv('F101_PG_READER_PASSWORD');
if (!is_string($adminPass) || strlen($adminPass) < 12
    || !is_string($f100Pass) || strlen($f100Pass) < 12
    || !is_string($f101Pass) || strlen($f101Pass) < 12) {
    throw new RuntimeException('PORTAL-F103 requires ephemeral CI-only passwords.');
}
$connect = static fn (string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$db = $connect('postgres',$adminPass);
$probeF76 = new PortalF100OfflinePermissionProbe(
    $connect('portal_f76_reader',$f100Pass)
);
$probeF101 = new PortalF101OfflineResourceProbe(
    $connect('portal_f101_reader',$f101Pass)
);
$n = 0;
$ok = static function (bool $yes, string $label) use (&$n): void {
    if (!$yes) {
        throw new RuntimeException('PORTAL-F103 FAILED '.$label);
    }
    $n++;
};
$isTrue = static fn (mixed $v): bool => in_array($v,[true,1,'1','t'],true);
$isFalse = static fn (mixed $v): bool => in_array($v,[false,0,'0','f'],true);

$columns = [
    'portal_identities' => [
        'allow' => ['id','status','email_verified_at','mfa_confirmed_at','security_revision'],
        'deny' => ['password_hash','mfa_secret_ciphertext','email_normalized','created_at'],
    ],
    'portal_organizations' => [
        'allow' => ['id','status','security_revision'],
        'deny' => ['reference','display_name','created_at'],
    ],
    'portal_memberships' => [
        'allow' => ['id','identity_id','organization_id','status','membership_revision'],
        'deny' => ['role','created_at','updated_at'],
    ],
    'portal_membership_grants' => [
        'allow' => ['membership_id','permission_code'],
        'deny' => ['id','created_at','updated_at'],
    ],
    'portal_auth_sessions' => [
        'allow' => [
            'session_sha256','identity_id','organization_id','status',
            'revoked_at','issued_at','expires_at','mfa_verified_at',
            'identity_revision','membership_revision','organization_revision',
        ],
        'deny' => ['id','created_at','updated_at'],
    ],
    'portal_f101_resource_fixtures' => [
        'allow' => ['id','organization_id','status'],
        'deny' => ['display_ref'],
    ],
];

$privilege = $db->prepare(
    "SELECT has_column_privilege(
       'portal_f102_security_owner', :table, :column, 'SELECT'
     ) AS definer_read,
     has_column_privilege('portal_f101_reader', :table, :column, 'SELECT')
       AS reader_read"
);
$tableWide = $db->prepare(
    "SELECT count(*)
       FROM pg_class c
       JOIN pg_namespace n ON n.oid=c.relnamespace
       CROSS JOIN LATERAL aclexplode(
         COALESCE(c.relacl,acldefault('r',c.relowner))
       ) AS acl
      WHERE n.nspname='public' AND c.relname=:table
        AND acl.grantee='portal_f102_security_owner'::regrole
        AND acl.privilege_type='SELECT'"
);
foreach ($columns as $table=>$rules) {
    $tableWide->execute(['table'=>$table]);
    $ok((int)$tableWide->fetchColumn() === 0,
        'no table-wide SELECT privilege: '.$table);
    foreach ($rules['allow'] as $column) {
        $privilege->execute(['table'=>'public.'.$table,'column'=>$column]);
        $grants = $privilege->fetch();
        $ok(is_array($grants)
            && $isTrue($grants['definer_read'])
            && $isFalse($grants['reader_read']),
            'definer has only required column and caller cannot SELECT: '.$table.'.'.$column);
    }
    foreach ($rules['deny'] as $column) {
        $privilege->execute(['table'=>'public.'.$table,'column'=>$column]);
        $grants = $privilege->fetch();
        $ok(is_array($grants)
            && $isFalse($grants['definer_read'])
            && $isFalse($grants['reader_read']),
            'forbidden column denied even to privileged definer: '.$table.'.'.$column);
    }
}

// Real SQL permission test: simulate execution as restricted NOLOGIN owner
// through a superuser-only CI role switch. Secrets are never selected.
foreach (['password_hash','mfa_secret_ciphertext'] as $secret) {
    $temporary = $connect('postgres',$adminPass);
    $temporary->beginTransaction();
    try {
        $temporary->exec('SET LOCAL ROLE portal_f102_security_owner');
        $temporary->query('SELECT '.$secret.' FROM public.portal_identities LIMIT 1');
        $ok(false,'privileged owner must not read '.$secret);
    } catch (PDOException $exception) {
        $ok($exception->getCode()==='42501','SQL denies '.$secret.' to function owner');
    } finally {
        if ($temporary->inTransaction()) $temporary->rollBack();
    }
}

// Independent, synthetic session / resource proves the F76 and F101 SQL
// predicates continue to work with their MINIMAL column SELECT lists.
$identity='e1111111-1111-4111-8111-111111111111';
$org='e2222222-2222-4222-8222-222222222222';
$other='e3333333-3333-4333-8333-333333333333';
$member='e4444444-4444-4444-8444-444444444444';
$grant='e5555555-5555-4555-8555-555555555555';
$session='e6666666-6666-4666-8666-666666666666';
$resource='e7777777-7777-4777-8777-777777777777';
$bearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');

$db->prepare(
    "INSERT INTO portal_identities
     (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
      security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',1,now(),now())"
)->execute([$identity,'f103-demo@example.invalid']);
$insertOrg=$db->prepare(
    "INSERT INTO portal_organizations
     (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$insertOrg->execute([$org,'f103-primary','Synthetic only primary']);
$insertOrg->execute([$other,'f103-foreign','Synthetic only foreign']);
$db->prepare(
    "INSERT INTO portal_memberships
     (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$member,$identity,$org]);
$db->prepare(
    "INSERT INTO portal_membership_grants
     (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute([$grant,$member]);
$db->prepare(
    "INSERT INTO portal_auth_sessions
     (id,session_sha256,identity_id,organization_id,status,
      identity_revision,membership_revision,organization_revision,
      mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '2 minutes',
      now()-interval '3 minutes',now()+interval '20 minutes',now(),now())"
)->execute([$session,hash('sha256',$bearer),$identity,$org]);
$db->prepare(
    "INSERT INTO portal_f101_resource_fixtures(id,organization_id,status,display_ref)
     VALUES(?,?,'active','f103-only')"
)->execute([$resource,$org]);

$checkResource=static fn():bool=>$probeF101->check($bearer,$org,$resource,'services.view');
$ok($probeF76->check($bearer,$org,'services.view'),
    'nested F76 predicate works with column-only SELECT grants');
$ok($checkResource(),'F101 resource owner proof works with column-only SELECT grants');
$ok(!$probeF101->check($bearer,$other,$resource,'services.view'),
    'foreign organization still denied with minimal columns');

// Removing ONE necessary column must break F101 without breaking F76.
$db->exec(
    "REVOKE SELECT (status) ON TABLE public.portal_f101_resource_fixtures
     FROM portal_f102_security_owner"
);
$ok(!$checkResource(),'removing fixture status SELECT fails F101 closed');
$ok($probeF76->check($bearer,$org,'services.view'),
    'restricting resource columns never compromises F76 independence');
$db->exec(
    "GRANT SELECT (status) ON TABLE public.portal_f101_resource_fixtures
     TO portal_f102_security_owner"
);
$ok($checkResource(),'explicit restoration of required column restores F101 lab');

$db->exec("UPDATE portal_auth_sessions
           SET status='revoked',revoked_at=now() WHERE id='$session'");
$ok(!$checkResource(),'session revocation remains effective under column grants');
$db->exec("UPDATE portal_auth_sessions
           SET status='active',revoked_at=NULL WHERE id='$session'");
$db->exec("UPDATE portal_f101_resource_fixtures
           SET organization_id='$other' WHERE id='$resource'");
$ok(!$checkResource(),'resource ownership change remains effective');
$ok((int)$db->query(
    "SELECT count(*) FROM pg_policies
      WHERE schemaname='public' AND tablename LIKE 'portal_%'"
)->fetchColumn()===0,'minimal grants do not create permissive RLS policy');

echo "PASS PORTAL-F103 $n column-specific definer grants, secret denial and revocation assertions\n";
