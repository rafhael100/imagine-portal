<?php
declare(strict_types=1);

/**
 * F113 disposable PostgreSQL18 test: a callback cannot overtake a
 * cooperating tenant/session revoker holding the same advisory lock.
 */
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthRlsLabBoundary;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use App\Domain\SeoReview\PortalSeoPgOAuthSessionBoundLab;
use App\Domain\SeoReview\PortalSeoPgSessionAdvisoryLockLab;
use App\Domain\SeoReview\PortalSeoPgSessionRevocationLab;

require_once __DIR__.'/../../vendor/autoload.php';
function assert113(bool $check,string $reason):void{
    if(!$check)throw new RuntimeException('F113 synthetic PG18: '.$reason);
}
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_SEO_F113_PG_CI')!=='1'||
    getenv('PORTAL_SEO_F112_PG_CI')!=='1'||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
    getenv('DB_HOST')!=='127.0.0.1'||
    getenv('POSTGRES_PASSWORD')!=='f108-ci-postgres-only'||
    getenv('F108_ROLE_PASSWORD')!=='f108-ci-role-only')
    throw new RuntimeException('F113 requires disposable PG18 CI');

function connection113(string $user,string $password):PDO{
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
$admin=connection113('postgres',getenv('POSTGRES_PASSWORD'));
$tenant='demo-tenant';$actor='portal-f113-a';
$now=1800000000;
$property='sc-domain:example.com';
$fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint($property);
assert113(is_string($fingerprint),'invalid synthetic property');
$key=random_bytes(32);$oauthKey=random_bytes(32);
$handle=fn():string=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$session=$handle();
$resource=$handle();
$sessionDigest=hash_hmac('sha256',
    'portal-session-review-v1|'.$tenant.'|'.$session,$key);
$resourceDigest=hash_hmac('sha256',
    'portal-seo-resource-review-v1|'.$tenant.'|'.$resource,$key);

// Seed by privileged CI setup ONLY. Neither browser nor read role can insert.
$add=$admin->prepare('INSERT INTO portal_seo_review_sessions
    (tenant_ref,session_digest,actor_ref,issued_at,expires_at,mfa_verified_at,
     revoked_at,account_revision,session_revision)
    VALUES (:tenant,:digest,:actor,:issued,:expires,:mfa,NULL,2,2)');
$add->execute([
    'tenant'=>$tenant,'digest'=>$sessionDigest,'actor'=>$actor,
    'issued'=>$now-300,'expires'=>$now+600,'mfa'=>$now-30
]);
$add=$admin->prepare('INSERT INTO portal_seo_review_memberships
    (tenant_ref,actor_ref,status,permission,checked_at,revoked_at)
    VALUES (:tenant,:actor,:status,:permission,:at,NULL)');
$add->execute([
    'tenant'=>$tenant,'actor'=>$actor,'status'=>'active',
    'permission'=>'seo.view','at'=>$now-30
]);
$add=$admin->prepare('INSERT INTO portal_seo_review_resources
    (tenant_ref,resource_digest,property_fingerprint,status,updated_at)
    VALUES (:tenant,:digest,:property,:status,:at)');
$add->execute([
    'tenant'=>$tenant,'digest'=>$resourceDigest,'property'=>$fingerprint,
    'status'=>'active','at'=>$now-20
]);
$add=$admin->prepare('INSERT INTO portal_gsc_property_grants
    (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
     verified_at,expires_at,revoked_at)
    VALUES (:tenant,:actor,:property,:scope,:status,:verified,:expires,NULL)');
$add->execute([
    'tenant'=>$tenant,'actor'=>$actor,'property'=>$fingerprint,
    'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
    'status'=>'active','verified'=>$now-20,'expires'=>$now+600
]);

// Dedicated test-only revoker: no superuser/BYPASSRLS and only
// SELECT + UPDATE on revoked_at, never INSERT/UPDATE sessions otherwise.
$admin->exec("CREATE ROLE portal_f113_revoker LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f108-ci-role-only'");
$admin->exec('GRANT USAGE ON SCHEMA public TO portal_f113_revoker');
$admin->exec('GRANT SELECT ON portal_seo_review_sessions TO portal_f113_revoker');
$admin->exec('GRANT UPDATE(revoked_at)
    ON portal_seo_review_sessions TO portal_f113_revoker');

$reader=connection113('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$writer=connection113('portal_f113_revoker',getenv('F108_ROLE_PASSWORD'));
foreach([$reader,$writer] as $db){
    $status=$db->query('SELECT rolsuper,rolbypassrls FROM pg_roles
        WHERE rolname=current_user')->fetch(PDO::FETCH_ASSOC);
    assert113(is_array($status)&&in_array($status['rolsuper'],[false,'f'],true)
        &&in_array($status['rolbypassrls'],[false,'f'],true),
        'SQL role has BYPASSRLS');
}
$preflight=new GoogleSearchConsoleOAuthPreflight();
$ctx=['tenantRef'=>$tenant,'actorRef'=>$actor];
$prepared=$preflight->prepare([
    'clientId'=>'123456789-demo-app.apps.googleusercontent.com',
    'registeredRedirectUri'=>
        'https://portal.example.com/integrations/google/search-console/callback'
],$ctx,$now);
assert113(is_array($prepared),'synthetic state preflight failed');
$pending=new GoogleSearchConsoleOAuthRlsLabBoundary($reader,$oauthKey);
assert113($pending->storePending($ctx,$prepared['serverOnly'],$now),
    'could not stage synthetic encrypted PKCE');
$state=$prepared['publicAuthorization']['state'];
$callback=['state'=>$state,'code'=>'synthetic-code-2026'];
$handler=new PortalSeoPgOAuthSessionBoundLab($reader,$key,$oauthKey);
$revoker=new PortalSeoPgSessionRevocationLab($writer,$key);
$consumed=function()use($admin,$state):bool{
    $stmt=$admin->prepare('SELECT consumed_at FROM portal_gsc_oauth_states
        WHERE state_hash=:digest');
    $stmt->execute(['digest'=>hash('sha256',$state)]);
    return $stmt->fetchColumn()!==null;
};
assert113(!$consumed(),'PKCE was already consumed');

// Connection A takes a cooperative tenant/session lock, as an official
// revoker must do. Connection B attempts callback and fails due to
// lock_timeout, without consuming OAuth state or granting access.
$writer->beginTransaction();
try{
    $scope=$writer->prepare("SELECT set_config('app.tenant_ref',:tenant,true)");
    $scope->execute(['tenant'=>$tenant]);
    assert113($scope->fetchColumn()===$tenant,'revoker scope failed');
    assert113(PortalSeoPgSessionAdvisoryLockLab::acquire(
        $writer,$tenant,$sessionDigest),'revoker could not lock session');

    $reader->exec("SET lock_timeout TO '400ms'");
    assert113($handler->reviewAndConsume(
        $tenant,$session,$resource,$property,$callback,$now+5
    )===null,'callback overtook revoker holding the lock');
    assert113(!$consumed(),'state consumed during denied lock contention');

    // A's trusted revocation writes within the SAME lock transaction.
    $revocation=$writer->prepare('UPDATE portal_seo_review_sessions
        SET revoked_at=:now
        WHERE tenant_ref=:tenant AND session_digest=:digest
        AND revoked_at IS NULL RETURNING session_digest');
    $revocation->execute([
        'now'=>$now,'tenant'=>$tenant,'digest'=>$sessionDigest
    ]);
    assert113($revocation->fetchColumn()===$sessionDigest,
        'locked revocation did not update exactly one session');
    $writer->commit();
}catch(Throwable $err){
    if($writer->inTransaction())$writer->rollBack();
    throw $err;
}
$reader->exec("SET lock_timeout TO '2s'");
assert113($handler->reviewAndConsume(
    $tenant,$session,$resource,$property,$callback,$now+6
)===null,'revoked session consumed state after lock released');
assert113(!$consumed(),'revoked session should retain unconsumed state');

// Reset only the disposable fixture, then test the official F113 lab
// revoker's one-use transaction and cross-tenant denial. Production
// never permits fixture reset/unrevocation via this class.
$reset=$admin->prepare('UPDATE portal_seo_review_sessions SET revoked_at=NULL
    WHERE tenant_ref=:tenant AND session_digest=:digest');
$reset->execute(['tenant'=>$tenant,'digest'=>$sessionDigest]);
assert113(!$revoker->revoke('other-tenant',$session,$now),
    'revoker crossed tenant boundary');
assert113($revoker->revoke($tenant,$session,$now),
    'coordinated one-use revoker failed');
assert113(!$revoker->revoke($tenant,$session,$now),
    'revocation replay succeeded');
assert113($handler->reviewAndConsume(
    $tenant,$session,$resource,$property,$callback,$now+8
)===null,'revoked session accepted PKCE again');
assert113(!$consumed(),'failed callback consumed state after revocation');

assert113(!PortalSeoPgSessionAdvisoryLockLab::authorizesProduction()
    && !PortalSeoPgSessionRevocationLab::authorizesRevokingRealSessions()
    && !PortalSeoPgSessionRevocationLab::authorizesCustomerDisplay(),
    'lab revoker claims production permission');
putenv('APP_ENV=production');
assert113(!$revoker->revoke($tenant,$session,$now),
    'production revocation unexpectedly enabled');

echo "SEO F113 PG18: cooperative session advisory lock prevents callback\n".
     "overtaking revocation; timeout leaves PKCE state pending;\n".
     "revoked session denied, one-use scoped writer tested, no real issuer\n";
