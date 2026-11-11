<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalInvitationSecret;

if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_F117_PG_LAB')!=='1'
    || getenv('PGHOST')!=='127.0.0.1'
    || getenv('PGPORT')!=='5432'
    || getenv('PGDATABASE')!=='portal_identity_lab') {
    throw new RuntimeException('F117 requires disposable PostgreSQL 18 CI.');
}
$adminPassword=getenv('F66_PG_ADMIN_PASSWORD');
$probePassword=getenv('F117_PG_PROBE_PASSWORD');
if(!is_string($adminPassword)||strlen($adminPassword)<12
    ||!is_string($probePassword)||strlen($probePassword)<12){
    throw new RuntimeException('F117 requires ephemeral CI credentials.');
}
$connection=static fn(string $role,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$admin=$connection('postgres',$adminPassword);
$a=$connection('portal_f117_accept_probe',$probePassword);
$b=$connection('portal_f117_accept_probe',$probePassword);
$writer=$connection('postgres',$adminPassword);
$n=0;
$ok=static function(bool $condition,string $label)use(&$n):void{
    if(!$condition)throw new RuntimeException('PORTAL-F117 FAILED '.$label);
    $n++;
};
$isTrue=static fn(mixed $x):bool=>in_array($x,[true,1,'t','1'],true);
$isFalse=static fn(mixed $x):bool=>in_array($x,[false,0,'f','0'],true);
$denySql=static function(callable $fn,string $sqlState,string $label)use($ok):void{
    try{$fn();}catch(PDOException $e){$ok($e->getCode()===$sqlState,$label);return;}
    $ok(false,$label.' was not denied');
};

$role=$admin->query(
    "SELECT rolcanlogin,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole
      FROM pg_roles WHERE rolname='portal_f117_accept_owner'"
)->fetch();
$ok(is_array($role)&&$isFalse($role['rolcanlogin'])
    &&$isFalse($role['rolsuper'])&&$isFalse($role['rolbypassrls'])
    &&$isFalse($role['rolcreatedb'])&&$isFalse($role['rolcreaterole']),
    'activation owner cannot login, bypass RLS or administer PostgreSQL');
$permissions=$admin->query(
    "SELECT
       has_function_privilege('portal_f117_accept_probe',
       'public.portal_f117_accept_with_audit(text,text,uuid)'::regprocedure,
       'EXECUTE') AS approved,
       has_function_privilege('portal_f117_accept_probe',
       'public.portal_f108_locked_claim_with_audit(text,uuid,uuid)'::regprocedure,
       'EXECUTE') AS bypass,
       has_function_privilege('portal_f106_invitation_probe',
       'public.portal_f108_locked_claim_with_audit(text,uuid,uuid)'::regprocedure,
       'EXECUTE') AS old_bypass,
       has_table_privilege('portal_f117_accept_probe',
       'public.portal_memberships','SELECT') AS direct_read,
       has_table_privilege('portal_f117_accept_probe',
       'public.portal_security_events','INSERT') AS direct_audit"
)->fetch();
$ok($isTrue($permissions['approved'])
    &&$isFalse($permissions['bypass'])
    &&$isFalse($permissions['old_bypass'])
    &&$isFalse($permissions['direct_read'])
    &&$isFalse($permissions['direct_audit']),
    'caller can execute only audited all-or-nothing acceptance');
$secrets=$admin->query(
    "SELECT
       has_column_privilege('portal_f117_accept_owner',
         'public.portal_identities','password_hash','SELECT') AS pass,
       has_column_privilege('portal_f117_accept_owner',
         'public.portal_identities','mfa_secret_ciphertext','SELECT') AS mfa,
       has_table_privilege('portal_f117_accept_owner',
         'public.portal_membership_grants','INSERT') AS grant_write,
       has_table_privilege('portal_f117_accept_owner',
         'public.portal_auth_sessions','INSERT') AS session_issue"
)->fetch();
$ok($isFalse($secrets['pass'])&&$isFalse($secrets['mfa'])
    &&$isFalse($secrets['grant_write'])&&$isFalse($secrets['session_issue']),
    'owner cannot read credentials, grant service rights or issue new session');

$create=static function(
    string $identity,string $org,string $membership,
    string $sessionId,string $invitationId
)use($admin):array{
    $sessionBearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    $inviteSecret=PortalInvitationSecret::mint();
    $inviteBearer=$inviteSecret->takeDeliverySecretOnce();
    if(!is_string($inviteBearer))throw new RuntimeException('Fixture secret missing');
    $admin->prepare(
        "INSERT INTO portal_identities(
          id,email_normalized,status,email_verified_at,mfa_confirmed_at,
          security_revision,created_at,updated_at)
         VALUES(?,?,'active',now()-interval '1 day',
           now()-interval '1 day',1,now(),now())"
    )->execute([$identity,'f117-'.substr($identity,0,8).'@example.invalid']);
    $admin->prepare(
        "INSERT INTO portal_organizations(
          id,reference,display_name,status,security_revision,created_at,updated_at)
         VALUES(?,?,?,'active',1,now(),now())"
    )->execute([$org,'f117-'.substr($org,0,8),'F117 synthetic tenant']);
    $admin->prepare(
        "INSERT INTO portal_memberships(
          id,identity_id,organization_id,role,status,membership_revision,
          created_at,updated_at)
         VALUES(?,?,?,'member','invited',1,now(),now())"
    )->execute([$membership,$identity,$org]);
    $admin->prepare(
        "INSERT INTO portal_auth_sessions(
          id,session_sha256,identity_id,organization_id,status,session_purpose,
          identity_revision,membership_revision,organization_revision,
          mfa_verified_at,issued_at,expires_at,created_at,updated_at)
         VALUES(?,?,?,?,'active','invitation_only',1,1,1,
           now()-interval '1 minute',now()-interval '10 minutes',
           now()+interval '20 minutes',now(),now())"
    )->execute([$sessionId,hash('sha256',$sessionBearer),$identity,$org]);
    $admin->prepare(
        "INSERT INTO portal_access_invitations(
          id,token_sha256,identity_id,organization_id,
          identity_revision,membership_revision,organization_revision,
          status,issued_at,expires_at,created_at,updated_at)
         VALUES(?,?,?,?,1,1,1,'pending',now()-interval '1 minute',
           now()+interval '1 day',now(),now())"
    )->execute([$invitationId,$inviteSecret->digest(),$identity,$org]);
    return [
        'identity'=>$identity,'org'=>$org,'membership'=>$membership,
        'session'=>$sessionId,'invitation'=>$invitationId,
        'sessionDigest'=>hash('sha256',$sessionBearer),
        'invitationDigest'=>$inviteSecret->digest(),
    ];
};
$claim=static function(PDO $pdo,array $fixture,?string $org=null)
    use($isTrue):bool{
    $stmt=$pdo->prepare(
        'SELECT public.portal_f117_accept_with_audit('
        .':session,:invitation,CAST(:org AS uuid))'
    );
    $stmt->execute([
        'session'=>$fixture['sessionDigest'],
        'invitation'=>$fixture['invitationDigest'],
        'org'=>$org??$fixture['org'],
    ]);
    return $isTrue($stmt->fetchColumn());
};
$status=static function(array $f)use($admin):array{
    $membership=$admin->prepare(
        'SELECT status,membership_revision FROM portal_memberships WHERE id=?'
    );
    $membership->execute([$f['membership']]);
    $session=$admin->prepare(
        'SELECT status,session_purpose,revoked_at FROM portal_auth_sessions WHERE id=?'
    );
    $session->execute([$f['session']]);
    $invite=$admin->prepare(
        'SELECT status,consumed_at FROM portal_access_invitations WHERE id=?'
    );
    $invite->execute([$f['invitation']]);
    $events=$admin->prepare(
        "SELECT event_code,event_id,evidence_sha256,security_revision
         FROM portal_security_events
         WHERE identity_id=? AND organization_id=? ORDER BY sequence"
    );
    $events->execute([$f['identity'],$f['org']]);
    return [
        'membership'=>$membership->fetch(),
        'session'=>$session->fetch(),
        'invite'=>$invite->fetch(),
        'events'=>$events->fetchAll(),
    ];
};
$stillPending=static function(array $f)use($status):bool{
    $s=$status($f);
    return $s['membership']['status']==='invited'
        &&(int)$s['membership']['membership_revision']===1
        &&$s['session']['status']==='active'
        &&$s['session']['revoked_at']===null
        &&$s['session']['session_purpose']==='invitation_only'
        &&$s['invite']['status']==='pending'
        &&$s['invite']['consumed_at']===null
        &&count($s['events'])===0;
};

