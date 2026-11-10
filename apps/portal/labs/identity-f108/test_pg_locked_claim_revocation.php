<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalInvitationSecret;

// All identities, organizations, locks, and tokens below are disposable.
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F108_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F108 only runs in disposable local PostgreSQL 18.');
}
$adminPass=getenv('F66_PG_ADMIN_PASSWORD');
$probePass=getenv('F106_PG_PROBE_PASSWORD');
if (!is_string($adminPass) || strlen($adminPass)<12
    || !is_string($probePass) || strlen($probePass)<12) {
    throw new RuntimeException('F108 needs ephemeral CI credentials.');
}
$connect=static fn(string $role,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$admin=$connect('postgres',$adminPass);
$callerA=$connect('portal_f106_invitation_probe',$probePass);
$callerB=$connect('portal_f106_invitation_probe',$probePass);
$writer=$connect('postgres',$adminPass);
$n=0;
$ok=static function(bool $value,string $why)use(&$n):void{
    if(!$value) throw new RuntimeException('PORTAL-F108 FAILED '.$why);
    $n++;
};
$isTrue=static fn(mixed $x):bool=>in_array($x,[true,1,'t','1'],true);
$isFalse=static fn(mixed $x):bool=>in_array($x,[false,0,'f','0'],true);

$roles=$admin->query(
    "SELECT rolcanlogin,rolsuper,rolbypassrls
       FROM pg_roles WHERE rolname='portal_f106_invitation_definer'"
)->fetch();
$ok(is_array($roles) && $isFalse($roles['rolcanlogin'])
    && $isFalse($roles['rolsuper']) && $isFalse($roles['rolbypassrls']),
    'definer is NOLOGIN NOSUPERUSER NOBYPASSRLS');

$can=$admin->query(
    "SELECT
      has_function_privilege('portal_f106_invitation_probe',
        'public.portal_f108_locked_claim_with_audit(text,uuid,uuid)'::regprocedure,
        'EXECUTE') AS locked,
      has_function_privilege('portal_f106_invitation_probe',
        'public.portal_f107_claim_with_audit(text,uuid,uuid)'::regprocedure,
        'EXECUTE') AS bypass_f107,
      has_function_privilege('portal_f106_invitation_probe',
        'public.portal_f106_claim_invitation(text,uuid,uuid)'::regprocedure,
        'EXECUTE') AS bypass_f106"
)->fetch();
$ok($isTrue($can['locked']) && $isFalse($can['bypass_f107'])
    && $isFalse($can['bypass_f106']),
    'caller can only invoke lock-protected and audited entrypoint');
$tables=['portal_identities','portal_organizations','portal_memberships'];
foreach($tables as $table){
    $stmt=$admin->prepare(
        "SELECT has_column_privilege(
           'portal_f106_invitation_definer',:table,'status','UPDATE'
         ) AS lock_privilege,
         has_column_privilege(
           'portal_f106_invitation_probe',:table,'status','UPDATE'
         ) AS caller_privilege"
    );
    $stmt->execute(['table'=>'public.'.$table]);
    $row=$stmt->fetch();
    $ok($isTrue($row['lock_privilege'])
        && $isFalse($row['caller_privilege']),
        'only NOLOGIN definer receives minimum lock privilege for '.$table);
    $stmt=$admin->prepare(
        "SELECT policyname,roles,cmd,qual,with_check
           FROM pg_policies WHERE schemaname='public'
           AND tablename=:table AND cmd IN ('UPDATE','ALL')"
    );
    $stmt->execute(['table'=>$table]);
    $policies=$stmt->fetchAll();
    $ok(count($policies)===1
        && $policies[0]['roles']==='{portal_f106_invitation_definer}'
        && $policies[0]['cmd']==='UPDATE'
        && trim((string)$policies[0]['qual'],'() ')==='true'
        && trim((string)$policies[0]['with_check'],'() ')==='false',
        'FOR SHARE can see rows but UPDATE WITH CHECK(false) denies writing '.$table);
}

