<?php
declare(strict_types=1);

/**
 * F111: durable synthetic session+MFA+membership+property match under real PG18.
 * Must run AFTER the F108/F109/F110 test fixtures in a disposable CI service.
 */
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\PortalSeoPgIdentityReviewLab;

require_once __DIR__.'/../../vendor/autoload.php';

function ok111(bool $value,string $reason):void{
    if(!$value)throw new RuntimeException('F111 isolated PG18: '.$reason);
}
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_SEO_F111_PG_CI')!=='1'||
    getenv('DB_HOST')!=='127.0.0.1'||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
    getenv('POSTGRES_PASSWORD')!=='f108-ci-postgres-only'||
    getenv('F108_ROLE_PASSWORD')!=='f108-ci-role-only')
    throw new RuntimeException('F111: isolated PG18 CI fixture required');

function db111(string $user,string $password):PDO{
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
$admin=db111('postgres',getenv('POSTGRES_PASSWORD'));
$role=db111('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$now=1800000000;
$site='sc-domain:example.com';
$property=GoogleSearchConsolePropertyIdentifier::fingerprint($site);
ok111(is_string($property),'invalid property');

foreach([
    'portal_seo_review_sessions',
    'portal_seo_review_memberships',
    'portal_seo_review_resources'
] as $table){
    $row=$admin->query("SELECT relrowsecurity,relforcerowsecurity FROM pg_class
        WHERE oid='public.".$table."'::regclass")->fetch();
    ok111(is_array($row)&&in_array($row['relrowsecurity'],[true,'t'],true)
        &&in_array($row['relforcerowsecurity'],[true,'t'],true),
        'missing FORCE RLS on '.$table);
    $admin->exec('GRANT SELECT ON public.'.$table.' TO portal_f108_service');
    ok111((int)$role->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,
        'row leak without tenant context on '.$table);
}
$pepper=random_bytes(32);
function handle111():string{
    return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
}
function digest111(string $purpose,string $tenant,string $handle,string $pepper):string{
    return hash_hmac('sha256',$purpose.'|'.$tenant.'|'.$handle,$pepper);
}
$sessionA=handle111();
$sessionB=handle111();
$resourceA=handle111();
$resourceB=handle111();

function seed111(
    PDO $admin,string $tenant,string $actor,string $session,string $resource,
    string $pepper,string $property,int $now
):void{
    $statement=$admin->prepare('INSERT INTO portal_seo_review_sessions
        (tenant_ref,session_digest,actor_ref,issued_at,expires_at,
        mfa_verified_at,revoked_at,account_revision,session_revision)
        VALUES (:tenant,:digest,:actor,:issued,:expires,:mfa,NULL,2,2)');
    $statement->execute([
        'tenant'=>$tenant,'actor'=>$actor,
        'digest'=>digest111('portal-session-review-v1',$tenant,$session,$pepper),
        'issued'=>$now-300,'expires'=>$now+600,'mfa'=>$now-30
    ]);
    $member=$admin->prepare('INSERT INTO portal_seo_review_memberships
        (tenant_ref,actor_ref,status,permission,checked_at,revoked_at)
        VALUES (:tenant,:actor,:status,:permission,:checked,NULL)');
    $member->execute([
        'tenant'=>$tenant,'actor'=>$actor,'status'=>'active',
        'permission'=>'seo.view','checked'=>$now-20
    ]);
    $resourceStmt=$admin->prepare('INSERT INTO portal_seo_review_resources
        (tenant_ref,resource_digest,property_fingerprint,status,updated_at)
        VALUES (:tenant,:digest,:property,:status,:updated)');
    $resourceStmt->execute([
        'tenant'=>$tenant,
        'digest'=>digest111('portal-seo-resource-review-v1',$tenant,$resource,$pepper),
        'property'=>$property,'status'=>'active','updated'=>$now-100
    ]);
    $grant=$admin->prepare('INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
         verified_at,expires_at,revoked_at)
        VALUES (:tenant,:actor,:property,:scope,:status,:verified,:expires,NULL)');
    $grant->execute([
        'tenant'=>$tenant,'actor'=>$actor,'property'=>$property,
        'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
        'status'=>'active','verified'=>$now-40,'expires'=>$now+600
    ]);
}
seed111($admin,'demo-tenant','portal-auth-a',
    $sessionA,$resourceA,$pepper,$property,$now);
seed111($admin,'other-tenant','portal-auth-b',
    $sessionB,$resourceB,$pepper,$property,$now);
$review=new PortalSeoPgIdentityReviewLab($role,$pepper);
$inspect=function(string $tenant,string $session,string $resource,
    string $siteUrl='sc-domain:example.com',int $time=1800000000)use($review):?array{
    return $review->inspect($tenant,$session,$resource,$siteUrl,$time);
};
$a=$inspect('demo-tenant',$sessionA,$resourceA);
$b=$inspect('other-tenant',$sessionB,$resourceB);
ok111(is_array($a) && is_array($b),'valid synthetic records rejected');
ok111($a['contractVersion']==='portal-seo-f111-identity-review-lab-v1',
    'wrong reviewed contract');
foreach([
    'realSessionIssuerVerified','realMfaVerified','liveGoogleGrantVerified',
    'customerDisplayAuthorized','oauthExchangeAuthorized',
    'productionReadAuthorized','networkPerformed'
] as $flag)ok111($a[$flag]===false,'false authority '.$flag);
$safe=json_encode($a,JSON_THROW_ON_ERROR);
foreach(['portal-auth-a','demo-tenant',$site,$sessionA,$resourceA] as $secret)
    ok111(!str_contains($safe,$secret),'identity or handle leaked');
ok111(!$review::authorizesHttp() && !$review::authenticatesPortal() &&
    !$review::authorizesGoogleOAuth() && !$review::authorizesCustomerDisplay(),
    'lab boundary falsely granted authority');

// Session and resource handles are tenant-bound, even where same site URL
// and a similarly named actor exist.
foreach([
    ['demo-tenant',$sessionB,$resourceA,$site,$now],
    ['other-tenant',$sessionA,$resourceB,$site,$now],
    ['demo-tenant',$sessionA,$resourceB,$site,$now],
    ['demo-tenant',$sessionA,$resourceA,'sc-domain:other.example',$now],
    ['demo-tenant',$sessionA,$resourceA,'https://example.com/',$now],
    ['foreign-tenant',$sessionA,$resourceA,$site,$now],
    ['demo-tenant','not-a-portal-session',$resourceA,$site,$now],
    ['demo-tenant',$sessionA,'bad-resource-handle',$site,$now],
    ['demo-tenant',$sessionA,$resourceA,$site,$now+1000]
] as $args)ok111($inspect(...$args)===null,'cross-tenant, wrong input or expiry allowed');

function modify111(PDO $db,string $sql,array $params=[]):void{
    $q=$db->prepare($sql);
    ok111($q!==false && $q->execute($params),'test fixture update failed');
}
$digA=digest111('portal-session-review-v1','demo-tenant',$sessionA,$pepper);
$resDigA=digest111('portal-seo-resource-review-v1','demo-tenant',$resourceA,$pepper);
$sessionCondition=[
    'tenant'=>'demo-tenant','digest'=>$digA
];
// MFA missing, stale, revocation, expired session and stale revision.
foreach([
    ['mfa_verified_at=NULL','mfa_verified_at=:restore',
        ['restore'=>$now-30]],
    ['mfa_verified_at=:stale','mfa_verified_at=:restore',
        ['stale'=>$now-601,'restore'=>$now-30]],
    ['revoked_at=:revoked','revoked_at=NULL',['revoked'=>$now]],
    ['session_revision=1','session_revision=2',[]],
    ['expires_at=:expired','expires_at=:restore',
        ['expired'=>$now-1,'restore'=>$now+600]]
] as [$mutation,$restore,$extra]){
    $base='UPDATE portal_seo_review_sessions SET ';
    $filter=' WHERE tenant_ref=:tenant AND session_digest=:digest';
    modify111($admin,$base.$mutation.$filter,
        $sessionCondition+$extra);
    ok111($inspect('demo-tenant',$sessionA,$resourceA)===null,
        'invalid MFA, revocation or revision accepted');
    modify111($admin,$base.$restore.$filter,
        $sessionCondition+$extra);
    ok111(is_array($inspect('demo-tenant',$sessionA,$resourceA)),
        'synthetic session did not recover after controlled fixture restore');
}
// Membership and resource revocation do not leak data.
modify111($admin,"UPDATE portal_seo_review_memberships
    SET status='revoked',revoked_at=:now WHERE tenant_ref=:tenant AND actor_ref=:actor",
    ['now'=>$now,'tenant'=>'demo-tenant','actor'=>'portal-auth-a']);
ok111($inspect('demo-tenant',$sessionA,$resourceA)===null,
    'revoked membership accepted');
modify111($admin,"UPDATE portal_seo_review_memberships
    SET status='active',revoked_at=NULL WHERE tenant_ref=:tenant AND actor_ref=:actor",
    ['tenant'=>'demo-tenant','actor'=>'portal-auth-a']);
modify111($admin,"UPDATE portal_seo_review_resources SET status='revoked'
    WHERE tenant_ref=:tenant AND resource_digest=:digest",
    ['tenant'=>'demo-tenant','digest'=>$resDigA]);
ok111($inspect('demo-tenant',$sessionA,$resourceA)===null,
    'revoked resource accepted');
modify111($admin,"UPDATE portal_seo_review_resources SET status='active'
    WHERE tenant_ref=:tenant AND resource_digest=:digest",
    ['tenant'=>'demo-tenant','digest'=>$resDigA]);

// A stale/expired membership freshness marker denies; no UI privilege.
modify111($admin,'UPDATE portal_seo_review_memberships SET checked_at=:at
    WHERE tenant_ref=:tenant AND actor_ref=:actor',
    ['at'=>$now-901,'tenant'=>'demo-tenant','actor'=>'portal-auth-a']);
ok111($inspect('demo-tenant',$sessionA,$resourceA)===null,
    'stale membership accepted');
modify111($admin,'UPDATE portal_seo_review_memberships SET checked_at=:at
    WHERE tenant_ref=:tenant AND actor_ref=:actor',
    ['at'=>$now-20,'tenant'=>'demo-tenant','actor'=>'portal-auth-a']);

modify111($admin,"UPDATE portal_gsc_property_grants
    SET grant_status='revoked',revoked_at=:revoked
    WHERE tenant_ref=:tenant AND actor_ref=:actor AND property_fingerprint=:property",
    ['revoked'=>$now,'tenant'=>'demo-tenant',
        'actor'=>'portal-auth-a','property'=>$property]);
ok111($inspect('demo-tenant',$sessionA,$resourceA)===null,
    'revoked GSC grant accepted');
ok111(is_array($inspect('other-tenant',$sessionB,$resourceB)),
    'other tenant affected by revoked tenant A grant');

foreach([
    'portal_seo_review_sessions',
    'portal_seo_review_memberships',
    'portal_seo_review_resources'
] as $table)ok111((int)$role->query('SELECT count(*) FROM '.$table)
    ->fetchColumn()===0,'RLS context remained after service call');

// Gate outside CI/test never returns even synthetic-positive review.
putenv('APP_ENV=production');
ok111($inspect('other-tenant',$sessionB,$resourceB)===null,
    'production mode unexpectedly passed review');
putenv('APP_ENV=testing');
putenv('PORTAL_SEO_F111_PG_CI=0');
ok111($inspect('other-tenant',$sessionB,$resourceB)===null,
    'missing explicit lab gate unexpectedly accepted');

echo "SEO F111 PG18: HMAC-bound tenant sessions, MFA freshness, membership,\n".
     "resource/property mapping, GSC grant revocation, RLS isolation PASS;\n".
     "synthetic data only, no real authenticated issuer or customer access\n";
