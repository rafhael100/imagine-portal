<?php

declare(strict_types=1);

require_once __DIR__.'/PortalGscF111OfflineSessionBoundOAuth.php';

function f111Check(bool $value, string $why): void {
    if (!$value) {
        throw new RuntimeException('GSC-F111: '.$why);
    }
}
function f111Token(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}
foreach ([
    'APP_ENV' => 'testing',
    'PORTAL_F107_GSC_LAB' => '1',
    'PORTAL_F111_PG_LAB' => '1',
    'PGHOST' => '127.0.0.1',
    'PGPORT' => '5432',
    'PGDATABASE' => 'portal_identity_lab',
] as $name => $value) {
    putenv($name.'='.$value);
}
putenv('PORTAL_F107_KEY_B64='.f111Token());

$connect = static fn(string $role, string $pass): PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab',
    $role, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$clientPassword = getenv('F106_PG_CLIENT_PASSWORD');
$adminPassword = getenv('F106_PG_ADMIN_PASSWORD');
f111Check(is_string($clientPassword) && is_string($adminPassword), 'synthetic role credentials');
$admin = $connect('postgres', $adminPassword);
$client = $connect('portal_gsc_f106_client', $clientPassword);
$other = $connect('portal_gsc_f106_client', $clientPassword);
$issuer = new PortalGscF111OfflineSessionBoundOAuth($client);
$callback = new PortalGscF111OfflineSessionBoundOAuth($other);

$actor = '123e4567-e89b-42d3-a456-426614174000';
$org = '123e4567-e89b-42d3-a456-426614174001';
$member = '123e4567-e89b-42d3-a456-426614174003';
$grantId = '123e4567-e89b-42d3-a456-426614174005';
$sessionId = '123e4567-e89b-42d3-a456-426614174006';
$otherSessionId = '123e4567-e89b-42d3-a456-426614174007';
$property = hash('sha256', 'sc-domain:imagine.example');
$verifier = f111Token();
$session = $admin->query(
    "SELECT session_sha256 FROM public.portal_auth_sessions WHERE id='$sessionId'"
)->fetchColumn();
f111Check(is_string($session) && strlen($session) === 64, 'F110 fixture session exists');
// The raw bearer is not persisted by design, so this test injects its own
// synthetic bearer into the existing F110 fixture only.
$sessionBearer = f111Token();
$admin->prepare(
    'UPDATE portal_auth_sessions SET session_sha256=:digest WHERE id=:id'
)->execute(['digest'=>hash('sha256',$sessionBearer),'id'=>$sessionId]);

$tryIssue = static fn(string $bearer): bool =>
    $issuer->issue($bearer, f111Token(), $verifier, $actor, $org, $property, time());
f111Check(!$tryIssue($sessionBearer), 'deny missing SEO grant (F110 removed it)');
$admin->prepare(
    'INSERT INTO public.portal_membership_grants '
    .'(id,membership_id,permission_code,created_at,updated_at) '
    ."VALUES(:id,:member,'seo.gsc.connect',now(),now())"
)->execute(['id'=>$grantId,'member'=>$member]);

$issue = static function () use ($issuer,$sessionBearer,$verifier,$actor,$org,$property): string {
    $state = f111Token();
    f111Check($issuer->issue(
        $sessionBearer,$state,$verifier,$actor,$org,$property,time()
    ), 'state issue must have current grant');
    return $state;
};

$state = $issue();
$stored = $admin->prepare(
    'SELECT session_digest,envelope_ciphertext,consumed_at '
    .'FROM public.portal_gsc_f106_pending WHERE state_digest=:digest'
);
$stored->execute(['digest'=>hash('sha256',$state)]);
$row = $stored->fetch(PDO::FETCH_ASSOC);
f111Check(is_array($row)
    && $row['session_digest'] === hash('sha256',$sessionBearer)
    && $row['consumed_at'] === null
    && !str_contains($row['envelope_ciphertext'],$verifier),
    'store only bound session digest and encrypted PKCE');
f111Check($callback->consume($sessionBearer,$state,$actor,$org,$property) === $verifier,
    'same session can complete callback from another connection');
f111Check($issuer->consume($sessionBearer,$state,$actor,$org,$property) === null,
    'state cannot replay');

$otherSession = f111Token();
$admin->prepare(
    "INSERT INTO portal_auth_sessions
      (id,session_sha256,identity_id,organization_id,status,identity_revision,
       membership_revision,organization_revision,mfa_verified_at,issued_at,
       expires_at,created_at,updated_at)
      VALUES(:id,:digest,:actor,:org,'active',1,1,1,now()-interval '1 minute',
             now()-interval '10 minutes',now()+interval '30 minutes',now(),now())"
)->execute([
    'id'=>$otherSessionId,'digest'=>hash('sha256',$otherSession),
    'actor'=>$actor,'org'=>$org
]);
$state = $issue();
f111Check($callback->consume($otherSession,$state,$actor,$org,$property) === null,
    'even same actor and tenant cannot swap a different active session');