$identity='b1111111-1111-4111-8111-111111111111';
$org='b2222222-2222-4222-8222-222222222222';
$foreign='b3333333-3333-4333-8333-333333333333';
$membership='b4444444-4444-4444-8444-444444444444';
$admin->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
       security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',1,now(),now())"
)->execute([$identity,'f108@example.invalid']);
$orgInsert=$admin->prepare(
    "INSERT INTO portal_organizations
      (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$orgInsert->execute([$org,'f108-home','F108 synthetic tenant']);
$orgInsert->execute([$foreign,'f108-away','F108 unrelated tenant']);
$admin->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','invited',1,now(),now())"
)->execute([$membership,$identity,$org]);

$sequence=0;
$issue=static function(int $identityRev,int $membershipRev,int $orgRev)
    use(&$sequence,$admin,$identity,$org):array{
    $sequence++;
    $secret=PortalInvitationSecret::mint();
    $bearer=$secret->takeDeliverySecretOnce();
    if(!is_string($bearer))throw new RuntimeException('missing fake bearer');
    $uuid='b5555555-5555-4555-8555-'
        .str_pad((string)$sequence,12,'0',STR_PAD_LEFT);
    $stmt=$admin->prepare(
        "INSERT INTO portal_access_invitations(
           id,token_sha256,identity_id,organization_id,
           identity_revision,membership_revision,organization_revision,
           status,issued_at,expires_at,created_at,updated_at
         ) VALUES(?,?,?,?,?,?,?,'pending',now()-interval '1 minute',
           now()+interval '1 day',now(),now())"
    );
    $stmt->execute([
        $uuid,$secret->digest(),$identity,$org,
        $identityRev,$membershipRev,$orgRev,
    ]);
    return [$uuid,$secret->digest()];
};
$claim=static function(PDO $db,string $digest,string $actor,string $tenant)
    use($isTrue):bool{
    $stmt=$db->prepare(
        'SELECT public.portal_f108_locked_claim_with_audit(:digest,'
        .'CAST(:actor AS uuid),CAST(:tenant AS uuid))'
    );
    $stmt->execute(['digest'=>$digest,'actor'=>$actor,'tenant'=>$tenant]);
    return $isTrue($stmt->fetchColumn());
};
$auditCount=static fn():int=>(int)$admin->query(
    "SELECT count(*) FROM portal_security_events WHERE event_code='invitation.consumed'"
)->fetchColumn();
$pending=static function(string $uuid)use($admin,$isTrue):bool{
    $stmt=$admin->prepare(
        "SELECT status='pending' FROM portal_access_invitations WHERE id=?"
    );
    $stmt->execute([$uuid]);
    return $isTrue($stmt->fetchColumn());
};

$baselineAuditCount=$auditCount();
[$id1,$digest1]=$issue(1,1,1);
$ok(!$claim($callerA,$digest1,$identity,$foreign),
    'selected foreign tenant does not match membership');
$ok(!$claim($callerA,$digest1,$foreign,$org),
    'unrelated actor cannot acquire invitation');
$ok($pending($id1) && $auditCount()===$baselineAuditCount,
    'invalid claims do not change invitation or audit');

// Writer changes identity revision but leaves transaction uncommitted.
// Claim cannot read a stale identity revision and succeed under this lock.
$writer->beginTransaction();
$writer->exec(
    "UPDATE portal_identities SET security_revision=2 WHERE id='$identity'"
);
$callerA->exec("SET lock_timeout='250ms'");
$ok(!$claim($callerA,$digest1,$identity,$org),
    'claim cannot overtake an uncommitted identity revocation');
$ok($pending($id1) && $auditCount()===$baselineAuditCount,
    'timeout fails closed without token or audit change');
$writer->commit();
$ok(!$claim($callerA,$digest1,$identity,$org),
    'committed identity revision makes prior invitation stale');
$callerA->exec('RESET lock_timeout');
$writer->exec(
    "UPDATE portal_identities SET security_revision=1 WHERE id='$identity'"
);
$ok($claim($callerA,$digest1,$identity,$org),
    'lab-only restored revision can be claimed and audited once');
$ok(!$claim($callerB,$digest1,$identity,$org),
    'same token cannot be reused by a second PDO client');
