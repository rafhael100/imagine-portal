<?php

declare(strict_types=1);

require_once __DIR__.'/PortalGscF109OfflineGatedIssuer.php';
require_once __DIR__.'/../gsc-f108/PortalGscF108OfflineSealedState.php';

function f109Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('GSC-F109: '.$message);
    }
}
function f109Token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

foreach ([
    'APP_ENV' => 'testing',
    'PORTAL_F107_GSC_LAB' => '1',
    'PORTAL_F108_PG_LAB' => '1',
    'PORTAL_F109_PG_LAB' => '1',
    'PGHOST' => '127.0.0.1',
    'PGPORT' => '5432',
    'PGDATABASE' => 'portal_identity_lab',
] as $k => $v) {
    putenv($k.'='.$v);
}
putenv('PORTAL_F107_KEY_B64='.f109Token());

$db = static fn(string $role, string $password): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab',
    $role,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES => false]
);
$clientPassword = getenv('F106_PG_CLIENT_PASSWORD');
$adminPassword = getenv('F106_PG_ADMIN_PASSWORD');
f109Assert(is_string($clientPassword) && is_string($adminPassword), 'CI lab roles exist');
$client = $db('portal_gsc_f106_client', $clientPassword);
$admin = $db('postgres', $adminPassword);
$issuer = new PortalGscF109OfflineGatedIssuer($client);
$consumer = new PortalGscF108OfflineSealedState($client);

$session = f109Token();
$actor = '123e4567-e89b-42d3-a456-426614174000';
$otherActor = '123e4567-e89b-42d3-a456-426614174099';
$org = '123e4567-e89b-42d3-a456-426614174001';
$otherOrg = '123e4567-e89b-42d3-a456-426614174002';
$property = hash('sha256', 'sc-domain:imagine.example');
$otherProperty = str_repeat('f', 64);
$now = 1794330000;
$verifier = f109Token();

$attempt = static fn(string $bearer, string $actorId, string $orgId,
    string $fingerprint, int $at): bool =>
    $issuer->issue($bearer, f109Token(), $verifier,
        $actorId, $orgId, $fingerprint, $at);

f109Assert(!$attempt($session, $actor, $org, $property, $now),
    'default deny without explicit SEO grant');

$grant = $admin->prepare(
    'INSERT INTO public.portal_gsc_f109_grants '
    .'(session_digest,actor_id,organization_id,property_fingerprint,'
    .'permission_code,mfa_confirmed,organization_revision,issued_at,expires_at) '
    .'VALUES(:digest,CAST(:actor AS uuid),CAST(:org AS uuid),:property,'
    ."'seo.gsc.connect',false,1,:issued,:expires)"
);
$grant->execute([
    'digest' => hash('sha256', $session),
    'actor' => $actor,
    'org' => $org,
    'property' => $property,
    'issued' => $now - 60,
    'expires' => $now + 3600,
]);
$update = $admin->prepare(
    'UPDATE public.portal_gsc_f109_grants SET mfa_confirmed=:flag '
    .'WHERE session_digest=:digest'
);
$update->execute(['flag' => false, 'digest' => hash('sha256', $session)]);
f109Assert(!$attempt($session, $actor, $org, $property, $now),
    'deny even explicit grant without confirmed synthetic MFA');
$admin->prepare(
    'UPDATE public.portal_gsc_f109_grants SET mfa_confirmed=true '
    .'WHERE session_digest=:digest'
)->execute(['digest' => hash('sha256', $session)]);

f109Assert(!$attempt(f109Token(), $actor, $org, $property, $now),
    'unknown session denied');
f109Assert(!$attempt($session, $otherActor, $org, $property, $now),
    'cross-actor denied');
f109Assert(!$attempt($session, $actor, $otherOrg, $property, $now),
    'cross-tenant denied');
f109Assert(!$attempt($session, $actor, $org, $otherProperty, $now),
    'cross-property denied');
