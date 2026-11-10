<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalEmailVerificationSecret;
use App\Domain\Identity\Invitations\PortalInvitationSecret;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F115_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F115 may only run on disposable local PG18.');
}
$adminPass=getenv('F66_PG_ADMIN_PASSWORD');
$probePass=getenv('F115_PG_PROBE_PASSWORD');
if (!is_string($adminPass)||strlen($adminPass)<12
    || !is_string($probePass)||strlen($probePass)<12) {
    throw new RuntimeException('F115 needs ephemeral runner-only credentials.');
}
$pdo=static fn(string $role,string $password):PDO=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db=$pdo('postgres',$adminPass);
$a=$pdo('portal_f115_email_probe',$probePass);
$b=$pdo('portal_f115_email_probe',$probePass);
$n=0;
$ok=static function(bool $yes,string $label)use(&$n):void{
    if(!$yes)throw new RuntimeException('PORTAL-F115 FAILED '.$label);
    ++$n;
};
$truth=static fn(mixed $v):bool=>in_array($v,[true,1,'t','1'],true);
$denySql=static function(callable $fn,string $state,string $label)use($ok):void{
    try{$fn();}catch(PDOException $e){$ok($e->getCode()===$state,$label);return;}
    $ok(false,$label.' unexpectedly accepted');
};
$role=$db->query(
    "SELECT rolcanlogin,rolsuper,rolbypassrls
     FROM pg_roles WHERE rolname='portal_f115_email_definer'"
)->fetch();
$ok(is_array($role)&&!$truth($role['rolcanlogin'])
    &&!$truth($role['rolsuper'])&&!$truth($role['rolbypassrls']),
    'SQL definer is NOLOGIN, NOSUPERUSER and NOBYPASSRLS');
$permissions=$db->query(
    "SELECT
      has_function_privilege('portal_f115_email_probe',
       'public.portal_f115_confirm_email(text,uuid)'::regprocedure,'EXECUTE') AS execute_fn,
      has_table_privilege('portal_f115_email_probe',
       'public.portal_identities','SELECT') AS read_id,
      has_table_privilege('portal_f115_email_probe',
       'public.portal_email_verification_challenges','UPDATE') AS write_challenge,
      has_column_privilege('portal_f115_email_definer',
       'public.portal_identities','password_hash','SELECT') AS password_access,
      has_column_privilege('portal_f115_email_definer',
       'public.portal_identities','mfa_secret_ciphertext','SELECT') AS mfa_access"
)->fetch();
$ok($truth($permissions['execute_fn'])&&!$truth($permissions['read_id'])
    &&!$truth($permissions['write_challenge'])
    &&!$truth($permissions['password_access'])
    &&!$truth($permissions['mfa_access']),
    'probe EXECUTE only and definer cannot read password/MFA material');
$rels=$db->query(
    "SELECT relname,relrowsecurity,relforcerowsecurity
       FROM pg_class
      WHERE oid IN ('public.portal_identities'::regclass,
       'public.portal_email_verification_challenges'::regclass)"
)->fetchAll();
$ok(count($rels)===2&&count(array_filter($rels,
    static fn($r)=>$truth($r['relrowsecurity'])&&$truth($r['relforcerowsecurity'])
))===2,'both identity and challenge enforce FORCE RLS');
$policies=$db->query(
    "SELECT tablename,roles FROM pg_policies WHERE schemaname='public'
      AND policyname LIKE 'portal_f115_%'"
)->fetchAll();
$ok(count($policies)===4&&count(array_filter($policies,
    static fn($p)=>$p['roles']==='{portal_f115_email_definer}'
))===4,'RLS policies scoped strictly to technical NOLOGIN owner');

