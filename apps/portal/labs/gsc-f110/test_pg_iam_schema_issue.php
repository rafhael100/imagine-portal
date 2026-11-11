<?php

declare(strict_types=1);

require_once __DIR__.'/PortalGscF110OfflineIamIssuer.php';
require_once __DIR__.'/../gsc-f108/PortalGscF108OfflineSealedState.php';

function f110Assert(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('GSC-F110: '.$label);
    }
}
function f110Token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

foreach ([
    'APP_ENV' => 'testing',
    'PORTAL_F107_GSC_LAB' => '1',
    'PORTAL_F108_PG_LAB' => '1',
    'PORTAL_F110_PG_LAB' => '1',
    'PGHOST' => '127.0.0.1',
    'PGPORT' => '5432',
    'PGDATABASE' => 'portal_identity_lab',
] as $key => $value) {
    putenv($key.'='.$value);
}
putenv('PORTAL_F107_KEY_B64='.f110Token());

$connect = static fn(string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab',
    $role, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES => false]
);
$clientPassword = getenv('F106_PG_CLIENT_PASSWORD');
$adminPassword = getenv('F106_PG_ADMIN_PASSWORD');
f110Assert(is_string($clientPassword) && is_string($adminPassword), 'lab role credentials');
$admin = $connect('postgres', $adminPassword);
$client = $connect('portal_gsc_f106_client', $clientPassword);
$issuer = new PortalGscF110OfflineIamIssuer($client);
$consumer = new PortalGscF108OfflineSealedState($client);

$actor = '123e4567-e89b-42d3-a456-426614174000';
$otherActor = '123e4567-e89b-42d3-a456-426614174099';
$org = '123e4567-e89b-42d3-a456-426614174001';
$otherOrg = '123e4567-e89b-42d3-a456-426614174002';
$member = '123e4567-e89b-42d3-a456-426614174003';
$serviceGrant = '123e4567-e89b-42d3-a456-426614174004';
$seoGrant = '123e4567-e89b-42d3-a456-426614174005';
$sessionId = '123e4567-e89b-42d3-a456-426614174006';
$property = hash('sha256', 'sc-domain:imagine.example');
$otherProperty = hash('sha256', 'sc-domain:elsewhere.example');
$session = f110Token();
$verifier = f110Token();
$now = time();

