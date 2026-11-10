<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalInvitationSecret;

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F107_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F107 is restricted to disposable PostgreSQL 18 CI.');
}
$adminPass = getenv('F66_PG_ADMIN_PASSWORD');
$clientPass = getenv('F106_PG_PROBE_PASSWORD');
if (!is_string($adminPass) || strlen($adminPass)<12
    || !is_string($clientPass) || strlen($clientPass)<12) {
    throw new RuntimeException('F107 requires disposable CI-only credentials.');
}
$connect = static fn(string $role,string $password):PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$admin = $connect('postgres',$adminPass);
$a = $connect('portal_f106_invitation_probe',$clientPass);
$b = $connect('portal_f106_invitation_probe',$clientPass);
$n=0;
$ok = static function(bool $yes,string $label)use(&$n):void{
    if(!$yes) throw new RuntimeException('PORTAL-F107 FAILED: '.$label);
    ++$n;
};
$bool = static fn(mixed $v):bool=>in_array($v,[true,'t','1',1],true);
$denySql = static function(callable $action,string $sqlState,string $label)use($ok):void{
    try {
        $action();
    } catch(PDOException $e) {
        $ok($e->getCode()===$sqlState,$label);
        return;
    }
    $ok(false,$label.' unexpectedly accepted');
};

$identity='e1111111-1111-4111-8111-111111111111';
$org='e2222222-2222-4222-8222-222222222222';
$foreign='e3333333-3333-4333-8333-333333333333';
$membership='e4444444-4444-4444-8444-444444444444';
$admin->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
       security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',1,now(),now())"
)->execute([$identity,'f107-event@example.invalid']);
$orgInsert=$admin->prepare(
    "INSERT INTO portal_organizations
      (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$orgInsert->execute([$org,'f107-tenant','F107 Synthetic Home']);
$orgInsert->execute([$foreign,'f107-other','F107 Synthetic Foreign']);
$admin->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$membership,$identity,$org]);

$next=0;
$issue = static function()use($admin,$identity,$org,&$next):array{
    ++$next;
    $secret=PortalInvitationSecret::mint();
    $bearer=$secret->takeDeliverySecretOnce();
    if (!is_string($bearer)) throw new RuntimeException('missing fixture secret');
    $uuid='e5555555-5555-4555-8555-'.str_pad((string)$next,12,'0',STR_PAD_LEFT);
    $admin->prepare(
        "INSERT INTO portal_access_invitations(
           id,token_sha256,identity_id,organization_id,
           identity_revision,membership_revision,organization_revision,
           status,issued_at,expires_at,created_at,updated_at
         ) VALUES(?,?,?,?,1,1,1,'pending',now()-interval '1 minute',
           now()+interval '1 day',now(),now())"
    )->execute([$uuid,$secret->digest(),$identity,$org]);
    return [$uuid,$secret->digest(),$bearer];
};
$claim = static function(
    PDO $pdo,string $digest,string $identity,string $organization
)use($bool):bool {
    $stmt=$pdo->prepare(
        'SELECT public.portal_f107_claim_with_audit(:digest,'
        .'CAST(:identity AS uuid),CAST(:organization AS uuid))'
    );
    $stmt->execute([
        'digest'=>$digest,'identity'=>$identity,'organization'=>$organization,
    ]);
    return $bool($stmt->fetchColumn());
};
$total = static fn():int=>(int)$admin->query(
    "SELECT count(*) FROM portal_security_events WHERE event_code='invitation.consumed'"
)->fetchColumn();
$consumed = static function(string $uuid)use($admin):bool{
    $stmt=$admin->prepare(
        "SELECT status='consumed' FROM portal_access_invitations WHERE id=?"
    );
    $stmt->execute([$uuid]);
    return in_array($stmt->fetchColumn(),[true,'t','1',1],true);
};

$functionPrivileges=$admin->query(
    "SELECT
      has_function_privilege(
       'portal_f106_invitation_probe',
       'public.portal_f107_claim_with_audit(text,uuid,uuid)'::regprocedure,
       'EXECUTE'
      ) AS audited,
      has_function_privilege(
       'portal_f106_invitation_probe',
       'public.portal_f106_claim_invitation(text,uuid,uuid)'::regprocedure,
       'EXECUTE'
      ) AS unaudited"
)->fetch();
$ok($bool($functionPrivileges['audited'])
    && !$bool($functionPrivileges['unaudited']),
    'probe can only execute audited claim entrypoint');
$policy=$admin->query(
    "SELECT cmd, roles,with_check FROM pg_policies
      WHERE schemaname='public' AND tablename='portal_security_events'
        AND policyname='portal_f107_invite_event_append'"
)->fetch();
$ok(is_array($policy)
    && $policy['cmd']==='INSERT'
    && $policy['roles']==='{portal_f106_invitation_definer}'
    && str_contains((string)$policy['with_check'],"'invitation.consumed'"),
    'audit INSERT RLS policy only for NOLOGIN definer and allowed code');