// All UUIDs and credentials are synthetic, unique to F117.
$first=$create(
    '71111111-1111-4111-8111-111111111111',
    '72222222-2222-4222-8222-222222222222',
    '73333333-3333-4333-8333-333333333333',
    '74444444-4444-4444-8444-444444444444',
    '75555555-5555-4555-8555-555555555555'
);
$second=$create(
    '81111111-1111-4111-8111-111111111111',
    '82222222-2222-4222-8222-222222222222',
    '83333333-3333-4333-8333-333333333333',
    '84444444-4444-4444-8444-444444444444',
    '85555555-5555-4555-8555-555555555555'
);
$third=$create(
    '91111111-1111-4111-8111-111111111111',
    '92222222-2222-4222-8222-222222222222',
    '93333333-3333-4333-8333-333333333333',
    '94444444-4444-4444-8444-444444444444',
    '95555555-5555-4555-8555-555555555555'
);
$fourth=$create(
    'a1111111-1111-4111-8111-111111111111',
    'a2222222-2222-4222-8222-222222222222',
    'a3333333-3333-4333-8333-333333333333',
    'a4444444-4444-4444-8444-444444444444',
    'a5555555-5555-4555-8555-555555555555'
);
$ok(!$claim($a,$first,$second['org']),
    'first-party session cannot accept invitation for a foreign company');