f111Check($issuer->consume($sessionBearer,$state,$actor,$org,$property) === null,
    'session-swapped callback burns state permanently');

$state = $issue();
$admin->prepare('DELETE FROM portal_membership_grants WHERE id=:id')
    ->execute(['id'=>$grantId]);
f111Check($callback->consume($sessionBearer,$state,$actor,$org,$property) === null,
    'grant revocation after issue denies callback');
$admin->prepare(
    'INSERT INTO portal_membership_grants '
    .'(id,membership_id,permission_code,created_at,updated_at) '
    ."VALUES(:id,:member,'seo.gsc.connect',now(),now())"
)->execute(['id'=>$grantId,'member'=>$member]);
f111Check($callback->consume($sessionBearer,$state,$actor,$org,$property) === null,
    'restoring grant does not revive burnt state');

$state = $issue();
$admin->prepare(
    "UPDATE portal_auth_sessions SET status='revoked',revoked_at=now() WHERE id=:id"
)->execute(['id'=>$sessionId]);
f111Check($callback->consume($sessionBearer,$state,$actor,$org,$property) === null,
    'revoked session denies callback despite valid original issue');
$admin->prepare(
    "UPDATE portal_auth_sessions SET status='active',revoked_at=NULL WHERE id=:id"
)->execute(['id'=>$sessionId]);
f111Check($callback->consume($sessionBearer,$state,$actor,$org,$property) === null,
    'unrevoked session does not undo one-time consumption');

$state = $issue();
$admin->prepare(
    'UPDATE portal_gsc_f110_allowed_properties SET active=false '
    .'WHERE organization_id=:org'
)->execute(['org'=>$org]);
f111Check($callback->consume($sessionBearer,$state,$actor,$org,$property) === null,
    'property withdrawn after issue blocks callback');
$admin->prepare(
    'UPDATE portal_gsc_f110_allowed_properties SET active=true '
    .'WHERE organization_id=:org'
)->execute(['org'=>$org]);

$state = $issue();
$admin->prepare(
    "UPDATE portal_auth_sessions SET status='pending_mfa',mfa_verified_at=NULL WHERE id=:id"
)->execute(['id'=>$sessionId]);
f111Check($callback->consume($sessionBearer,$state,$actor,$org,$property) === null,
    'MFA removed after issue blocks callback');
$admin->prepare(
    "UPDATE portal_auth_sessions SET status='active',mfa_verified_at=now()-interval '1 minute' "
    .'WHERE id=:id'
)->execute(['id'=>$sessionId]);

foreach ([
    ['public.portal_gsc_f108_consume(text,uuid,uuid,text,bigint)', 'old callback'],
    ['public.portal_gsc_f110_iam_issued_state(text,text,uuid,uuid,text,bigint,bigint,text,text)', 'old issuer'],
] as [$function,$label]) {
    $statement = $admin->prepare(
        'SELECT has_function_privilege(:role,:signature,:mode)'
    );
    $statement->execute([
        'role'=>'portal_gsc_f106_client',
        'signature'=>$function,'mode'=>'EXECUTE'
    ]);
    f111Check(in_array($statement->fetchColumn(),[false,'f','0',0],true),
        'role cannot bypass F111 through '.$label);
}
try {
    $client->query('SELECT session_digest FROM public.portal_gsc_f106_pending');
    throw new RuntimeException('unexpected access to session-bound state');
} catch (PDOException $error) {
    f111Check($error->getCode() === '42501', 'no direct state table SELECT');
}
f111Check(!$issuer->issue($sessionBearer,'invalid',$verifier,$actor,$org,$property,time()),
    'invalid state rejected');
f111Check($issuer->consume('invalid',$state,$actor,$org,$property) === null,
    'invalid session bearer rejected');

putenv('PORTAL_F111_PG_LAB=0');
f111Check(!$tryIssue($sessionBearer), 'disabled test environment always denies');
f111Check($issuer->consume($sessionBearer,f111Token(),$actor,$org,$property) === null,
    'disabled callback fails closed');
f111Check(!PortalGscF111OfflineSessionBoundOAuth::authorizesOperationalOAuth(),
    'no operational OAuth grant');
echo "GSC-F111 session-bound one-time callback with post-issue revocation: PASS\n";
