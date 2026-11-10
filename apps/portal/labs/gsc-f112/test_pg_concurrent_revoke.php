<?php

declare(strict_types=1);

require_once __DIR__.'/PortalGscF112OfflineLockedOAuth.php';

function f112Check(bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('GSC-F112: '.$label);
    }
}
function f112Token(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

foreach ([
    'APP_ENV'=>'testing',
    'PORTAL_F107_GSC_LAB'=>'1',
    'PORTAL_F112_PG_LAB'=>'1',
    'PGHOST'=>'127.0.0.1',
    'PGPORT'=>'5432',
    'PGDATABASE'=>'portal_identity_lab',
] as $k=>$v) {
    putenv($k.'='.$v);
}
putenv('PORTAL_F107_KEY_B64='.f112Token());
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab';
$connect=static fn(string $user,string $password): PDO => new PDO(
    $dsn,$user,$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$clientPassword=getenv('F106_PG_CLIENT_PASSWORD');
$adminPassword=getenv('F106_PG_ADMIN_PASSWORD');
f112Check(is_string($clientPassword)&&is_string($adminPassword),'ephemeral roles');
$client=$connect('portal_gsc_f106_client',$clientPassword);
$admin=$connect('postgres',$adminPassword);
$issuer=new PortalGscF112OfflineLockedOAuth($client);

$actor='123e4567-e89b-42d3-a456-426614174000';
$org='123e4567-e89b-42d3-a456-426614174001';
$sessionId='123e4567-e89b-42d3-a456-426614174006';
$grantId='123e4567-e89b-42d3-a456-426614174005';
$property=hash('sha256','sc-domain:imagine.example');
$session=f112Token();
$verifier=f112Token();

// Reuse only the invented Portal identity/MFA records from F110/F111.
$admin->prepare(
    "UPDATE public.portal_auth_sessions SET session_sha256=:digest,
      status='active',revoked_at=NULL,
      mfa_verified_at=now()-interval '1 minute' WHERE id=:id"
)->execute(['digest'=>hash('sha256',$session),'id'=>$sessionId]);

$make=static function () use ($issuer,$session,$verifier,$actor,$org,$property): string {
    $state=f112Token();
    f112Check($issuer->issue($session,$state,$verifier,$actor,$org,$property,time()),
        'valid synthetic issue');
    return $state;
};
$admin->exec("SET lock_timeout = '300ms'");

$probe=$admin->prepare('SELECT has_function_privilege(:role,:signature,:mode)');
foreach ([
  'public.portal_gsc_f111_issue(text,text,uuid,uuid,text,bigint,bigint,text,text)',
  'public.portal_gsc_f111_consume(text,text,uuid,uuid,text)',
] as $old) {
    $probe->execute(['role'=>'portal_gsc_f106_client','signature'=>$old,'mode'=>'EXECUTE']);
    f112Check(in_array($probe->fetchColumn(),[false,'f','0',0],true),
        'old F111 entry point cannot bypass locking');
}

$state=$make();
$client->beginTransaction();
f112Check($issuer->consume($session,$state,$actor,$org,$property)===$verifier,
    'transactional callback recovers verifier for matching synthetic session');

// A concurrent writer cannot finish revoking the same session while the
// callback holds SHARE row locks in its database transaction.
try {
    $admin->exec(
        "UPDATE public.portal_auth_sessions SET status='revoked',revoked_at=now() "
        ."WHERE id='$sessionId'"
    );
    throw new RuntimeException('revocation unexpectedly bypassed callback lock');
} catch (PDOException $e) {
    f112Check($e->getCode()==='55P03',
        'session revocation waits for callback transaction completion');
}
$client->commit();
$admin->exec(
    "UPDATE public.portal_auth_sessions SET status='revoked',revoked_at=now() "
    ."WHERE id='$sessionId'"
);
f112Check(!$issuer->issue($session,f112Token(),$verifier,$actor,$org,$property,time()),
    'new issuance denied once revocation commits');

$admin->exec(
    "UPDATE public.portal_auth_sessions SET status='active',revoked_at=NULL "
    ."WHERE id='$sessionId'"
);
$state=$make();
$client->beginTransaction();
f112Check($issuer->consume($session,$state,$actor,$org,$property)===$verifier,
    'callback with active explicit SEO permission');
try {
    $admin->exec(
        "DELETE FROM public.portal_membership_grants WHERE id='$grantId'"
    );
    throw new RuntimeException('grant deletion unexpectedly bypassed callback lock');
} catch (PDOException $e) {
    f112Check($e->getCode()==='55P03',
        'grant revocation waits for callback transaction completion');
}
$client->commit();

$admin->exec(
    "DELETE FROM public.portal_membership_grants WHERE id='$grantId'"
);
f112Check(!$issuer->issue($session,f112Token(),$verifier,$actor,$org,$property,time()),
    'new issue denied after grant deletion');

$admin->prepare(
    "INSERT INTO public.portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(:id,:membership,'seo.gsc.connect',now(),now())"
)->execute([
    'id'=>$grantId,'membership'=>'123e4567-e89b-42d3-a456-426614174003',
]);
$state=$make();
// A revocation committing before callback must deny even if state was
// issued while permission still existed.
$admin->exec("DELETE FROM public.portal_membership_grants WHERE id='$grantId'");
f112Check($issuer->consume($session,$state,$actor,$org,$property)===null,
    'pre-committed grant revocation denies and burns pending callback');
$admin->prepare(
    "INSERT INTO public.portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(:id,:membership,'seo.gsc.connect',now(),now())"
)->execute([
    'id'=>$grantId,'membership'=>'123e4567-e89b-42d3-a456-426614174003',
]);
f112Check($issuer->consume($session,$state,$actor,$org,$property)===null,
    'restoring grant cannot replay the burnt state');

// The narrowly scoped SQL owner may lock rows, but must not UPDATE a
// sensitive field or grant any UPDATE capability to client roles.
foreach ([
    'public.portal_identities'=>'password_hash',
    'public.portal_auth_sessions'=>'status',
    'public.portal_membership_grants'=>'permission_code',
] as $table=>$column) {
    $stmt=$admin->prepare(
        'SELECT has_column_privilege(:role,:table,:column,:mode)'
    );
    $stmt->execute([
        'role'=>'portal_gsc_f110_owner',
        'table'=>$table,'column'=>$column,'mode'=>'UPDATE',
    ]);
    f112Check(in_array($stmt->fetchColumn(),[false,'f','0',0],true),
        'NOLOGIN lock owner cannot modify sensitive field '.$column);
}
f112Check(!PortalGscF112OfflineLockedOAuth::authorizesOperationalOAuth(),
    'still no real Google or Portal authorization');
putenv('PORTAL_F112_PG_LAB=0');
f112Check(!$issuer->issue($session,f112Token(),$verifier,$actor,$org,$property,time()),
    'laboratory disabled is fail-closed');
echo "GSC-F112 concurrent revocation lock serialization: PASS\n";