$identities=[
 'f1111111-1111-4111-8111-111111111111'=>'f115-a@example.invalid',
 'f2222222-2222-4222-8222-222222222222'=>'f115-b@example.invalid',
 'f3333333-3333-4333-8333-333333333333'=>'f115-c@example.invalid',
 'f4444444-4444-4444-8444-444444444444'=>'f115-d@example.invalid'
];
$ins=$db->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,status,security_revision,created_at,updated_at)
     VALUES(?,?,'invited',1,now(),now())"
);
foreach ($identities as $id=>$email)$ins->execute([$id,$email]);
$ids=array_keys($identities);
$sequence=0;
$issue=static function(
    string $id,string $email,string $issuedOffset='-1 minute',
    string $expiresOffset='+15 minutes'
)use($db,&$sequence):array{
    $sequence++;
    $secret=PortalEmailVerificationSecret::mint();
    $bearer=$secret->takeDeliverySecretOnce();
    if(!is_string($bearer))throw new RuntimeException('missing lab email bearer');
    $uuid='f5555555-5555-4555-8555-'.str_pad((string)$sequence,12,'0',STR_PAD_LEFT);
    // The intervals below are controlled CI fixtures, not user input.
    $stmt=$db->prepare(
        "INSERT INTO portal_email_verification_challenges(
           id,token_sha256,identity_id,email_sha256,identity_revision,
           status,issued_at,expires_at,created_at,updated_at
         ) VALUES(?,?,?,?,1,'pending',
           clock_timestamp()+CAST(? AS interval),
           clock_timestamp()+CAST(? AS interval),now(),now())"
    );
    $stmt->execute([
        $uuid,$secret->digest(),$id,hash('sha256',$email),
        $issuedOffset,$expiresOffset,
    ]);
    return [$uuid,$secret->digest(),$bearer];
};
$confirm=static function(PDO $conn,string $digest,string $id)use($truth):bool{
    $stmt=$conn->prepare(
        'SELECT public.portal_f115_confirm_email(:digest,CAST(:identity AS uuid))'
    );
    $stmt->execute(['digest'=>$digest,'identity'=>$id]);
    return $truth($stmt->fetchColumn());
};
$verified=static function(string $id)use($db):bool{
    $query=$db->prepare('SELECT email_verified_at IS NOT NULL FROM portal_identities WHERE id=?');
    $query->execute([$id]);
    return in_array($query->fetchColumn(),[true,'t','1',1],true);
};
$state=static function(string $uuid)use($db):string{
    $stmt=$db->prepare('SELECT status FROM portal_email_verification_challenges WHERE id=?');
    $stmt->execute([$uuid]);
    return (string)$stmt->fetchColumn();
};
$count=static fn():int=>(int)$db->query(
    "SELECT count(*) FROM portal_email_verification_challenges
       WHERE status='consumed'"
)->fetchColumn();

[$id1,$digest1,$bearer1]=$issue($ids[0],$identities[$ids[0]]);
$ok(PortalEmailVerificationSecret::matchesCandidate($bearer1,$digest1),
    'valid canonical delivery token matches challenge digest');
$ok(!PortalInvitationSecret::matchesCandidate($bearer1,$digest1),
    'invitation and email proof token domains never overlap');
$ok(!$confirm($a,$digest1,$ids[1]),'challenge cannot verify other identity');
$ok(!$confirm($a,PortalInvitationSecret::digestCandidate($bearer1),$ids[0]),
    'invitation digest cannot satisfy email challenge');
$ok(!$confirm($a,'bad-digest',$ids[0]),'invalid digest denied');
$ok(!$verified($ids[0])&&$state($id1)==='pending',
    'negative attempts do not change email verification');

$secretDup=PortalEmailVerificationSecret::mint();
$denySql(static fn()=> $db->prepare(
    "INSERT INTO portal_email_verification_challenges(
       id,token_sha256,identity_id,email_sha256,identity_revision,
       issued_at,expires_at,created_at,updated_at
     ) VALUES(?,?,?,?,1,now()-interval '1 minute',
       now()+interval '10 minutes',now(),now())"
)->execute([
    'f6666666-6666-4666-8666-666666666666',
    $secretDup->digest(),$ids[0],hash('sha256',$identities[$ids[0]])
]),'23505','one outstanding challenge per identity');

$a->beginTransaction();
$ok($confirm($a,$digest1,$ids[0]),'first transaction verifies email once');
$ok(!$verified($ids[0])&&$count()===0,
    'uncommitted change invisible to independent connection');
$b->exec("SET lock_timeout='250ms'");
$ok(!$confirm($b,$digest1,$ids[0]),
    'concurrent claim cannot overtake first locked identity');
$a->commit();
$ok(!$confirm($b,$digest1,$ids[0]),'post-commit replay denied');
$b->exec('RESET lock_timeout');
$ok($verified($ids[0])&&$state($id1)==='consumed'&&$count()===1,
    'exactly one challenge and verification timestamp persisted');