f109Assert(!$attempt($session, $actor, $org, $property, $now - 61),
    'not-yet-issued session denied');
f109Assert(!$attempt($session, $actor, $org, $property, $now + 3600),
    'expired synthetic grant denied');
f109Assert(!$issuer->issue($session, f109Token(), 'weak', $actor, $org, $property, $now),
    'weak verifier denied');
f109Assert(!$issuer->issue('invalid-session', f109Token(), $verifier,
    $actor, $org, $property, $now), 'noncanonical session denied');

$state = f109Token();
f109Assert($issuer->issue($session, $state, $verifier, $actor, $org, $property, $now),
    'explicit MFA and property grant allows synthetic issue');
f109Assert(!$issuer->issue($session, $state, $verifier, $actor, $org, $property, $now),
    'duplicate issued state denied');
f109Assert($consumer->consume($state, $actor, $org, $property, $now + 1) === $verifier,
    'only authorized issued state can be consumed');
f109Assert($consumer->consume($state, $actor, $org, $property, $now + 2) === null,
    'consumed state cannot replay');

try {
    $client->prepare(
        'SELECT public.portal_gsc_f108_issue(:digest,CAST(:actor AS uuid),'
        .'CAST(:org AS uuid),:property,CAST(:issued AS bigint),'
        .'CAST(:expires AS bigint),:nonce,:cipher)'
    )->execute([
        'digest'=>hash('sha256', f109Token()), 'actor'=>$actor, 'org'=>$org,
        'property'=>$property, 'issued'=>$now, 'expires'=>$now+300,
        'nonce'=>str_repeat('A',32), 'cipher'=>str_repeat('A',128),
    ]);
    throw new RuntimeException('F108 direct issuer unexpectedly callable');
} catch (PDOException $e) {
    f109Assert($e->getCode() === '42501', 'direct F108 issuer is revoked');
}
try {
    $client->prepare(
        'SELECT public.portal_gsc_f106_issue(:digest,CAST(:actor AS uuid),'
        .'CAST(:org AS uuid),:property,CAST(:issued AS bigint),CAST(:expires AS bigint))'
    )->execute([
        'digest'=>hash('sha256', f109Token()), 'actor'=>$actor, 'org'=>$org,
        'property'=>$property, 'issued'=>$now, 'expires'=>$now+300,
    ]);
    throw new RuntimeException('F106 direct issuer unexpectedly callable');
} catch (PDOException $e) {
    f109Assert($e->getCode() === '42501', 'direct F106 issuer is revoked');
}
try {
    $client->query('SELECT session_digest FROM public.portal_gsc_f109_grants');
    throw new RuntimeException('grant table unexpectedly readable');
} catch (PDOException $e) {
    f109Assert($e->getCode() === '42501', 'no direct grant table SELECT');
}

$admin->prepare(
    'UPDATE public.portal_gsc_f109_grants SET revoked_at=:when '
    .'WHERE session_digest=:digest'
)->execute(['when'=>$now, 'digest'=>hash('sha256', $session)]);
f109Assert(!$attempt($session, $actor, $org, $property, $now + 1),
    'revocation blocks future issuance');
$admin->prepare(
    'UPDATE public.portal_gsc_f109_grants SET revoked_at=NULL,mfa_confirmed=false '
    .'WHERE session_digest=:digest'
)->execute(['digest'=>hash('sha256', $session)]);
f109Assert(!$attempt($session, $actor, $org, $property, $now + 2),
    'MFA removal blocks future issuance');

putenv('PORTAL_F109_PG_LAB=0');
f109Assert(!$attempt($session, $actor, $org, $property, $now),
    'disabled lab cannot issue');
f109Assert(!PortalGscF109OfflineGatedIssuer::authorizesOperationalOAuth(),
    'laboratory cannot authorize Google');

echo "GSC-F109 explicit SEO grant and issuer revocation: PASS\n";