// Populate the ACTUAL migration tables with invented identities only.
// No fixture issuer authenticates a real person.
$admin->prepare(
    "INSERT INTO portal_identities
     (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
      security_revision,created_at,updated_at)
     VALUES (?,?,'active',now()-interval '2 days',now()-interval '2 days',1,now(),now())"
)->execute([$actor,'gsc-f110@example.invalid']);
$orgInsert = $admin->prepare(
    "INSERT INTO portal_organizations
     (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES (?,?,?,'active',1,now(),now())"
);
$orgInsert->execute([$org,'gsc-f110-org-a','F110 test organization A']);
$orgInsert->execute([$otherOrg,'gsc-f110-org-b','F110 test organization B']);
$admin->prepare(
    "INSERT INTO portal_memberships
     (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'owner','active',1,now(),now())"
)->execute([$member,$actor,$org]);
$grantInsert = $admin->prepare(
    'INSERT INTO portal_membership_grants '
    .'(id,membership_id,permission_code,created_at,updated_at) '
    .'VALUES(?,?,?,now(),now())'
);
$grantInsert->execute([$serviceGrant,$member,'services.view']);
$admin->prepare(
    "INSERT INTO portal_auth_sessions
     (id,session_sha256,identity_id,organization_id,status,identity_revision,
      membership_revision,organization_revision,mfa_verified_at,issued_at,
      expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '1 minute',
      now()-interval '10 minutes',now()+interval '30 minutes',now(),now())"
)->execute([$sessionId,hash('sha256',$session),$actor,$org]);

$attempt = static fn(string $bearer, string $person, string $tenant,
    string $fingerprint, int $issued = 0): bool =>
    $issuer->issue($bearer,f110Token(),$verifier,$person,$tenant,
        $fingerprint,$issued > 0 ? $issued : time());

f110Assert(!$attempt($session,$actor,$org,$property),
    'session with services.view cannot connect Google');
$grantInsert->execute([$seoGrant,$member,'seo.gsc.connect']);
f110Assert(!$attempt($session,$actor,$org,$property),
    'explicit permission still requires approved candidate property');
$admin->prepare(
    'INSERT INTO public.portal_gsc_f110_allowed_properties '
    .'(organization_id,property_fingerprint,active) VALUES (CAST(:org AS uuid),:fingerprint,false)'
)->execute(['org'=>$org,'fingerprint'=>$property]);
f110Assert(!$attempt($session,$actor,$org,$property),
    'inactive property candidate denied');
$admin->exec(
    "UPDATE public.portal_gsc_f110_allowed_properties SET active=true
     WHERE organization_id='$org'"
);

f110Assert(!$attempt(f110Token(),$actor,$org,$property),
    'unknown session denied');
f110Assert(!$attempt($session,$otherActor,$org,$property),
    'identity mismatch denied');
f110Assert(!$attempt($session,$actor,$otherOrg,$property),
    'cross-tenant denied');
f110Assert(!$attempt($session,$actor,$org,$otherProperty),
    'other property not allowlisted');
f110Assert(!$attempt($session,$actor,$org,$property,$now+120),
    'client supplied future issue time denied');
f110Assert(!$attempt($session,$actor,$org,$property,$now-120),
    'client supplied stale issue time denied');

$state = f110Token();
f110Assert($issuer->issue($session,$state,$verifier,$actor,$org,$property,time()),
    'authenticated-shape synthetic Portal session + explicit grant permits issue');
f110Assert(!$issuer->issue($session,$state,$verifier,$actor,$org,$property,time()),
    'state duplicate rejected');
f110Assert($consumer->consume($state,$actor,$org,$property,time()) === $verifier,
    'F108 consumes a F110-authorized state only once');
f110Assert($consumer->consume($state,$actor,$org,$property,time()) === null,
    'state replay rejected');

$admin->exec("UPDATE portal_identities SET status='suspended' WHERE id='$actor'");
f110Assert(!$attempt($session,$actor,$org,$property), 'suspended identity denied');
$admin->exec("UPDATE portal_identities SET status='active',security_revision=2 WHERE id='$actor'");
f110Assert(!$attempt($session,$actor,$org,$property), 'identity revision mismatch denied');
$admin->exec("UPDATE portal_identities SET security_revision=1 WHERE id='$actor'");

$admin->exec("UPDATE portal_memberships SET status='suspended' WHERE id='$member'");
f110Assert(!$attempt($session,$actor,$org,$property), 'suspended membership denied');
$admin->exec("UPDATE portal_memberships SET status='active',membership_revision=2 WHERE id='$member'");
f110Assert(!$attempt($session,$actor,$org,$property), 'stale membership revision denied');
$admin->exec("UPDATE portal_memberships SET membership_revision=1 WHERE id='$member'");

$admin->exec("UPDATE portal_organizations SET status='suspended' WHERE id='$org'");
f110Assert(!$attempt($session,$actor,$org,$property), 'suspended organization denied');
$admin->exec("UPDATE portal_organizations SET status='active',security_revision=2 WHERE id='$org'");
f110Assert(!$attempt($session,$actor,$org,$property), 'stale organization revision denied');
$admin->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$org'");

$admin->exec("UPDATE portal_auth_sessions SET organization_revision=NULL WHERE id='$sessionId'");
f110Assert(!$attempt($session,$actor,$org,$property), 'legacy session revision denied');
$admin->exec("UPDATE portal_auth_sessions SET organization_revision=1 WHERE id='$sessionId'");
$admin->exec("UPDATE portal_auth_sessions SET status='revoked',revoked_at=now() WHERE id='$sessionId'");
f110Assert(!$attempt($session,$actor,$org,$property), 'revoked session denied');
$admin->exec("UPDATE portal_auth_sessions SET status='active',revoked_at=NULL WHERE id='$sessionId'");
$admin->exec("UPDATE portal_auth_sessions SET status='pending_mfa',mfa_verified_at=NULL WHERE id='$sessionId'");
f110Assert(!$attempt($session,$actor,$org,$property), 'missing session MFA denied');
$admin->exec(
    "UPDATE portal_auth_sessions SET status='active',mfa_verified_at=now()-interval '1 minute'
     WHERE id='$sessionId'"
);

$admin->exec("DELETE FROM portal_membership_grants WHERE id='$seoGrant'");
f110Assert(!$attempt($session,$actor,$org,$property),
    'removing SEO-only grant immediately denies new states');

try {
    $client->query('SELECT session_sha256 FROM portal_auth_sessions');
    throw new RuntimeException('identity table exposed to client');
} catch (PDOException $error) {
    f110Assert($error->getCode() === '42501', 'client has no session table SELECT');
}
try {
    $client->query('SELECT * FROM public.portal_gsc_f110_allowed_properties');
    throw new RuntimeException('property allowance table exposed');
} catch (PDOException $error) {
    f110Assert($error->getCode() === '42501', 'client cannot read property candidates');
}
try {
    $client->prepare(
        'SELECT public.portal_gsc_f109_authorized_issue('
        .':session_hash,:state_hash,CAST(:actor AS uuid),CAST(:org AS uuid),'
        .':property,CAST(:issued AS bigint),CAST(:expires AS bigint),:nonce,:cipher)'
    )->execute([
        'session_hash'=>hash('sha256',$session),
        'state_hash'=>hash('sha256',f110Token()),
        'actor'=>$actor,'org'=>$org,'property'=>$property,
        'issued'=>time(),'expires'=>time()+300,
        'nonce'=>str_repeat('A',32),'cipher'=>str_repeat('A',128)
    ]);
    throw new RuntimeException('F109 fixture issuer unexpectedly callable');
} catch (PDOException $error) {
    f110Assert($error->getCode() === '42501', 'legacy F109 emitter revoked');
}

$columns = $admin->query(
    "SELECT has_column_privilege(
      'portal_gsc_f110_owner','public.portal_identities','password_hash','SELECT'
     )"
)->fetchColumn();
f110Assert(in_array($columns,[false,'f','0',0],true),
    'F110 owner cannot SELECT password hashes');
putenv('PORTAL_F110_PG_LAB=0');
f110Assert(!$attempt($session,$actor,$org,$property), 'disabled lab always refuses');
f110Assert(!PortalGscF110OfflineIamIssuer::authorizesOperationalOAuth(),
    'no operational OAuth authority');

echo "GSC-F110 migration-backed tenant/MFA/revocation gate: PASS\n";
