<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/identity-f112/PortalF112OfflinePgSessionIdentitySource.php';

use App\Domain\Identity\Invitations\PortalInvitationPreflight;
use App\Domain\Identity\Invitations\PortalInvitationSecret;
use App\Domain\Organizations\OrganizationId;

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F113_PG_LAB') !== '1'
    || getenv('PORTAL_F112_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F113 requires disposable PostgreSQL 18 CI.');
}
$adminPassword=getenv('F66_PG_ADMIN_PASSWORD');
$sessionPassword=getenv('F112_PG_READER_PASSWORD');
$businessPassword=getenv('F76_PG_READER_PASSWORD');
if (!is_string($adminPassword) || strlen($adminPassword)<12
    || !is_string($sessionPassword) || strlen($sessionPassword)<12
    || !is_string($businessPassword) || strlen($businessPassword)<12) {
    throw new RuntimeException('F113 requires disposable CI credentials only.');
}
$connect=static fn(string $role,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$admin=$connect('postgres',$adminPassword);
$businessReader=$connect('portal_f76_reader',$businessPassword);
$invitationReader=$connect('portal_f112_reader',$sessionPassword);
$source=new PortalF112OfflinePgSessionIdentitySource($invitationReader);
$gate=new PortalInvitationPreflight($source);
$n=0;
$ok=static function(bool $v,string $why)use(&$n):void{
    if(!$v)throw new RuntimeException('PORTAL-F113 FAILED: '.$why);
    ++$n;
};
$yes=static fn(mixed $v):bool=>in_array($v,[true,'t','1',1],true);
$no=static fn(mixed $v):bool=>in_array($v,[false,'f','0',0],true);
$denySql=static function(callable $fn,string $code,string $why)use($ok):void{
    try {
        $fn();
    }catch(PDOException $e){
        $ok($e->getCode()===$code,$why);
        return;
    }
    $ok(false,$why.' was unexpectedly permitted');
};

$purposeColumn=$admin->query(
    "SELECT column_default,is_nullable FROM information_schema.columns
       WHERE table_schema='public' AND table_name='portal_auth_sessions'
         AND column_name='session_purpose'"
)->fetch();
$ok(is_array($purposeColumn)
    && $purposeColumn['column_default']===null
    && $purposeColumn['is_nullable']==='YES',
    'new purpose has no default and old sessions remain unclassified');
$owner=$admin->query(
    "SELECT rolcanlogin,rolsuper,rolbypassrls
       FROM pg_roles WHERE rolname='portal_f113_business_owner'"
)->fetch();
$ok(is_array($owner) && $no($owner['rolcanlogin'])
    && $no($owner['rolsuper']) && $no($owner['rolbypassrls']),
    'purpose-aware business gate uses non-login non-superuser no-BYPASSRLS owner');
$grants=$admin->query(
    "SELECT
      has_function_privilege('portal_f76_reader',
       'public.portal_f76_permission_check(text,uuid,text)'::regprocedure,
       'EXECUTE') AS direct,
      has_function_privilege('portal_f76_reader',
       'public.portal_f113_business_permission_check(text,uuid,text)'::regprocedure,
       'EXECUTE') AS guarded"
)->fetch();
$ok($no($grants['direct']) && $yes($grants['guarded']),
    'business caller cannot bypass purpose gate using F76 directly');
$policy=$admin->query(
    "SELECT roles,qual FROM pg_policies
       WHERE schemaname='public' AND tablename='portal_auth_sessions'
         AND policyname='portal_f112_session_read'"
)->fetch();
$ok($policy['roles']==='{portal_f112_owner}'
    && str_contains((string)$policy['qual'],"'invitation_only'"),
    'invitation source only sees invitation_only sessions through FORCE RLS');

$id='d1111111-1111-4111-8111-111111111111';
$org='d2222222-2222-4222-8222-222222222222';
$membership='d3333333-3333-4333-8333-333333333333';
$inviteId='d4444444-4444-4444-8444-444444444444';
$businessId='d5555555-5555-4555-8555-555555555555';
$legacyId='d6666666-6666-4666-8666-666666666666';
$tenant=new OrganizationId($org);
$inviteBearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$businessBearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$legacyBearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$acceptBearer=PortalInvitationSecret::mint()->takeDeliverySecretOnce();
if(!is_string($acceptBearer)) throw new RuntimeException('Missing synthetic invitation.');

$admin->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
       security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 hour',
       now()-interval '1 hour',1,now(),now())"
)->execute([$id,'f113@example.invalid']);
$admin->prepare(
    "INSERT INTO portal_organizations(id,reference,display_name,status,
      security_revision,created_at,updated_at)
     VALUES(?,'f113-tenant','F113 synthetic tenant','active',1,now(),now())"
)->execute([$org]);
$admin->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,
       created_at,updated_at)
     VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$membership,$id,$org]);

$insertSession=$admin->prepare(
    "INSERT INTO portal_auth_sessions
      (id,session_sha256,identity_id,organization_id,status,
       session_purpose,identity_revision,membership_revision,organization_revision,
       mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',?,1,1,1,now()-interval '1 minute',
       now()-interval '10 minutes',now()+interval '20 minutes',now(),now())"
);
$insertSession->execute([
    $inviteId,hash('sha256',$inviteBearer),$id,$org,'invitation_only',
]);
$evidence=static fn(string $bearer):bool=>$source->findFor($bearer,$tenant)!==null;
$checkBusiness=static function(string $bearer,string $permission)
    use($businessReader,$org,$yes):bool {
    $stmt=$businessReader->prepare(
        'SELECT public.portal_f113_business_permission_check('
        .':digest,CAST(:org AS uuid),:permission)'
    );
    $stmt->execute([
        'digest'=>hash('sha256',$bearer),'org'=>$org,'permission'=>$permission,
    ]);
    return $yes($stmt->fetchColumn());
};

