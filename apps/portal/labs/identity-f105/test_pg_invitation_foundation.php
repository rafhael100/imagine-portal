<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalInvitationSecret;

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F105_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F105 only runs in disposable PostgreSQL 18 CI.');
}
$adminPassword=getenv('F66_PG_ADMIN_PASSWORD');
$readerPassword=getenv('F101_PG_READER_PASSWORD');
if (!is_string($adminPassword) || strlen($adminPassword)<12
    || !is_string($readerPassword) || strlen($readerPassword)<12) {
    throw new RuntimeException('F105 requires ephemeral runner-only credentials.');
}
$connect=static fn(string $role,string $password):PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db=$connect('postgres',$adminPassword);
$reader=$connect('portal_f101_reader',$readerPassword);
$n=0;
$ok=static function(bool $valid,string $label)use(&$n):void{
    if(!$valid) throw new RuntimeException('F105 FAILED: '.$label);
    ++$n;
};
$denyState=static function(callable $operation,string $expectedSqlstate,string $label)use($ok):void{
    try {
        $operation();
    } catch(PDOException $error) {
        $ok($error->getCode()===$expectedSqlstate,$label);
        return;
    }
    $ok(false,$label.' unexpectedly allowed');
};
$bool=static fn(mixed $v):bool=>in_array($v,[true,1,'1','t'],true);

$tables=$db->query(
    "SELECT relrowsecurity,relforcerowsecurity
       FROM pg_class
      WHERE oid='public.portal_access_invitations'::regclass"
)->fetch();
$ok(is_array($tables)
    && $bool($tables['relrowsecurity'])
    && $bool($tables['relforcerowsecurity']),
    'invitation table always has FORCE ROW LEVEL SECURITY');
$ok((int)$db->query(
    "SELECT count(*) FROM pg_policies
      WHERE schemaname='public' AND tablename='portal_access_invitations'"
)->fetchColumn()===0,'NO policies can grant invitation rows to any application role');
$ok(!PortalInvitationSecret::mayAuthenticateOrGrantAccess(),
    'invitation secret cannot authenticate a Portal account');

$id='a1111111-2222-4111-8111-111111111111';
$org='a2222222-3333-4222-8222-222222222222';
$foreign='a3333333-4444-4333-8333-333333333333';
$member='a4444444-5555-4444-8444-444444444444';
$db->prepare(
    "INSERT INTO portal_identities(id,email_normalized,status,security_revision,created_at,updated_at)
      VALUES(?,?,'invited',1,now(),now())"
)->execute([$id,'f105-invited@example.invalid']);
$insertOrg=$db->prepare(
    "INSERT INTO portal_organizations(id,reference,display_name,status,security_revision,created_at,updated_at)
      VALUES(?,?,?,'active',1,now(),now())"
);
$insertOrg->execute([$org,'f105-home','F105 Synthetic Home']);
$insertOrg->execute([$foreign,'f105-foreign','F105 Synthetic Other']);
$db->prepare(
    "INSERT INTO portal_memberships(id,identity_id,organization_id,role,status,
      membership_revision,created_at,updated_at)
      VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$member,$id,$org]);

$first=PortalInvitationSecret::mint();
$firstSecret=$first->takeDeliverySecretOnce();
$ok(is_string($firstSecret) && strlen($firstSecret)===43,
    '256-bit delivery secret generated for a synthetic invite');
$ok($first->takeDeliverySecretOnce()===null,'delivery secret visible at most once');
$insert=$db->prepare(
    "INSERT INTO portal_access_invitations(
       id,token_sha256,identity_id,organization_id,
       identity_revision,membership_revision,organization_revision,
       status,issued_at,expires_at,created_at,updated_at
    ) VALUES(?,?,?,?,1,1,1,'pending',now()-interval '1 minute',
            now()+interval '2 days',now(),now())"
);
$invite1='a5555555-6666-4555-8555-555555555555';
$invite2='a6666666-7777-4666-8666-666666666666';
$insert->execute([$invite1,$first->digest(),$id,$org]);
$row=$db->query(
    "SELECT token_sha256,status,consumed_at,revoked_at
       FROM portal_access_invitations WHERE id='$invite1'"
)->fetch();
$ok(is_array($row)
    && $row['token_sha256']===hash('sha256',$firstSecret)
    && $row['token_sha256']!==$firstSecret
    && $row['status']==='pending'
    && $row['consumed_at']===null
    && $row['revoked_at']===null,
    'database stores only canonical SHA256 and pending state');
