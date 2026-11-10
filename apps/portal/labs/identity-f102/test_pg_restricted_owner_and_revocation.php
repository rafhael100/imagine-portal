<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/identity-f101/PortalF101OfflineResourceProbe.php';

// No production identities, no real database, no HTTP: strictly disposable CI.
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F102_PG_LAB') !== '1'
    || getenv('PORTAL_F101_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F102 is for ephemeral local PostgreSQL 18 CI only.');
}
$adminPassword = getenv('F66_PG_ADMIN_PASSWORD');
$readerPassword = getenv('F101_PG_READER_PASSWORD');
if (!is_string($adminPassword) || strlen($adminPassword) < 12
    || !is_string($readerPassword) || strlen($readerPassword) < 12) {
    throw new RuntimeException('F102 requires only ephemeral CI credentials.');
}
$connect = static fn (string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$writer = $connect('postgres', $adminPassword);
$reader = $connect('portal_f101_reader', $readerPassword);
$probe = new PortalF101OfflineResourceProbe($reader);
$n = 0;
$ok = static function (bool $valid, string $label) use (&$n): void {
    if (!$valid) {
        throw new RuntimeException('PORTAL-F102 failed: '.$label);
    }
    ++$n;
};

$role = $writer->query(
    "SELECT rolsuper, rolcanlogin, rolbypassrls, rolcreaterole,
            rolcreatedb, rolinherit
       FROM pg_roles WHERE rolname='portal_f102_security_owner'"
)->fetch();
$ok(is_array($role)
    && $role['rolsuper'] === false
    && $role['rolcanlogin'] === false
    && $role['rolbypassrls'] === true
    && $role['rolcreaterole'] === false
    && $role['rolcreatedb'] === false
    && $role['rolinherit'] === false,
    'definer must be non-superuser NOLOGIN with explicit BYPASSRLS');

$owners = $writer->query(
    "SELECT p.proname, pg_get_userbyid(p.proowner) AS owner,
            p.prosecdef, p.proconfig
       FROM pg_proc p
      WHERE p.oid IN (
          'public.portal_f76_permission_check(text,uuid,text)'::regprocedure,
          'public.portal_f101_resource_check(text,uuid,uuid,text)'::regprocedure
      ) ORDER BY p.proname"
)->fetchAll();
$ok(count($owners) === 2, 'both SQL gates present');
foreach ($owners as $fn) {
    $ok($fn['owner'] === 'portal_f102_security_owner'
        && $fn['prosecdef'] === true
        && str_contains((string)$fn['proconfig'], 'search_path=pg_catalog, pg_temp'),
        'restricted function owner and fixed search_path');
}
$schemaCreate = $writer->query(
    "SELECT has_schema_privilege('portal_f102_security_owner','public','CREATE')"
)->fetchColumn();
$ok($schemaCreate === false, 'no permanent CREATE in public for definer');
foreach ([
    'portal_identities', 'portal_organizations', 'portal_memberships',
    'portal_membership_grants', 'portal_auth_sessions',
    'portal_f101_resource_fixtures'
] as $table) {
    $sql = $writer->prepare(
        "SELECT has_table_privilege('portal_f102_security_owner', :tbl, 'SELECT'),
                has_table_privilege('portal_f102_security_owner', :tbl, 'INSERT'),
                has_table_privilege('portal_f101_reader', :tbl, 'SELECT')"
    );
    $sql->execute(['tbl' => 'public.'.$table]);
    $privileges = $sql->fetch(PDO::FETCH_NUM);
    $ok($privileges === [true, false, false], 'owner SELECT only; reader no row access: '.$table);
}

$acl = $writer->query(
    "SELECT
      has_function_privilege(
        'portal_f101_reader',
        'public.portal_f101_resource_check(text,uuid,uuid,text)'::regprocedure, 'EXECUTE'
      ) AS resource_execute,
      has_function_privilege(
        'portal_f101_reader',
        'public.portal_f76_permission_check(text,uuid,text)'::regprocedure, 'EXECUTE'
      ) AS lowlevel_execute,
      has_function_privilege(
        'portal_f76_reader',
        'public.portal_f76_permission_check(text,uuid,text)'::regprocedure, 'EXECUTE'
      ) AS f76_execute"
)->fetch();
$ok($acl === [
    'resource_execute' => true,
    'lowlevel_execute' => false,
    'f76_execute' => true,
], 'readers keep exactly the required function access');

$rls = $writer->query(
    "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
      WHERE n.nspname='public'
        AND c.relname IN (
           'portal_identities','portal_organizations','portal_memberships',
           'portal_membership_grants','portal_auth_sessions','portal_f101_resource_fixtures'
        ) AND c.relrowsecurity AND c.relforcerowsecurity"
)->fetchColumn();
$ok((int)$rls === 6, 'all identity and fixture tables keep FORCE RLS');
$ok((int)$writer->query(
    "SELECT count(*) FROM pg_policies
      WHERE schemaname='public' AND tablename LIKE 'portal_%'"
)->fetchColumn() === 0, 'no permissive RLS policies introduced');

// Independent synthetic fixtures for two separate, concurrent SQL connections.
$identity = 'c1111111-1111-4111-8111-111111111111';
$organization = 'c2222222-2222-4222-8222-222222222222';
$foreign = 'c3333333-3333-4333-8333-333333333333';
$membership = 'c4444444-4444-4444-8444-444444444444';
$grant = 'c5555555-5555-4555-8555-555555555555';
$session = 'c6666666-6666-4666-8666-666666666666';
$resource = 'c7777777-7777-4777-8777-777777777777';
$bearer = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$writer->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
       security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',1,now(),now())"
)->execute([$identity, 'f102-fixture@example.invalid']);
$orgInsert = $writer->prepare(
    "INSERT INTO portal_organizations
      (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$orgInsert->execute([$organization,'f102-home','Synthetic home organization']);
$orgInsert->execute([$foreign,'f102-foreign','Synthetic foreign organization']);
$writer->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$membership,$identity,$organization]);
$writer->prepare(
    "INSERT INTO portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute([$grant,$membership]);
$writer->prepare(
    "INSERT INTO portal_auth_sessions
      (id,session_sha256,identity_id,organization_id,status,
       identity_revision,membership_revision,organization_revision,
       mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '2 minutes',
       now()-interval '3 minutes',now()+interval '20 minutes',now(),now())"
)->execute([$session,hash('sha256',$bearer),$identity,$organization]);
$writer->prepare(
    "INSERT INTO portal_f101_resource_fixtures(id,organization_id,status,display_ref)
     VALUES(?,?,'active','f102-fixture')"
)->execute([$resource]);

$allow = static fn (): bool => $probe->check(
    $bearer,$organization,$resource,'services.view'
);
$ok($allow(), 'restricted non-superuser definer still allows valid synthetic session');
$ok(!$probe->check($bearer,$foreign,$resource,'services.view'),
    'cross-tenant remains denied under restricted owner');

// READ COMMITTED checks from a second DB connection: uncommitted revocation
// must not be mistaken for committed revocation. Once committed, every new
// statement must see denial. No linearizability is claimed for in-flight
// requests before the commit, and production would need a stronger fence.
$writer->beginTransaction();
$writer->exec("UPDATE portal_f101_resource_fixtures
               SET status='archived' WHERE id='$resource'");
$ok($allow(), 'before owner transaction commit, old committed resource state visible');
$writer->commit();
$ok(!$allow(), 'first check after committed resource archival denied');
$writer->exec("UPDATE portal_f101_resource_fixtures SET status='active' WHERE id='$resource'");
$ok($allow(), 'controlled fixture reset permits subsequent check');

$writer->beginTransaction();
$writer->exec("UPDATE portal_f101_resource_fixtures
               SET organization_id='$foreign' WHERE id='$resource'");
$ok($allow(), 'uncommitted owner transfer does not become visible early');
$writer->commit();
$ok(!$allow(), 'first check after committed ownership transfer denied');
$writer->exec("UPDATE portal_f101_resource_fixtures
               SET organization_id='$organization' WHERE id='$resource'");

$writer->beginTransaction();
$writer->exec("UPDATE portal_memberships
               SET membership_revision=2 WHERE id='$membership'");
$ok($allow(), 'uncommitted membership revision remains invisible');
$writer->commit();
$ok(!$allow(), 'committed membership revision immediately invalidates session');
$writer->exec("UPDATE portal_memberships SET membership_revision=1 WHERE id='$membership'");

$writer->beginTransaction();
$writer->exec("UPDATE portal_auth_sessions
               SET status='revoked',revoked_at=now() WHERE id='$session'");
$ok($allow(), 'uncommitted session revocation not yet visible');
$writer->commit();
$ok(!$allow(), 'committed session revocation immediately denied');

// A negative privilege test: if the restricted definer loses a required
// table grant, it must NOT silently fall back to a more privileged identity.
$writer->exec("REVOKE SELECT ON public.portal_f101_resource_fixtures
               FROM portal_f102_security_owner");
$ok(!$allow(), 'lack of owner SELECT fails closed rather than impersonating postgres');
$writer->exec("GRANT SELECT ON public.portal_f101_resource_fixtures
               TO portal_f102_security_owner");

putenv('PORTAL_F101_PG_LAB=0');
$ok(!$allow(), 'offline PHP client disabled by missing F101 flag');
putenv('PORTAL_F101_PG_LAB=1');
putenv('APP_ENV=production');
$ok(!$allow(), 'offline PHP client disabled in production');

echo "PASS PORTAL-F102 {$n} restricted-function-owner and committed-revocation checks; laboratory only\n";