$ok($evidence($inviteBearer),
    'invited member has DB-based synthetic invitation-only evidence');
$result=$gate->inspect($inviteBearer,$acceptBearer,$tenant,time());
$ok($result->syntheticInputsPlausible
    && !$result->canAuthorizeHttp()
    && !$result->canConsumeInvitation(),
    'invitation_only can pass synthetic preflight but cannot grant access');
$ok(!$checkBusiness($inviteBearer,'services.view'),
    'invitation_only cannot obtain business authorization before activation');

$admin->prepare(
    "INSERT INTO portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute(['d7777777-7777-4777-8777-777777777777',$membership]);
// Adversarial situation: membership becomes active but security revision
// is NOT incremented. F76 (old raw predicate) would now authorize a
// matching token, so F113 must remain safe even under this mistake.
$admin->exec(
    "UPDATE portal_memberships SET status='active' WHERE id='$membership'"
);
$legacyF76=$admin->prepare(
    'SELECT public.portal_f76_permission_check('
    .':digest,CAST(:org AS uuid),:permission)'
);
$legacyF76->execute([
    'digest'=>hash('sha256',$inviteBearer),
    'org'=>$org,'permission'=>'services.view',
]);
$ok($yes($legacyF76->fetchColumn()),
    'old unguarded F76 illustrates escalation risk after membership activation');
$ok(!$checkBusiness($inviteBearer,'services.view'),
    'F113 purpose gate prevents invitation token becoming business credential');
$ok(!$evidence($inviteBearer),
    'invitation-only preflight refuses already active membership');
$ok($admin->query(
    "SELECT session_purpose FROM portal_auth_sessions WHERE id='$inviteId'"
)->fetchColumn()==='invitation_only','purpose remains immutable');

$insertSession->execute([
    $businessId,hash('sha256',$businessBearer),$id,$org,'business',
]);
$ok($checkBusiness($businessBearer,'services.view'),
    'only newly issued business-purpose fixture can reach explicit grant');
$ok(!$checkBusiness($businessBearer,'billing.view'),
    'business purpose does not bypass explicit grant enforcement');
$ok(!$evidence($businessBearer),
    'business credential cannot be used as invitation acceptance evidence');

// Nullable legacy rows are deliberately refused by both gates.
$admin->prepare(
    "INSERT INTO portal_auth_sessions
      (id,session_sha256,identity_id,organization_id,status,
       identity_revision,membership_revision,organization_revision,
       mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '1 minute',
       now()-interval '10 minutes',now()+interval '20 minutes',now(),now())"
)->execute([$legacyId,hash('sha256',$legacyBearer),$id,$org]);
$ok(!$evidence($legacyBearer) && !$checkBusiness($legacyBearer,'services.view'),
    'old NULL-purpose session cannot silently inherit any scope');
$legacyF76->execute([
    'digest'=>hash('sha256',$legacyBearer),
    'org'=>$org,'permission'=>'services.view',
]);
$ok($yes($legacyF76->fetchColumn()),
    'raw old F76 would have accepted legacy NULL-purpose session; reader cannot call it');

$denySql(static fn()=> $admin->exec(
    "UPDATE portal_auth_sessions SET session_purpose='business' WHERE id='$inviteId'"
),'23514','cannot upgrade invitation-only token after issuance');
$denySql(static fn()=> $admin->exec(
    "UPDATE portal_auth_sessions SET session_purpose='invitation_only' WHERE id='$businessId'"
),'23514','cannot downgrade a business token for invitation acceptance');
$denySql(static fn()=> $admin->exec(
    "UPDATE portal_auth_sessions SET session_purpose='business' WHERE id='$legacyId'"
),'23514','legacy NULL purpose cannot be upgraded in place');
$denySql(static fn()=> $admin->exec(
    "UPDATE portal_auth_sessions SET session_purpose='unknown' WHERE id='$inviteId'"
),'23514','invalid purpose rejected without widening scope');

$badSession=$admin->prepare(
    "INSERT INTO portal_auth_sessions
      (id,session_sha256,identity_id,organization_id,status,
       session_purpose,identity_revision,membership_revision,organization_revision,
       mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',?,1,1,1,now()-interval '1 minute',
       now()-interval '1 minute',now()+interval '2 hours',now(),now())"
);
$denySql(static fn()=> $badSession->execute([
    'd8888888-8888-4888-8888-888888888888',
    hash('sha256',random_bytes(32)),$id,$org,'invitation_only',
]),'23514','invitation-only token cannot have TTL over one hour');
$ok($no($admin->query(
    "SELECT has_table_privilege('portal_f76_reader',
      'public.portal_auth_sessions','UPDATE')"
)->fetchColumn()),'business reader cannot change session purpose or status');

$admin->exec("UPDATE portal_organizations SET security_revision=2 WHERE id='$org'");
$ok(!$checkBusiness($businessBearer,'services.view'),
    'business credential revoked after org revision changes');
$admin->exec("UPDATE portal_organizations SET security_revision=1 WHERE id='$org'");
$admin->exec(
    "UPDATE portal_auth_sessions SET status='revoked',revoked_at=now()
      WHERE id='$businessId'"
);
$ok(!$checkBusiness($businessBearer,'services.view'),
    'business credential revocation remains effective');

echo "PASS PORTAL-F113 {$n} immutable purpose and invitation/business privilege isolation assertions\n";