$ok(PortalInvitationSecret::matchesCandidate($firstSecret,$row['token_sha256']),
    'constant-time digest match is independent of authorization');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$id'"
)->fetchColumn()===0,'creating invite does not issue any login or session');

$second=PortalInvitationSecret::mint();
$denyState(static fn()=> $insert->execute([
    $invite2,$second->digest(),$id,$org
]),'23505','at most one pending invitation per membership');
$db->exec(
    "UPDATE portal_access_invitations
        SET status='revoked',revoked_at=now() WHERE id='$invite1'"
);
$insert->execute([$invite2,$second->digest(),$id,$org]);
$ok((int)$db->query(
    "SELECT count(*) FROM portal_access_invitations
      WHERE identity_id='$id' AND organization_id='$org' AND status='pending'"
)->fetchColumn()===1,'reissue possible only after explicit revocation of previous pending invite');

$denyState(static fn()=> $insert->execute([
    'a7777777-8888-4777-8777-777777777777',
    hash('sha256','foreign-fixture'),$id,$foreign,
]),'23503','cross-organization invitation must reference an existing membership');

$denyState(static fn()=> $db->exec(
    "UPDATE portal_access_invitations SET token_sha256='ABC' WHERE id='$invite2'"
),'23514','noncanonical digest is rejected by SQL constraint');
$denyState(static fn()=> $db->exec(
    "UPDATE portal_access_invitations SET expires_at=issued_at+interval '73 hours'
      WHERE id='$invite2'"
),'23514','invitation over 72-hour hard lifetime denied');
$denyState(static fn()=> $db->exec(
    "UPDATE portal_access_invitations SET identity_revision=0 WHERE id='$invite2'"
),'23514','nonpositive security revision denied');
$denyState(static fn()=> $db->exec(
    "UPDATE portal_access_invitations SET status='consumed',consumed_at=NULL
      WHERE id='$invite2'"
),'23514','cannot mark consumed without a consumption timestamp');
$denyState(static fn()=> $db->exec(
    "UPDATE portal_access_invitations SET status='consumed',
      consumed_at=issued_at-interval '1 second' WHERE id='$invite2'"
),'23514','cannot consume an invitation before issuance');

$readerAdmin=$db->query(
    "SELECT has_table_privilege('portal_f101_reader',
      'public.portal_access_invitations','INSERT')"
)->fetchColumn();
$ok(!$bool($readerAdmin),'reader role cannot insert an invitation');
$db->exec(
    'GRANT SELECT ON TABLE public.portal_access_invitations TO portal_f101_reader'
);
$readerRows=$reader->query('SELECT count(*) FROM public.portal_access_invitations')
    ->fetchColumn();
$ok((int)$readerRows===0,'even explicit SELECT grant yields zero rows without a policy');
$denyState(static fn()=> $reader->exec(
    "DELETE FROM public.portal_access_invitations"
),'42501','reader cannot revoke or delete an invitation');

$db->exec(
    "UPDATE portal_access_invitations
       SET status='consumed', consumed_at=now() WHERE id='$invite2'"
);
$ok((int)$db->query(
    "SELECT count(*) FROM portal_access_invitations
      WHERE id='$invite2' AND status='consumed' AND consumed_at IS NOT NULL"
)->fetchColumn()===1,'consumed marker is structurally valid');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$id'"
)->fetchColumn()===0,'consuming a marker does not confer session without trusted issuer');
$ok((int)$db->query(
    "SELECT count(*) FROM pg_policies
      WHERE schemaname='public' AND tablename='portal_access_invitations'"
)->fetchColumn()===0,'no invitation access policy accidentally introduced');

echo "PASS PORTAL-F105 {$n} invitation token, FK, lifetime, partial uniqueness and closed RLS checks\n";