$fake=$first;
$fake['invitationDigest']=$second['invitationDigest'];
$ok(!$claim($a,$fake),'session cannot redeem another identity invitation');
$fake=$first;
$fake['sessionDigest']=hash('sha256','CRM-cookie-not-Portal');
$ok(!$claim($a,$fake),'CRM bearer cannot authenticate invitation acceptance');
$fake=$first;
$fake['sessionDigest']='BAD-HASH';
$ok(!$claim($a,$fake),'malformed digest fails closed');
$ok($stillPending($first),'unauthorized attempts do not change any of four records');

$a->beginTransaction();
$ok($claim($a,$first),'valid synthetic Portal session accepts invitation atomically');
$ok($stillPending($first),'changes are invisible to an independent connection');
$b->exec("SET lock_timeout='250ms'");
$ok(!$claim($b,$first),
    'competing acceptance cannot overtake an uncommitted identity/tenant lock');
$a->commit();
$b->exec('RESET lock_timeout');
$ok(!$claim($b,$first),'consumed temporary credential cannot be replayed');
$committed=$status($first);
$ok($committed['membership']['status']==='active'
    &&(int)$committed['membership']['membership_revision']===2
    &&$committed['session']['status']==='revoked'
    &&$committed['session']['revoked_at']!==null
    &&$committed['session']['session_purpose']==='invitation_only'
    &&$committed['invite']['status']==='consumed'
    &&$committed['invite']['consumed_at']!==null,
    'one COMMIT links consumed invite, activated member and revoked temp session');
$ok(count($committed['events'])===2
    &&$committed['events'][0]['event_code']==='invitation.consumed'
    &&$committed['events'][1]['event_code']==='membership.activated'
    &&(int)$committed['events'][1]['security_revision']===2,
    'exactly two F89 security audit entries follow the atomic activation');
$activation=$committed['events'][1];
$expectedEvidence=hash(
    'sha256','portal.f117.membership.activated.v1:'.$first['membership']
    .':'.$activation['event_id'].':'.$first['org'].':'.$first['identity']
);
$ok($activation['evidence_sha256']===$expectedEvidence
    &&$activation['evidence_sha256']!==$first['invitationDigest']
    &&$activation['evidence_sha256']!==$first['sessionDigest'],
    'F89 activation event binds UUIDs without storing bearer hashes');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_membership_grants
      WHERE membership_id='73333333-3333-4333-8333-333333333333'"
)->fetchColumn()===0,
    'membership activation does not auto-grant any service permissions');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_auth_sessions
      WHERE identity_id='71111111-1111-4111-8111-111111111111'
        AND session_purpose='business'"
)->fetchColumn()===0,
    'temporary session is retired without a business session being issued');

