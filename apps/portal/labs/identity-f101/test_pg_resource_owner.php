<?php

declare(strict_types=1);

require_once __DIR__.'/PortalF101OfflineResourceProbe.php';

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F101_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F101 requires disposable local PostgreSQL 18.');
}
$adminPass = getenv('F66_PG_ADMIN_PASSWORD');
$readerPass = getenv('F101_PG_READER_PASSWORD');
if (!is_string($adminPass) || strlen($adminPass) < 12
    || !is_string($readerPass) || strlen($readerPass) < 12) {
    throw new RuntimeException('F101 requires ephemeral runner credentials.');
}
$connect = static fn(string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$admin = $connect('postgres', $adminPass);
$reader = $connect('portal_f101_reader', $readerPass);
$probe = new PortalF101OfflineResourceProbe($reader);
$privilegedProbe = new PortalF101OfflineResourceProbe($admin);
$n = 0;
$check = static function (bool $value, string $label) use (&$n): void {
    if (!$value) {
        throw new RuntimeException('PORTAL-F101 FAIL '.$label);
    }
    $n++;
};
$denySql = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(false, $label.' unexpectedly allowed');
    } catch (PDOException $error) {
        $check($error->getCode() === '42501', $label.' requires permission denied');
    }
};

$identity = 'b1111111-1111-4111-8111-111111111111';
$org = 'b2222222-2222-4222-8222-222222222222';
$foreign = 'b3333333-3333-4333-8333-333333333333';
$member = 'b4444444-4444-4444-8444-444444444444';
$grant = 'b5555555-5555-4555-8555-555555555555';
$session = 'b6666666-6666-4666-8666-666666666666';
$resource = 'b7777777-7777-4777-8777-777777777777';
$resourceOther = 'b8888888-8888-4888-8888-888888888888';
$bearer = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$check(!PortalF101OfflineResourceProbe::authorizesOperationalHttp(),
    'offline fixture cannot authorize live access');
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'no session or resource yet');

$admin->prepare(
    "INSERT INTO portal_identities(id,email_normalized,status,email_verified_at,
      mfa_confirmed_at,security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',1,now(),now())"
)->execute([$identity, 'f101@example.invalid']);
$orgInsert = $admin->prepare(
    "INSERT INTO portal_organizations(id,reference,display_name,status,
      security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$orgInsert->execute([$org, 'f101-primary', 'F101 Synthetic Primary']);
$orgInsert->execute([$foreign, 'f101-foreign', 'F101 Synthetic Foreign']);
$admin->prepare(
    "INSERT INTO portal_memberships(id,identity_id,organization_id,role,status,
      membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$member, $identity, $org]);
$admin->prepare(
    "INSERT INTO portal_membership_grants(id,membership_id,permission_code,
      created_at,updated_at) VALUES(?,?,'services.view',now(),now())"
)->execute([$grant, $member]);
$admin->prepare(
    "INSERT INTO portal_auth_sessions(id,session_sha256,identity_id,organization_id,
      status,identity_revision,membership_revision,organization_revision,
      mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '2 minutes',
       now()-interval '3 minutes',now()+interval '20 minutes',now(),now())"
)->execute([$session, hash('sha256', $bearer), $identity, $org]);
$createResource = $admin->prepare(
    "INSERT INTO portal_f101_resource_fixtures(id,organization_id,status,display_ref)
     VALUES(?,?,'active',?)"
);
$createResource->execute([$resource, $org, 'synthetic-service-a']);
$createResource->execute([$resourceOther, $foreign, 'synthetic-service-b']);

$check($probe->check($bearer, $org, $resource, 'services.view'),
    'MFA session + matching business + explicit grant + owned resource passes in lab');
$check(!$probe->check($bearer, $org, $resourceOther, 'services.view'),
    'resource owned by another business denied');