$ok($auditCount()===$baselineAuditCount+1,'first valid claim creates one audit event');

// Conversely, a valid claim holding FOR SHARE on the membership blocks
// a concurrent revision writer until the claim's own commit/rollback.
[$id2,$digest2]=$issue(1,1,1);
$callerA->beginTransaction();
$ok($claim($callerA,$digest2,$identity,$org),
    'first claimant obtains membership lock and audited invitation claim');
$writer->exec("SET lock_timeout='250ms'");
try {
    $writer->exec(
        "UPDATE portal_memberships SET membership_revision=2 WHERE id='$membership'"
    );
    $ok(false,'revocation writer must not overtake locked claim');
} catch(PDOException $e) {
    $ok($e->getCode()==='55P03',
        'membership revision writer waits for locked claim');
}
$writer->exec('RESET lock_timeout');
$ok($auditCount()===$baselineAuditCount+1,
    'uncommitted claim/audit remains invisible outside the transaction');
$callerA->commit();
$ok($auditCount()===$baselineAuditCount+2 && !$pending($id2),
    'committing claim saves one token transition plus audit event');
$writer->exec(
    "UPDATE portal_memberships SET membership_revision=2 WHERE id='$membership'"
);
$ok(!$claim($callerB,$digest2,$identity,$org),
    'subsequent revocation cannot replay already consumed token');

// A pending invitation with an old membership revision is refused.
[$id3,$digest3]=$issue(1,1,1);
$ok(!$claim($callerB,$digest3,$identity,$org),
    'committed membership revision invalidates an old invitation');
$ok($pending($id3),'stale invitation remains pending for manual revoke');
$writer->exec(
    "UPDATE portal_access_invitations
     SET status='revoked',revoked_at=now() WHERE id='$id3'"
);
$writer->exec(
    "UPDATE portal_memberships SET membership_revision=1 WHERE id='$membership'"
);

// Organization revocation before a claim must be respected too.
[$id4,$digest4]=$issue(1,1,1);
$writer->beginTransaction();
$writer->exec(
    "UPDATE portal_organizations SET status='suspended' WHERE id='$org'"
);
$callerB->exec("SET lock_timeout='250ms'");
$ok(!$claim($callerB,$digest4,$identity,$org),
    'claim cannot pass a concurrent organization suspension');
$writer->commit();
$ok(!$claim($callerB,$digest4,$identity,$org),
    'committed organization suspension remains denied');
$callerB->exec('RESET lock_timeout');
$ok($pending($id4) && $auditCount()===$baselineAuditCount+2,
    'denied claims cannot append security events');
$writer->exec(
    "UPDATE portal_organizations SET status='active' WHERE id='$org'"
);

// Even though the NOLOGIN owner got UPDATE(status) for FOR SHARE, FORCE RLS
// with no UPDATE policy must prevent it from changing security states.
$admin->beginTransaction();
try {
    $admin->exec('SET LOCAL ROLE portal_f106_invitation_definer');
    $admin->exec(
        "UPDATE portal_identities SET status='revoked' WHERE id='$identity'"
    );
    $ok(false,'definer must not pass UPDATE WITH CHECK(false)');
} catch(PDOException $e) {
    $ok($e->getCode()==='42501',
        'definer receives RLS policy violation when attempting identity UPDATE');
} finally {
    if($admin->inTransaction()) $admin->rollBack();
}
$ok((string)$admin->query(
    "SELECT status FROM portal_identities WHERE id='$identity'"
)->fetchColumn()==='active', 'identity status unchanged after impersonated role');

$ok((int)$admin->query(
    "SELECT count(*) FROM portal_auth_sessions WHERE identity_id='$identity'"
)->fetchColumn()===0,'locked claims never issue sessions');
$ok((int)$admin->query(
    "SELECT count(*) FROM portal_memberships
      WHERE identity_id='$identity' AND organization_id='$org' AND status='active'"
)->fetchColumn()===0,'locked claims never activate membership');

echo "PASS PORTAL-F108 {$n} locked identity, membership and organization claim/revocation checks\n";