$ok(!$bool($admin->query(
    "SELECT has_table_privilege('portal_f106_invitation_probe',
     'public.portal_security_events','INSERT')"
)->fetchColumn()),'caller cannot directly forge audit rows');
$ok(!$bool($admin->query(
    "SELECT has_table_privilege('portal_f106_invitation_definer',
     'public.portal_security_events','UPDATE')"
)->fetchColumn()),'definer cannot edit audit history');
$ok(!$bool($admin->query(
    "SELECT has_table_privilege('portal_f106_invitation_definer',
     'public.portal_security_events','DELETE')"
)->fetchColumn()),'definer cannot delete audit history');

[$id1,$digest1] = $issue();
$ok($total()===0,'no synthetic event before invitation claim');
$ok(!$claim($a,$digest1,$identity,$foreign),'foreign tenant cannot claim');
$ok(!$claim($a,$digest1,$foreign,$org),'foreign identity cannot claim');
$ok(!$claim($a,'bad-digest',$identity,$org),'bad digest cannot claim');
$ok($total()===0 && !$consumed($id1),'denied claims do not create any audit');

$ok($claim($a,$digest1,$identity,$org),'valid synthetic claim and audit must commit');
$ok($consumed($id1) && $total()===1,'claim and one audit event committed together');
$ok(!$claim($b,$digest1,$identity,$org),'replay rejected');
$ok($total()===1,'replay never appends a second audit event');
$event=$admin->query(
    "SELECT event_id,organization_id,identity_id,event_code,
       evidence_sha256,security_revision FROM portal_security_events"
)->fetch();
$expectedEvidence=hash('sha256','portal.f107.invitation.consumed.v1:'
    .$id1.':'.$event['event_id'].':'.$org.':'.$identity);
$ok($event['organization_id']===$org && $event['identity_id']===$identity
    && $event['event_code']==='invitation.consumed'
    && (int)$event['security_revision']===1
    && $event['evidence_sha256']===$expectedEvidence,
    'audit binds sanitized invite, identity, organization and event id, not bearer');
$ok($event['evidence_sha256']!==$digest1,
    'evidence SHA cannot be the bearer-equivalent invite digest');

// If the audit policy refuses INSERT, a successful F106 row transition must
// be rolled back by the F107 exception subtransaction. Restoring the audit
// policy allows the exact same invitation to be claimed once.
[$id2,$digest2] = $issue();
$admin->exec(
    "ALTER POLICY portal_f107_invite_event_append
      ON public.portal_security_events WITH CHECK (false)"
);
$ok(!$claim($a,$digest2,$identity,$org),
    'audit outage makes whole invitation transaction fail closed');
$ok(!$consumed($id2) && $total()===1,
    'audit INSERT failure rolls back invitation state');
$admin->exec(
    "ALTER POLICY portal_f107_invite_event_append
      ON public.portal_security_events
      WITH CHECK (
        event_code='invitation.consumed'
        AND identity_id IS NOT NULL AND security_revision>0
      )"
);
$ok($claim($a,$digest2,$identity,$org),
    'restoring audit policy permits one synthetic audited claim');
$ok($consumed($id2) && $total()===2,
    'restored audit transaction atomically persisted both records');

// Concurrent transactions must never yield two audit rows or two successful
// claims of the same invitation. The loser may time out at the row lock.
[$id3,$digest3] = $issue();
$a->beginTransaction();
$ok($claim($a,$digest3,$identity,$org),
    'first claimant owns uncommitted token update and audit insert');
$ok($total()===2,
    'uncommitted audit row not visible on independent admin connection');
$b->exec("SET lock_timeout='250ms'");
$ok(!$claim($b,$digest3,$identity,$org),
    'competing claim cannot consume while first holds token row');
$a->commit();
$ok(!$claim($b,$digest3,$identity,$org),
    'loser cannot consume after first COMMIT');
$ok($consumed($id3) && $total()===3,
    'exactly one committed event and token consumption across contenders');
$b->exec('RESET lock_timeout');

[$id4,$digest4] = $issue();
$a->beginTransaction();
$ok($claim($a,$digest4,$identity,$org),
    'claim under transaction can be rolled back');
$a->rollBack();
$ok(!$consumed($id4) && $total()===3,
    'ROLLBACK restores pending invitation and removes audit row');
$ok($claim($b,$digest4,$identity,$org),
    'another transaction may claim after first rollback');
$ok($total()===4 && $consumed($id4),
    'after rollback, exactly one new event persists');

$ok((int)$admin->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$identity'"
)->fetchColumn()===0,'claims and audit do not issue a session');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_memberships
     WHERE identity_id='$identity' AND organization_id='$org' AND status='active'"
)->fetchColumn()===0,'claims do not activate membership');

$denySql(static fn()=> $admin->exec(
    "UPDATE portal_security_events
       SET event_code='grant.revoked' WHERE event_code='invitation.consumed'"
),'42501','append-only trigger rejects audit mutation');
$ok($total()===4,'failed audit mutation cannot erase the evidence');

echo "PASS PORTAL-F107 {$n} transactional invitation+audit, replay, concurrent row claims and rollback checks\n";