$ok($db->query(
    "SELECT status FROM portal_identities WHERE id='$ids[0]'"
)->fetchColumn()==='invited','email proof does not activate invited identity');

[$id2,$digest2]=$issue($ids[1],$identities[$ids[1]]);
$a->beginTransaction();
$ok($confirm($a,$digest2,$ids[1]),'another proof may execute in transaction');
$a->rollBack();
$ok(!$verified($ids[1])&&$state($id2)==='pending'&&$count()===1,
    'explicit rollback undoes both identity stamp and challenge state');
$ok($confirm($b,$digest2,$ids[1]),'different client may claim after rollback');
$ok($count()===2&&$verified($ids[1]),
    'exactly one verified email event after rollback recovery');

[$id3,$digest3]=$issue($ids[2],$identities[$ids[2]]);
$db->exec(
    "UPDATE portal_identities SET email_normalized='f115-changed@example.invalid'
     WHERE id='$ids[2]'"
);
$ok(!$confirm($a,$digest3,$ids[2])&&!$verified($ids[2]),
    'changed target email invalidates original email delivery proof');
$db->exec(
    "UPDATE portal_identities SET email_normalized='f115-c@example.invalid',
      security_revision=2 WHERE id='$ids[2]'"
);
$ok(!$confirm($a,$digest3,$ids[2]),
    'identity revision change rejects stale email challenge');
$db->exec(
    "UPDATE portal_identities SET security_revision=1 WHERE id='$ids[2]'"
);
$db->exec(
    "ALTER POLICY portal_f115_challenge_update
      ON public.portal_email_verification_challenges WITH CHECK (false)"
);
$ok(!$confirm($a,$digest3,$ids[2])&&!$verified($ids[2])
    &&$state($id3)==='pending',
    'audit-like challenge write failure rolls back email timestamp');
$db->exec(
    "ALTER POLICY portal_f115_challenge_update
      ON public.portal_email_verification_challenges
      WITH CHECK (status='consumed' AND consumed_at IS NOT NULL)"
);
$ok($confirm($a,$digest3,$ids[2]),'restoring scoped RLS permits one proof');
$ok($verified($ids[2])&&$count()===3,
    'atomic identity stamp and proof consumption restored');

[$id4,$digest4]=$issue(
    $ids[3],$identities[$ids[3]],'-20 minutes','-5 minutes'
);
$ok(!$confirm($a,$digest4,$ids[3]),'expired email challenge denied');
$db->exec(
    "UPDATE portal_email_verification_challenges
       SET status='revoked',revoked_at=clock_timestamp() WHERE id='$id4'"
);
$ok(!$confirm($a,$digest4,$ids[3]),'revoked challenge denied');
[$future,$futureDigest]=$issue(
    $ids[3],$identities[$ids[3]],'+5 minutes','+15 minutes'
);
$ok(!$confirm($a,$futureDigest,$ids[3]),'future-issued email challenge denied');
$ok(!$verified($ids[3])&&$count()===3,
    'expired, revoked and future challenges never stamp email');
$db->exec(
    "UPDATE portal_email_verification_challenges SET status='revoked',
      revoked_at=issued_at WHERE id='$future'"
);

$deniedInsert=$db->prepare(
    "INSERT INTO portal_email_verification_challenges
      (id,token_sha256,identity_id,email_sha256,identity_revision,
       status,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?,1,'pending',now(),now()+interval '31 minutes',now(),now())"
);
$denySql(static fn()=> $deniedInsert->execute([
    'f7777777-7777-4777-8777-777777777777',
    hash('sha256','invalid-expiry'),$ids[3],
    hash('sha256',$identities[$ids[3]])
]),'23514','challenge TTL over 30 minutes rejected');
$ok(!$verified($ids[3]),'invalid challenge cannot verify email');

$ok((int)$db->query(
    "SELECT count(*) FROM portal_auth_sessions"
)->fetchColumn()===0,'email proof never issues login sessions');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_membership_grants"
)->fetchColumn()===0,'email proof never issues service grants');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_memberships WHERE status='active'"
)->fetchColumn()===0,'email proof never activates organization membership');
$ok((int)$db->query(
    "SELECT count(*) FROM portal_identities WHERE status='active'"
)->fetchColumn()===0,'email proof never activates an account');

echo "PASS PORTAL-F115 {$n} email-only atomic proof, concurrency, replay, expiry, RLS and rollback checks\n";
