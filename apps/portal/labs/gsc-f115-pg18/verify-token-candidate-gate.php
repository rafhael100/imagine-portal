<?php
declare(strict_types=1);

/**
 * F115 PG18: a decryptable synthetic candidate without an active/fresh
 * recorded grant must never produce even a positive safe review.
 */
use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenVault;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenResponsePolicy;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction;
use App\Domain\SeoReview\PortalSeoPgGrantTokenRevocationLab;
use App\Domain\SeoReview\PortalSeoPgScopedTokenCandidateReviewLab;

require_once __DIR__.'/../../vendor/autoload.php';

function check115(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('F115 synthetic PG18: '.$reason);
}
if(PHP_SAPI!=='cli' || getenv('CI')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_SEO_F114_PG_CI')!=='1' ||
    getenv('PORTAL_SEO_F115_PG_CI')!=='1' ||
    getenv('PORTAL_GSC_TOKEN_VAULT_LAB')!=='1' ||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('POSTGRES_PASSWORD')!=='f108-ci-postgres-only' ||
    getenv('F108_ROLE_PASSWORD')!=='f108-ci-role-only')
    throw new RuntimeException('F115 requires disposable PG18 CI');

function db115(string $user,string $pw):PDO{
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$pw,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
$admin=db115('postgres',getenv('POSTGRES_PASSWORD'));
$service=db115('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$now=1800000000;
$site='sc-domain:example.com';
$fp=GoogleSearchConsolePropertyIdentifier::fingerprint($site);
check115(is_string($fp),'property');
$key=random_bytes(32);
$contexts=[
    'active'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f115-active'],
    'other'=>['tenantRef'=>'other-tenant','actorRef'=>'portal-f115-other'],
    'stale'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f115-stale'],
    'revoked'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f115-revoked'],
    'expired'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f115-expired'],
    'noCandidate'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f115-empty'],
    'noGrant'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f115-nogrant']
];

function seedGrant115(PDO $db,array $ctx,string $fp,int $verified,int $expiry):void {
    $stmt=$db->prepare('INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
         verified_at,expires_at,revoked_at)
        VALUES (:tenant,:actor,:property,:scope,:status,
            :verified,:expires,NULL)');
    $stmt->execute([
        'tenant'=>$ctx['tenantRef'],'actor'=>$ctx['actorRef'],'property'=>$fp,
        'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
        'status'=>'active','verified'=>$verified,'expires'=>$expiry
    ]);
}
foreach($contexts as $type=>$ctx) {
    if($type==='noGrant')continue;
    seedGrant115($admin,$ctx,$fp,
        $type==='stale'?$now-901:$now-30,$now+1200);
}
$scope=new GoogleSearchConsoleTenantScopedTransaction($service);
function seedToken115(
    GoogleSearchConsoleTenantScopedTransaction $scope,
    array $ctx,string $fp,string $key,int $issued
):void {
    $json=json_encode([
        'access_token'=>'synthetic-access-f115-abcdefghijklmnopqrstuvwxyz',
        'refresh_token'=>'synthetic-refresh-f115-abcdefghijklmnopqrstuvwxyz',
        'expires_in'=>600,
        'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
        'token_type'=>'Bearer'
    ],JSON_THROW_ON_ERROR);
    $candidate=(new GoogleSearchConsoleOAuthTokenResponsePolicy())
        ->inspect(200,$json,$issued);
    check115($candidate!==null,'synthetic OAuth response rejected');
    $stored=$scope->run($ctx,
        fn(PDO $db)=>(new GoogleSearchConsoleEncryptedTokenVault($db,$key))
            ->storeSyntheticCandidate(
                $ctx['tenantRef'],$ctx['actorRef'],$fp,$candidate,$issued
            )
    );
    check115($stored===true,'synthetic encrypted candidate not stored');
}
foreach($contexts as $type=>$ctx){
    if($type==='noCandidate')continue;
    seedToken115($scope,$ctx,$fp,$key,
        $type==='expired'?$now-1000:$now);
}
$review=new PortalSeoPgScopedTokenCandidateReviewLab($service,$key);
$inspect=fn(array $ctx,string $s='sc-domain:example.com',int $time=1800000000)
    =>$review->review($ctx,$s,$time);

$positive=$inspect($contexts['active']);
check115(is_array($positive),'matching grant/candidate refused');
check115($positive['contractVersion']===
    'portal-seo-f115-token-grant-coherence-review-lab-v1','contract mismatch');
check115($positive['recordedGrantFresh']===true &&
    $positive['encryptedCandidatePresentForLabReview']===true &&
    $positive['candidateTokenReturned']===false,
    'safe review evidence wrong');
foreach([
    'liveGooglePropertyVerified','realPortalIdentityVerified',
    'providerOriginVerified','tokenUseAuthorized',
    'customerDisplayAuthorized','productionReadAuthorized',
    'networkPerformed'
] as $flag) check115($positive[$flag]===false,
    'review falsely authorizes '.$flag);
$safe=json_encode($positive,JSON_THROW_ON_ERROR);
foreach([
    'synthetic-access','synthetic-refresh','demo-tenant',
    'portal-f115-active','example.com'
] as $private)
    check115(!str_contains($safe,$private),'review leaked secret/tenant');

check115($inspect($contexts['other'])!==null,'other tenant candidate refused');
foreach(['stale','expired','noCandidate','noGrant'] as $type)
    check115($inspect($contexts[$type])===null,
        'unverified, expired or missing grant/candidate accepted: '.$type);
check115($inspect([
    'tenantRef'=>'demo-tenant','actorRef'=>'portal-f115-other'
])===null,'cross-tenant identity borrowed candidate');
check115($inspect([
    'tenantRef'=>'other-tenant','actorRef'=>'portal-f115-active'
])===null,'cross-tenant actor borrowed grant');
check115($inspect($contexts['active'],'sc-domain:other.example')===null,
    'different property accepted');
check115($inspect($contexts['active'],'https://example.com/')===null,
    'URL-prefix accepted as domain property');
check115($inspect([...$contexts['active'],'oauthVerified'=>true])===null,
    'forged caller-authority flag accepted');

// A candidate can exist in the database while the grant is revoked.
// The review must immediately deny it. F114 also invalidates the
// matching candidate atomically.
$revoker=new PortalSeoPgGrantTokenRevocationLab($service);
check115($revoker->revoke($contexts['revoked'],$site,$now),
    'F114 joint revocation failed');
check115($inspect($contexts['revoked'])===null,
    'revoked grant/candidate still accepted');
check115($inspect($contexts['other'])!==null,
    'other tenant affected by property revocation');

// An individual candidate can be revoked without revoking the grant;
// decryptability must fail closed without a current candidate.
$one=$contexts['active'];
$removed=$scope->run($one,
    fn(PDO $db)=>(new GoogleSearchConsoleEncryptedTokenVault($db,$key))
        ->revokeSyntheticCandidate($one['tenantRef'],$one['actorRef'],$fp,$now)
);
check115($removed===true,'candidate revocation failed');
check115($inspect($contexts['active'])===null,
    'candidate-only revocation ignored');
check115($inspect($contexts['other'])!==null,
    'candidate revocation crossed tenant');

foreach(['portal_gsc_property_grants','portal_gsc_oauth_token_candidates'] as $table)
    check115((int)$service->query('SELECT count(*) FROM '.$table)
        ->fetchColumn()===0,'tenant context leaked after review');

putenv('APP_ENV=production');
check115($inspect($contexts['other'])===null,
    'production review enabled');
check115(!PortalSeoPgScopedTokenCandidateReviewLab::authorizesGoogleHttp()
    && !PortalSeoPgScopedTokenCandidateReviewLab::authorizesTokenUse()
    && !PortalSeoPgScopedTokenCandidateReviewLab::authorizesCustomerDisplay(),
    'lab gate authorized Google or customer');
echo "SEO F115 PostgreSQL18: fresh grant + encrypted synthetic candidate\n".
     "required; cross-tenant, expired, missing and revoked denied;\n".
     "no token escaped, no Google network access, no production grants\n";