$check(!$probe->check($bearer, $foreign, $resourceOther, 'services.view'),
    'valid foreign resource does not confer foreign organization session');
$check(!$probe->check($bearer, $foreign, $resource, 'services.view'),
    'selected organization must match both resource owner and session');
$check(!$probe->check($bearer, $org, 'b9999999-9999-4999-8999-999999999999', 'services.view'),
    'unknown resource fails closed');
$check(!$probe->check($bearer, $org, $resource, 'services.manage'),
    'missing explicit manage grant denied');
$check(!$probe->check($bearer, $org, $resource, 'billing.view'),
    'service-resource gate cannot be used for billing');
$check(!$probe->check($bearer, $org, $resource, '*'),
    'wildcard permission cannot authorize resource');
$check(!$probe->check('CRM-cookie-not-a-portal-session', $org, $resource, 'services.view'),
    'independent CRM session rejected');
$check(!$probe->check($bearer, $org, $resource."' OR TRUE--", 'services.view'),
    'SQL shaped resource reference rejected');
$check(!$probe->check(str_repeat('a', 43), $org, $resource, 'services.view'),
    'noncanonical synthetic bearer denied');
$check(!$privilegedProbe->check($bearer, $org, $resource, 'services.view'),
    'cannot accidentally use superuser connection');

$denySql(static fn()=> $reader->query('SELECT * FROM public.portal_f101_resource_fixtures'),
    'resource listing denied to EXECUTE-only role');
$denySql(static fn()=> $reader->query('SELECT * FROM public.portal_auth_sessions'),
    'session listing denied to EXECUTE-only role');
$denySql(static fn()=> $reader->query(
    "SELECT public.portal_f76_permission_check('".str_repeat('0', 64)."', "
    ."'$org'::uuid,'services.view')"
), 'F101 reader cannot call lower-level F76 predicate directly');

$admin->exec("UPDATE portal_f101_resource_fixtures SET status='archived' WHERE id='$resource'");
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'archived resource denied without revoking session');
$admin->exec("UPDATE portal_f101_resource_fixtures SET status='active',
  organization_id='$foreign' WHERE id='$resource'");
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'ownership transfer revokes resource immediately');
$admin->exec("UPDATE portal_f101_resource_fixtures SET organization_id='$org' WHERE id='$resource'");
$check($probe->check($bearer, $org, $resource, 'services.view'),
    'synthetic ownership restored in fixture');

$admin->exec("UPDATE portal_memberships SET membership_revision=2 WHERE id='$member'");
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'membership revision change revokes resource');
$admin->exec("UPDATE portal_memberships SET membership_revision=1 WHERE id='$member'");
$admin->exec("UPDATE portal_organizations SET security_revision=2 WHERE id='$org'");
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'organization revision change revokes resource');
$admin->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$org'");
$admin->exec("UPDATE portal_auth_sessions SET status='revoked',revoked_at=now() WHERE id='$session'");
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'revoked session cannot authorize owned resource');
$admin->exec("UPDATE portal_auth_sessions SET status='active',
  revoked_at=NULL, expires_at=now()-interval '1 minute' WHERE id='$session'");
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'expired session cannot authorize owned resource');
$admin->exec("UPDATE portal_auth_sessions SET expires_at=now()+interval '20 minutes' WHERE id='$session'");
$admin->exec("DELETE FROM portal_membership_grants WHERE id='$grant'");
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'explicit permission removal revokes resource');
$check((int)$admin->query(
    "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND tablename LIKE 'portal_%'"
)->fetchColumn() === 0, 'no application RLS policies created');

putenv('PORTAL_F101_PG_LAB=0');
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'disabling lab gate denies');
putenv('PORTAL_F101_PG_LAB=1');
putenv('APP_ENV=production');
$check(!$probe->check($bearer, $org, $resource, 'services.view'),
    'production environment denies');

echo "PASS PORTAL-F101 $n synthetic least-privilege resource owner tests\n";
