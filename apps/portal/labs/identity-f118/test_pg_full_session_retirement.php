<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalInvitationSecret;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'
    ||getenv('PORTAL_F118_PG_LAB')!=='1'
    ||getenv('PGHOST')!=='127.0.0.1'
    ||getenv('PGPORT')!=='5432'
    ||getenv('PGDATABASE')!=='portal_identity_lab'){
    throw new RuntimeException('F118 only runs on disposable PG18 CI.');
}
$adminPass=getenv('F66_PG_ADMIN_PASSWORD');
$probePass=getenv('F118_PG_PROBE_PASSWORD');
$businessPass=getenv('F76_PG_READER_PASSWORD');
if(!is_string($adminPass)||strlen($adminPass)<12
    ||!is_string($probePass)||strlen($probePass)<12
    ||!is_string($businessPass)||strlen($businessPass)<12){
    throw new RuntimeException('F118 requires synthetic CI-only credentials.');
}
$connect=static fn(string $role,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db=$connect('postgres',$adminPass);
$a=$connect('portal_f118_retirement_probe',$probePass);
$b=$connect('portal_f118_retirement_probe',$probePass);
$business=$connect('portal_f76_reader',$businessPass);
$n=0;
$ok=static function(bool $condition,string $message)use(&$n):void{
    if(!$condition)throw new RuntimeException('PORTAL-F118 FAILED '.$message);
    $n++;
};
$yes=static fn(mixed $x):bool=>in_array($x,[true,1,'t','1'],true);
$no=static fn(mixed $x):bool=>in_array($x,[false,0,'f','0'],true);
$role=$db->query(
    "SELECT rolcanlogin,rolsuper,rolbypassrls,rolcreaterole,rolcreatedb
      FROM pg_roles WHERE rolname='portal_f118_retirement_owner'"
)->fetch();
$ok(is_array($role)&&$no($role['rolcanlogin'])
    &&$no($role['rolsuper'])&&$no($role['rolbypassrls'])
    &&$no($role['rolcreaterole'])&&$no($role['rolcreatedb']),
    'retirement definer has NOLOGIN/NOSUPERUSER/NOBYPASSRLS');
$priv=$db->query(
    "SELECT
       has_function_privilege('portal_f118_retirement_probe',
         'public.portal_f118_accept_and_retire_all(text,text,uuid)'::regprocedure,
         'EXECUTE') AS wrapped,
       has_function_privilege('portal_f118_retirement_probe',
         'public.portal_f117_accept_with_audit(text,text,uuid)'::regprocedure,
         'EXECUTE') AS bypass,
       has_function_privilege('portal_f117_accept_probe',
         'public.portal_f117_accept_with_audit(text,text,uuid)'::regprocedure,
         'EXECUTE') AS older_bypass,
       has_table_privilege('portal_f118_retirement_probe',
         'public.portal_auth_sessions','SELECT') AS session_read,
       has_table_privilege('portal_f118_retirement_probe',
         'public.portal_auth_sessions','UPDATE') AS session_write,
       has_table_privilege('portal_f118_retirement_owner',
         'public.portal_auth_sessions','INSERT') AS new_session,
       has_table_privilege('portal_f118_retirement_owner',
         'public.portal_membership_grants','INSERT') AS new_permission"
)->fetch();
$ok($yes($priv['wrapped'])&&$no($priv['bypass'])
    &&$no($priv['older_bypass'])&&$no($priv['session_read'])
    &&$no($priv['session_write'])&&$no($priv['new_session'])
    &&$no($priv['new_permission']),
    'only wrapped retirement function is callable without direct DB access');
$policy=$db->query(
    "SELECT roles,cmd,with_check FROM pg_policies
     WHERE schemaname='public' AND tablename='portal_auth_sessions'
       AND policyname='portal_f118_session_retire'"
)->fetch();
$ok($policy['roles']==='{portal_f118_retirement_owner}'
    &&$policy['cmd']==='UPDATE'
    &&str_contains((string)$policy['with_check'],"'revoked'"),
    'F118 mutation policy permits only terminal revocation');
$allSessions=$db->prepare(
    "SELECT id,session_sha256,status,session_purpose,revoked_at
       FROM portal_auth_sessions
      WHERE identity_id=:actor AND organization_id=:org
      ORDER BY id"
);
$events=$db->prepare(
    "SELECT event_id,event_code,evidence_sha256,security_revision
       FROM portal_security_events
      WHERE identity_id=:actor AND organization_id=:org ORDER BY sequence"
);
$create=static function(string $prefix)use($db):array{
    // UUIDs are synthetic; each fixture uses a distinct first hex digit.
    $actor=$prefix.'1111111-1111-4111-8111-111111111111';
    $org=$prefix.'2222222-2222-4222-8222-222222222222';
    $member=$prefix.'3333333-3333-4333-8333-333333333333';
    $main=$prefix.'4444444-4444-4444-8444-444444444444';
    $invite=$prefix.'5555555-5555-4555-8555-555555555555';
    $bearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    $inviteSecret=PortalInvitationSecret::mint();
    $invitationBearer=$inviteSecret->takeDeliverySecretOnce();
    if(!is_string($invitationBearer))throw new RuntimeException('No synthetic invitation');
    $db->prepare(
        "INSERT INTO portal_identities
        (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
         security_revision,created_at,updated_at)
        VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',
         1,now(),now())"
    )->execute([$actor,'f118-'.$prefix.'@example.invalid']);
    $db->prepare(
        "INSERT INTO portal_organizations
        (id,reference,display_name,status,security_revision,created_at,updated_at)
        VALUES(?,?,?,'active',1,now(),now())"
    )->execute([$org,'f118-'.$prefix,'F118 Synthetic organization '.$prefix]);
    $db->prepare(
        "INSERT INTO portal_memberships
        (id,identity_id,organization_id,role,status,membership_revision,
         created_at,updated_at)
        VALUES(?,?,?,'member','invited',1,now(),now())"
    )->execute([$member,$actor,$org]);
    $db->prepare(
        "INSERT INTO portal_auth_sessions
        (id,session_sha256,identity_id,organization_id,status,session_purpose,
         identity_revision,membership_revision,organization_revision,
         mfa_verified_at,issued_at,expires_at,created_at,updated_at)
        VALUES(?,?,?,?,'active','invitation_only',1,1,1,
          now()-interval '1 minute',now()-interval '10 minutes',
          now()+interval '20 minutes',now(),now())"
    )->execute([$main,hash('sha256',$bearer),$actor,$org]);
    $db->prepare(
        "INSERT INTO portal_access_invitations
        (id,token_sha256,identity_id,organization_id,
         identity_revision,membership_revision,organization_revision,
         status,issued_at,expires_at,created_at,updated_at)
        VALUES(?,?,?,?,1,1,1,'pending',now()-interval '1 minute',
         now()+interval '1 day',now(),now())"
    )->execute([$invite,$inviteSecret->digest(),$actor,$org]);
    return [
        'actor'=>$actor,'org'=>$org,'member'=>$member,'main'=>$main,
        'invite'=>$invite,'sessionHash'=>hash('sha256',$bearer),
        'inviteHash'=>$inviteSecret->digest()
    ];
};
$extra=static function(array $f,string $id,string $purpose,string $status='active',
    ?string $tenant=null,?string $actor=null)use($db):void{
    $suspended=$status==='pending_mfa';
    $db->prepare(
        "INSERT INTO portal_auth_sessions
        (id,session_sha256,identity_id,organization_id,status,session_purpose,
         identity_revision,membership_revision,organization_revision,
         mfa_verified_at,issued_at,expires_at,created_at,updated_at)
        VALUES(?,?,?,?,?, ?,1,1,1,?,
           now()-interval '10 minutes',now()+interval '20 minutes',now(),now())"
    )->execute([
        $id,hash('sha256','f118-fixture-'.$id),$actor??$f['actor'],
        $tenant??$f['org'],$status,$purpose==='legacy'?null:$purpose,
        $suspended?null:date('Y-m-d H:i:sP',time()-60),
    ]);
};
$claim=static function(PDO $connection,array $f)use($yes):bool{
    $q=$connection->prepare(
        'SELECT public.portal_f118_accept_and_retire_all('
        .':session,:invitation,CAST(:org AS uuid))'
    );
    $q->execute([
        'session'=>$f['sessionHash'],'invitation'=>$f['inviteHash'],
        'org'=>$f['org']
    ]);
    return $yes($q->fetchColumn());
};
$state=static function(array $f)use($db,$allSessions,$events):array{
    $q=$db->prepare('SELECT status,membership_revision FROM portal_memberships WHERE id=?');
    $q->execute([$f['member']]);
    $member=$q->fetch();
    $q=$db->prepare('SELECT status FROM portal_access_invitations WHERE id=?');
    $q->execute([$f['invite']]);
    $invite=$q->fetchColumn();
    $allSessions->execute(['actor'=>$f['actor'],'org'=>$f['org']]);
    $sessions=$allSessions->fetchAll();
    $events->execute(['actor'=>$f['actor'],'org'=>$f['org']]);
    $records=$events->fetchAll();
    return ['member'=>$member,'invitation'=>$invite,'sessions'=>$sessions,'events'=>$records];
};
$isPending=static function(array $f)use($state):bool{
    $s=$state($f);
    return $s['member']['status']==='invited'
        &&(int)$s['member']['membership_revision']===1
        &&$s['invitation']==='pending'
        &&count($s['events'])===0
        &&count(array_filter($s['sessions'],static fn($r)=>$r['status']==='revoked'))===0;
};

