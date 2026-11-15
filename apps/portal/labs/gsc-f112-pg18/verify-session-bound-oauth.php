<?php
declare(strict_types=1);

/** F112: real PostgreSQL18 one-transaction Portal session + PKCE callback lab. */
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthRlsLabBoundary;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use App\Domain\SeoReview\PortalSeoPgOAuthSessionBoundLab;

require_once __DIR__.'/../../vendor/autoload.php';

function check112(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('F112 synthetic PG18: '.$reason);
}
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_SEO_F110_PG_CI')!=='1'||
    getenv('PORTAL_SEO_F111_PG_CI')!=='1'||
    getenv('PORTAL_SEO_F112_PG_CI')!=='1'||
    getenv('DB_HOST')!=='127.0.0.1'||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
    getenv('POSTGRES_PASSWORD')!=='f108-ci-postgres-only'||
    getenv('F108_ROLE_PASSWORD')!=='f108-ci-role-only')
    throw new RuntimeException('F112 requires disposable GitHub PostgreSQL CI');

function pg112(string $user,string $password):PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
$admin=pg112('postgres',getenv('POSTGRES_PASSWORD'));
$db=pg112('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$otherDb=pg112('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$now=1800000000;
$site='sc-domain:example.com';
$fp=GoogleSearchConsolePropertyIdentifier::fingerprint($site);
check112(is_string($fp),'site mismatch');
$sessionKey=random_bytes(32);
$oauthKey=random_bytes(32);
$h=function():string {
    return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
};
$sessionA=$h();$resourceA=$h();
$sessionB=$h();$resourceB=$h();
$tenantA=['tenantRef'=>'demo-tenant','actorRef'=>'portal-sec-a'];
$tenantB=['tenantRef'=>'other-tenant','actorRef'=>'portal-sec-b'];
$config=[
    'clientId'=>'123456789-demo-app.apps.googleusercontent.com',
    'registeredRedirectUri'=>
        'https://portal.example.com/integrations/google/search-console/callback'
];

function insertFixture112(PDO $db,array $ctx,string $session,string $resource,
    string $key,string $fingerprint,int $now):void {
    $tenant=$ctx['tenantRef'];$actor=$ctx['actorRef'];
    $hash=hash_hmac('sha256',
        'portal-session-review-v1|'.$tenant.'|'.$session,$key);
    $resourceHash=hash_hmac('sha256',
        'portal-seo-resource-review-v1|'.$tenant.'|'.$resource,$key);
    $sessionStmt=$db->prepare('INSERT INTO portal_seo_review_sessions
        (tenant_ref,session_digest,actor_ref,issued_at,expires_at,
         mfa_verified_at,revoked_at,account_revision,session_revision)
        VALUES (:tenant,:hash,:actor,:issued,:expires,:mfa,NULL,3,3)');
    $sessionStmt->execute([
        'tenant'=>$tenant,'hash'=>$hash,'actor'=>$actor,
        'issued'=>$now-300,'expires'=>$now+600,'mfa'=>$now-20
    ]);
    $member=$db->prepare('INSERT INTO portal_seo_review_memberships
        (tenant_ref,actor_ref,status,permission,checked_at,revoked_at)
        VALUES (:tenant,:actor,:status,:permission,:checked,NULL)');
    $member->execute([
        'tenant'=>$tenant,'actor'=>$actor,'status'=>'active',
        'permission'=>'seo.view','checked'=>$now-30
    ]);
    $mapping=$db->prepare('INSERT INTO portal_seo_review_resources
        (tenant_ref,resource_digest,property_fingerprint,status,updated_at)
        VALUES (:tenant,:digest,:property,:status,:updated)');
    $mapping->execute([
        'tenant'=>$tenant,'digest'=>$resourceHash,'property'=>$fingerprint,
        'status'=>'active','updated'=>$now-60
    ]);
    $grant=$db->prepare('INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
         verified_at,expires_at,revoked_at)
        VALUES (:tenant,:actor,:property,:scope,:status,:verified,:expiry,NULL)');
    $grant->execute([
        'tenant'=>$tenant,'actor'=>$actor,'property'=>$fingerprint,
        'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
        'status'=>'active','verified'=>$now-30,'expiry'=>$now+600
    ]);
}
insertFixture112($admin,$tenantA,$sessionA,$resourceA,$sessionKey,$fp,$now);
insertFixture112($admin,$tenantB,$sessionB,$resourceB,$sessionKey,$fp,$now);
$preflight=new GoogleSearchConsoleOAuthPreflight();
$stateSourceA=new GoogleSearchConsoleOAuthRlsLabBoundary($db,$oauthKey);
$stateSourceB=new GoogleSearchConsoleOAuthRlsLabBoundary($otherDb,$oauthKey);
$callbackSourceA=new PortalSeoPgOAuthSessionBoundLab($db,$sessionKey,$oauthKey);
$callbackSourceB=new PortalSeoPgOAuthSessionBoundLab($otherDb,$sessionKey,$oauthKey);

function state112(GoogleSearchConsoleOAuthPreflight $preflight,
    GoogleSearchConsoleOAuthRlsLabBoundary $vault,array $config,
    array $context,int $now):string {
    $p=$preflight->prepare($config,$context,$now);
    check112(is_array($p),'state preflight failed');
    check112($vault->storePending($context,$p['serverOnly'],$now),
        'state persistence failed');
    return $p['publicAuthorization']['state'];
}
function consumed112(PDO $admin,string $state):?int {
    $r=$admin->prepare('SELECT consumed_at FROM portal_gsc_oauth_states
        WHERE state_hash=:hash');
    $r->execute(['hash'=>hash('sha256',$state)]);
    $t=$r->fetchColumn();
    return $t===false || $t===null?null:(int)$t;
}
$stateA=state112($preflight,$stateSourceA,$config,$tenantA,$now);
$callback=['state'=>$stateA,'code'=>'synthetic-code-2026'];
$callA=function(array $parameters,int $clock=1800000005)
    use($callbackSourceA,$sessionA,$resourceA):?array {
    return $callbackSourceA->reviewAndConsume(
        'demo-tenant',$sessionA,$resourceA,'sc-domain:example.com',
        $parameters,$clock
    );
};
check112($callbackSourceB->reviewAndConsume(
    'other-tenant',$sessionB,$resourceB,$site,$callback,$now+5)===null,
    'other tenant consumed state');
check112($callbackSourceA->reviewAndConsume(
    'demo-tenant',$sessionB,$resourceA,$site,$callback,$now+5)===null,
    'other session passed');
check112($callbackSourceA->reviewAndConsume(
    'demo-tenant',$sessionA,$resourceB,$site,$callback,$now+5)===null,
    'other resource passed');
check112($callbackSourceA->reviewAndConsume(
    'demo-tenant',$sessionA,$resourceA,'https://example.com/',$callback,$now+5)
    ===null,'URL-prefix confused with domain property');
check112($callA([
    'state'=>$stateA,'code'=>'synthetic-code-2026',
    'scope'=>'https://www.googleapis.com/auth/webmasters'
])===null,'forged OAuth scope accepted');
check112(consumed112($admin,$stateA)===null,
    'invalid attempts consumed pending state');

// The DB changes while callback is still pending. Every revocation check
// must run in the same transaction as the eventual state consumption.
$q=$admin->prepare("UPDATE portal_seo_review_memberships SET
    status='revoked',revoked_at=:at
    WHERE tenant_ref=:tenant AND actor_ref=:actor");
$q->execute(['at'=>$now,'tenant'=>'demo-tenant','actor'=>'portal-sec-a']);
check112($callA($callback)===null,'revoked membership consumed state');
check112(consumed112($admin,$stateA)===null,
    'membership denial changed pending state');
$admin->exec("UPDATE portal_seo_review_memberships SET
    status='active',revoked_at=NULL
    WHERE tenant_ref='demo-tenant' AND actor_ref='portal-sec-a'");
$sessionDigest=hash_hmac('sha256',
    'portal-session-review-v1|demo-tenant|'.$sessionA,$sessionKey);
$rev=$admin->prepare('UPDATE portal_seo_review_sessions
    SET revoked_at=:at WHERE tenant_ref=:tenant AND session_digest=:digest');
$rev->execute([
    'at'=>$now,'tenant'=>'demo-tenant','digest'=>$sessionDigest
]);
check112($callA($callback)===null,'revoked session consumed state');
check112(consumed112($admin,$stateA)===null,
    'session denial changed pending state');
$clear=$admin->prepare('UPDATE portal_seo_review_sessions
    SET revoked_at=NULL WHERE tenant_ref=:tenant AND session_digest=:digest');
$clear->execute(['tenant'=>'demo-tenant','digest'=>$sessionDigest]);

// Successful OAuth state classification is still NOT a real Google grant.
$approvedReview=$callA($callback);
check112(is_array($approvedReview),'valid synthetic state not consumed');
check112($approvedReview['decision']===
    'CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND' &&
    $approvedReview['stateConsumed']===true &&
    $approvedReview['sessionMembershipResourceCheckedInSameTransaction']===true,
    'review flags inconsistent');
foreach([
    'realPortalIssuerVerified','realMfaVerified','liveGooglePermissionVerified',
    'oauthVerified','tokenExchangeAuthorized','customerDisplayAuthorized',
    'productionReadAuthorized','networkPerformed'
] as $flag) check112($approvedReview[$flag]===false,
    'unsafe authorization flag '.$flag);
$safe=json_encode($approvedReview,JSON_THROW_ON_ERROR);
foreach([$stateA,'synthetic-code-2026','portal-sec-a',
    'demo-tenant',$site,$sessionA,$resourceA] as $private)
    check112(!str_contains($safe,$private),'private state leaked');
check112(consumed112($admin,$stateA)!==null,'committed state CAS not recorded');
check112($callA($callback)===null,'OAuth callback state replay accepted');
check112($callbackSourceB->reviewAndConsume(
    'demo-tenant',$sessionA,$resourceA,$site,$callback,$now+6)===null,
    'replay succeeded from a different PostgreSQL connection');

// Consent denied: callback consumes state without producing a token plan.
$stateB=state112($preflight,$stateSourceB,$config,$tenantB,$now+20);
$denied=$callbackSourceB->reviewAndConsume(
    'other-tenant',$sessionB,$resourceB,$site,
    ['state'=>$stateB,'error'=>'access_denied'],$now+22
);
check112(is_array($denied) && $denied['decision']==='CONSENT_DENIED_HOLD'
    && $denied['stateConsumed']===true,
    'consent denial was not one-use');
check112($callbackSourceB->reviewAndConsume(
    'other-tenant',$sessionB,$resourceB,$site,
    ['state'=>$stateB,'code'=>'synthetic-code-2026'],$now+25
)===null,'denied consent state reused');

$expired=state112($preflight,$stateSourceA,$config,$tenantA,$now+30);
check112($callA(['state'=>$expired,'code'=>'synthetic-code-2026'],
    $now+631)===null,'expired state accepted');
check112(consumed112($admin,$expired)===null,'expired state consumed');

putenv('APP_ENV=production');
check112($callbackSourceA->reviewAndConsume(
    'demo-tenant',$sessionA,$resourceA,$site,$callback,$now+50
)===null,'production mode enabled');
putenv('APP_ENV=testing');
check112(!PortalSeoPgOAuthSessionBoundLab::authorizesHttp()
    && !PortalSeoPgOAuthSessionBoundLab::authorizesGoogleHttp()
    && !PortalSeoPgOAuthSessionBoundLab::authorizesTokenExchange()
    && !PortalSeoPgOAuthSessionBoundLab::authorizesCustomerDisplay(),
    'test-only boundary claims production power');

echo "SEO F112 PostgreSQL18: same transaction session/membership/resource/\n".
     "recorded GSC grant + encrypted PKCE callback one-time CAS PASS;\n".
     "revocation, cross-tenant scope, expired state and replay denied;\n".
     "no real Portal login, MFA or Google network authorization\n";