// F89 append-only event failure must roll back F108 token claim, F117
// membership activation and session revoke in the SAME subtransaction.
$admin->exec(
    "ALTER POLICY portal_f117_membership_audit_append
      ON public.portal_security_events WITH CHECK (false)"
);
$ok(!$claim($a,$second),
    'audit INSERT policy failure aborts entire acceptance');
$ok($stillPending($second),
    'audit error rolls back invitation, member and temp session states');
$admin->exec(
    "ALTER POLICY portal_f117_membership_audit_append
      ON public.portal_security_events
      WITH CHECK (event_code='membership.activated'
        AND identity_id IS NOT NULL AND security_revision>1)"
);
$ok($claim($a,$second),'restored audit policy admits valid synthetic acceptance');
$ok(count($status($second)['events'])===2,
    'retry after rolled back claim produces exactly one event pair');

// A rejected temporary-session UPDATE must roll back both earlier writes,
// including the lower-level F108 invitation.consumed event.
$admin->exec(
    "ALTER POLICY portal_f117_session_revoke
      ON public.portal_auth_sessions WITH CHECK (false)"
);
$ok(!$claim($a,$third),'session-revoke failure aborts acceptance');
$ok($stillPending($third),
    'failed provisional credential revoke rolls back member and invite events');
$admin->exec(
    "ALTER POLICY portal_f117_session_revoke
      ON public.portal_auth_sessions WITH CHECK (
        session_purpose='invitation_only' AND status='revoked'
        AND revoked_at IS NOT NULL)"
);

// A revocation writer acquiring the tenant lock first blocks and invalidates
// the acceptance. Never accept with stale security revision.
$writer->beginTransaction();
$writer->prepare(
    'UPDATE portal_organizations SET security_revision=2 WHERE id=?'
)->execute([$third['org']]);
$a->exec("SET lock_timeout='250ms'");
$ok(!$claim($a,$third),'cannot accept while tenant security revision is changing');
$a->exec('RESET lock_timeout');
$writer->commit();
$ok(!$claim($a,$third),'stale first-party session cannot pass tenant revision');
$ok($stillPending($third),'revocation never partially consumes invited token');

// Revoked session or old MFA cannot serve as the source of identity.
$admin->prepare(
    "UPDATE portal_auth_sessions SET mfa_verified_at=now()-interval '8 minutes'
      WHERE id=?"
)->execute([$fourth['session']]);
$ok(!$claim($a,$fourth),'stale MFA rejects acceptance');
$ok($stillPending($fourth),'stale MFA never changes membership or audit');
$admin->prepare(
    "UPDATE portal_auth_sessions SET mfa_verified_at=now()-interval '1 minute',
      status='revoked',revoked_at=now() WHERE id=?"
)->execute([$fourth['session']]);
$ok(!$claim($a,$fourth),'revoked first-party session rejects claim');

$ok($isFalse($admin->query(
    "SELECT has_table_privilege('portal_f117_accept_probe',
       'public.portal_memberships','UPDATE')"
)->fetchColumn()),'probe cannot modify memberships directly');
$admin->beginTransaction();
try{
    $admin->exec('SET LOCAL ROLE portal_f117_accept_owner');
    $admin->prepare(
        "UPDATE portal_identities SET status='revoked' WHERE id=?"
    )->execute([$second['identity']]);
    $ok(false,'technical definer must not revoke identity');
}catch(PDOException $e){
    $ok($e->getCode()==='42501',
        'F117 NOLOGIN owner cannot mutate identities under FORCE RLS');
}finally{
    if($admin->inTransaction())$admin->rollBack();
}

echo "PASS PORTAL-F117 {$n} atomic audited acceptance, revocation, replay and membership grants boundary assertions\n";