// Include all session categories and a pre-issued BUSINESS bearer. The
// business token must never gain privilege when membership is activated.
$first=$create('1');
$extra($first,'16666666-6666-4666-8666-666666666666','invitation_only');
$extra($first,'17777777-7777-4777-8777-777777777777','invitation_only','pending_mfa');
$extra($first,'18888888-8888-4888-8888-888888888888','business');
$extra($first,'19999999-9999-4999-8999-999999999999','legacy');
$db->prepare(
    "INSERT INTO portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute(['1aaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',$first['member']]);
$legacyBusinessDigest=hash('sha256','f118-fixture-18888888-8888-4888-8888-888888888888');
$canBusiness=static function(string $digest,array $f)use($business,$yes):bool{
    $q=$business->prepare(
        'SELECT public.portal_f113_business_permission_check('
        .':digest,CAST(:org AS uuid),:permission)'
    );
    $q->execute([
        'digest'=>$digest,'org'=>$f['org'],'permission'=>'services.view',
    ]);
    return $yes($q->fetchColumn());
};
$ok(!$canBusiness($legacyBusinessDigest,$first),
    'before acceptance a preissued business token lacks active membership');

// Distinct tenant for SAME actor must remain untouched.
$foreignOrg='1bbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$foreignMember='1ccccccc-cccc-4ccc-8ccc-cccccccccccc';
$foreignSession='1ddddddd-dddd-4ddd-8ddd-dddddddddddd';
$db->prepare(
    "INSERT INTO portal_organizations
    (id,reference,display_name,status,security_revision,created_at,updated_at)
    VALUES(?,?,'F118 other company','active',1,now(),now())"
)->execute([$foreignOrg,'f118-other-org']);
$db->prepare(
    "INSERT INTO portal_memberships
     (id,identity_id,organization_id,role,status,membership_revision,
      created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$foreignMember,$first['actor'],$foreignOrg]);
$extra($first,$foreignSession,'business','active',$foreignOrg);
$second=$create('2');
$ok($isPending($first),'fixtures start with a pending member and unused invitation');
$ok($claim($a,$first),'F118 atomically accepts invitation and retires all sessions');
$after=$state($first);
$ok($after['member']['status']==='active'
    &&(int)$after['member']['membership_revision']===2
    &&$after['invitation']==='consumed',
    'membership and invitation transition once');
$ok(count($after['sessions'])===5
    &&count(array_filter($after['sessions'],static fn($s)=>$s['status']==='revoked'))===5
    &&count(array_filter($after['sessions'],static fn($s)=>$s['revoked_at']===null))===0,
    'original, sibling, pending MFA, business and NULL-purpose tokens all retired');
$ok(count($after['events'])===6,
    'F117 two security events plus four F118 session.revoked events');
$codes=array_count_values(array_column($after['events'],'event_code'));
$ok(($codes['invitation.consumed']??0)===1
    &&($codes['membership.activated']??0)===1
    &&($codes['session.revoked']??0)===4,
    'exactly one event per retiring sibling and no duplicate claim event');
$ok(!$canBusiness($legacyBusinessDigest,$first),
    'preissued business bearer stays unauthorized AFTER membership activation');
$checkForeign=$db->prepare(
    'SELECT status FROM portal_auth_sessions WHERE id=?'
);
$checkForeign->execute([$foreignSession]);
$ok($checkForeign->fetchColumn()==='active',
    'same user other organization is not revoked');
$ok($isPending($second),'other user and other organization unaffected');
$ok(!$claim($b,$first),'replayed invitation or session cannot reactivate membership');
$ok(count($state($first)['events'])===6,'replay cannot forge more audit events');
$matched=$db->prepare(
    "SELECT id FROM portal_auth_sessions WHERE identity_id=? AND organization_id=?"
);
$matched->execute([$first['actor'],$first['org']]);
$revokedIds=array_map('strtolower',$matched->fetchAll(PDO::FETCH_COLUMN));
foreach($after['events'] as $e){
    if($e['event_code']!=='session.revoked')continue;
    $valid=false;
    foreach($revokedIds as $id){
        $expected=hash('sha256','portal.f118.session.revoked.v1:'.$id
            .':'.$e['event_id'].':'.$first['org'].':'.$first['actor']);
        if(hash_equals($expected,$e['evidence_sha256'])){$valid=true;break;}
    }
    $ok($valid,'session.revoked digest binds UUID and event without bearer');
}

// Audit failure for a SINGLE sibling must reverse the F117 invitation,
// membership change and original session revocation as well as sibling writes.
$extra($second,'26666666-6666-4666-8666-666666666666','business');
$db->exec(
    "ALTER POLICY portal_f118_session_event_append
       ON public.portal_security_events WITH CHECK (false)"
);
$ok(!$claim($a,$second),'F89 retirement audit failure prevents acceptance');
$ok($isPending($second),
    'failed audit rolls back F117 + all F118 session modifications');
$db->exec(
    "ALTER POLICY portal_f118_session_event_append
       ON public.portal_security_events
       WITH CHECK (event_code='session.revoked'
         AND identity_id IS NOT NULL AND security_revision>1)"
);
$ok($claim($b,$second),'after restoring audit, acceptance succeeds once');
$ok(count($state($second)['events'])===3,
    'one extra sibling receives exactly one append-only event');

$third=$create('3');
$extra($third,'36666666-6666-4666-8666-666666666666','business');
$db->exec(
    "ALTER POLICY portal_f118_session_retire
      ON public.portal_auth_sessions WITH CHECK (false)"
);
$ok(!$claim($a,$third),'failed session revoke policy prevents activation');
$ok($isPending($third),'F117 result rolled back when sibling UPDATE is denied');
$db->exec(
    "ALTER POLICY portal_f118_session_retire
       ON public.portal_auth_sessions WITH CHECK (
         status='revoked' AND revoked_at IS NOT NULL)"
);

// Two competing transactions cannot accept the same invitation concurrently.
$a->beginTransaction();
$ok($claim($a,$third),'first transaction retires all credentials without commit');
$ok($isPending($third),'changes are not visible outside first transaction');
$b->exec("SET lock_timeout='250ms'");
$ok(!$claim($b,$third),'competing acceptance cannot overtake locked membership');
$b->exec('RESET lock_timeout');
$a->rollBack();
$ok($isPending($third),'ROLLBACK of nested acceptance restores all credentials');
$ok($claim($b,$third),'second claimant can accept exactly once after rollback');
$ok(count($state($third)['events'])===3,
    'one successful event pair and one session retirement after rollback');

// More than 50 sibling sessions must fail CLOSED rather than partially retire.
$fourth=$create('4');
$bulk=$db->prepare(
    "INSERT INTO portal_auth_sessions
     (id,session_sha256,identity_id,organization_id,status,session_purpose,
      identity_revision,membership_revision,organization_revision,
      mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     SELECT gen_random_uuid(),
       encode(sha256(convert_to('f118-overflow-'||g::text,'UTF8')),'hex'),
       ?,?,'active','invitation_only',1,1,1,
       now()-interval '1 minute',now()-interval '10 minutes',
       now()+interval '20 minutes',now(),now()
     FROM generate_series(1,51) AS g"
);
$bulk->execute([$fourth['actor'],$fourth['org']]);
$ok(!$claim($a,$fourth),'more than 50 sibling sessions deny entire activation');
$ok($isPending($fourth),'overflow rolls back F117 claim and audit completely');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions
     WHERE organization_id='42222222-2222-4222-8222-222222222222'
       AND status='active'"
)->fetchColumn()===52,'overflow does not silently revoke an arbitrary subset');

$ok($no($db->query(
    "SELECT has_table_privilege('portal_f118_retirement_owner',
      'public.portal_identities','UPDATE')"
)->fetchColumn()),'retirement owner cannot edit identity data');

echo "PASS PORTAL-F118 {$n} atomic full session retirement, role isolation, multi-tenant rollback and audits\n";
