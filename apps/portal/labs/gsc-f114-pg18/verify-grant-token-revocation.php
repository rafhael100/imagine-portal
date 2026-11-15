<?php
declare(strict_types=1);

/**
 * F114: disposable PG18 test of grant + encrypted candidate + audit atomicity.
 * Requires earlier F108-F113 service fixture and existing Laravel migrations.
 */
use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenVault;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenResponsePolicy;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction;
use App\Domain\SeoReview\PortalSeoPgGrantTokenRevocationLab;

require_once __DIR__.'/../../vendor/autoload.php';
function expect114(bool $ok,string $why):void {
    if (!$ok)throw new RuntimeException('F114 synthetic PG18: '.$why);
}
if (PHP_SAPI!=='cli' || getenv('CI')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_SEO_F114_PG_CI')!=='1' ||
    getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('POSTGRES_PASSWORD')!=='f108-ci-postgres-only' ||
    getenv('F108_ROLE_PASSWORD')!=='f108-ci-role-only')
    throw new RuntimeException('F114 requires disposable PG18 CI');

function db114(string $user,string $password):PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f108_ci',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
$admin=db114('postgres',getenv('POSTGRES_PASSWORD'));
$service=db114('portal_f108_service',getenv('F108_ROLE_PASSWORD'));
$noAudit=db114('portal_f108_noaudit',getenv('F108_ROLE_PASSWORD'));
$now=1800000000;
$site='sc-domain:example.com';
$fp=GoogleSearchConsolePropertyIdentifier::fingerprint($site);
expect114(is_string($fp),'property invalid');

foreach([
    'portal_gsc_oauth_token_candidates','portal_gsc_property_grants',
    'portal_gsc_security_events'
] as $table){
    $row=$admin->query("SELECT relrowsecurity,relforcerowsecurity FROM pg_class
        WHERE oid='public.".$table."'::regclass")->fetch(PDO::FETCH_ASSOC);
    expect114(is_array($row) &&
        in_array($row['relrowsecurity'],[true,'t'],true) &&
        in_array($row['relforcerowsecurity'],[true,'t'],true),
        'FORCE RLS required');
}
$admin->exec('GRANT SELECT,INSERT,UPDATE
    ON portal_gsc_oauth_token_candidates TO portal_f108_service');
$admin->exec('GRANT SELECT,UPDATE
    ON portal_gsc_oauth_token_candidates TO portal_f108_noaudit');
$admin->exec("CREATE ROLE portal_f114_notoken LOGIN
    NOSUPERUSER NOBYPASSRLS NOINHERIT PASSWORD 'f108-ci-role-only'");
$admin->exec('GRANT USAGE ON SCHEMA public TO portal_f114_notoken');
$admin->exec('GRANT SELECT,UPDATE ON portal_gsc_property_grants
    TO portal_f114_notoken');
$admin->exec('GRANT SELECT ON portal_gsc_oauth_token_candidates
    TO portal_f114_notoken');
$admin->exec('GRANT SELECT,INSERT ON portal_gsc_security_events
    TO portal_f114_notoken');
$noToken=db114('portal_f114_notoken',getenv('F108_ROLE_PASSWORD'));

function seed114(PDO $admin,string $tenant,string $actor,string $fp,int $now):void {
    $stmt=$admin->prepare('INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
         verified_at,expires_at,revoked_at)
        VALUES (:tenant,:actor,:fingerprint,:scope,:status,
            :verified,:expiry,NULL)');
    $stmt->execute([
        'tenant'=>$tenant,'actor'=>$actor,
        'fingerprint'=>$fp,
        'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
        'status'=>'active','verified'=>$now-60,'expiry'=>$now+1800
    ]);
}
$contexts=[
    'a'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f114-a'],
    'b'=>['tenantRef'=>'other-tenant','actorRef'=>'portal-f114-b'],
    'noaudit'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f114-noaudit'],
    'notoken'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f114-notoken'],
    'empty'=>['tenantRef'=>'demo-tenant','actorRef'=>'portal-f114-empty'],
];
foreach($contexts as $ctx)
    seed114($admin,$ctx['tenantRef'],$ctx['actorRef'],$fp,$now);

$tokenJson=json_encode([
    'access_token'=>'synthetic-access-f114-abcdefghijklmnopqrstuvwxyz',
    'refresh_token'=>'synthetic-refresh-f114-abcdefghijklmnopqrstuvwxyz',
    'expires_in'=>1200,
    'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
    'token_type'=>'Bearer'
],JSON_THROW_ON_ERROR);
$candidate=(new GoogleSearchConsoleOAuthTokenResponsePolicy())
    ->inspect(200,$tokenJson,$now);
expect114($candidate!==null,'synthetic strict token response rejected');
$key=random_bytes(32);
$vault=new GoogleSearchConsoleEncryptedTokenVault($service,$key);
$scope=new GoogleSearchConsoleTenantScopedTransaction($service);
foreach(['a','b','noaudit','notoken'] as $type) {
    $ctx=$contexts[$type];
    $stored=$scope->run($ctx,fn(PDO $db)=> (new
        GoogleSearchConsoleEncryptedTokenVault($db,$key))
        ->storeSyntheticCandidate($ctx['tenantRef'],$ctx['actorRef'],
            $fp,$candidate,$now));
    expect114($stored===true,'encrypted candidate could not be stored: '.$type);
}
function grant114(PDO $db,array $ctx,string $fp):array {
    $stmt=$db->prepare('SELECT grant_status,revoked_at
        FROM portal_gsc_property_grants
        WHERE tenant_ref=:tenant AND actor_ref=:actor
        AND property_fingerprint=:fp');
    $stmt->execute([
        'tenant'=>$ctx['tenantRef'],'actor'=>$ctx['actorRef'],'fp'=>$fp
    ]);
    $record=$stmt->fetch(PDO::FETCH_ASSOC);
    expect114(is_array($record),'grant missing');
    return $record;
}
function token114(PDO $db,array $ctx,string $fp):?array {
    $stmt=$db->prepare('SELECT revoked_at,sealed_tokens
        FROM portal_gsc_oauth_token_candidates
        WHERE tenant_ref=:tenant AND actor_ref=:actor
        AND property_fingerprint=:fp');
    $stmt->execute([
        'tenant'=>$ctx['tenantRef'],'actor'=>$ctx['actorRef'],'fp'=>$fp
    ]);
    $r=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($r)?$r:null;
}
function audit114(PDO $db,array $ctx,string $fp):int {
    $stmt=$db->prepare("SELECT count(*) FROM portal_gsc_security_events
        WHERE tenant_ref=:tenant AND actor_ref=:actor
        AND property_fingerprint=:fp AND event_code='property_grant_revoked'");
    $stmt->execute([
        'tenant'=>$ctx['tenantRef'],'actor'=>$ctx['actorRef'],'fp'=>$fp
    ]);
    return (int)$stmt->fetchColumn();
}
foreach(['a','b','noaudit','notoken'] as $type){
    $r=token114($admin,$contexts[$type],$fp);
    expect114(is_array($r) && $r['revoked_at']===null &&
        str_starts_with($r['sealed_tokens'],'v1.') &&
        !str_contains($r['sealed_tokens'],'synthetic-access-f114'),
        'candidate not encrypted or already revoked');
}
$review=new PortalSeoPgGrantTokenRevocationLab($service);
expect114(!$review->revoke([
    'tenantRef'=>'demo-tenant','actorRef'=>'portal-f114-b'
],$site,$now),'cross-tenant actor revoked a grant');
expect114(!$review->revoke($contexts['a'],'https://example.com/',$now),
    'URL-prefix confusion');
expect114($review->revoke($contexts['a'],$site,$now),
    'grant/token/audit joint revocation failed');
expect114(grant114($admin,$contexts['a'],$fp)['grant_status']==='revoked',
    'grant not revoked');
expect114(token114($admin,$contexts['a'],$fp)['revoked_at']!==null,
    'encrypted token candidate not revoked');
expect114(audit114($admin,$contexts['a'],$fp)===1,
    'exactly one audit event required');
expect114(!$review->revoke($contexts['a'],$site,$now),
    'replayed grant revocation accepted');
expect114(audit114($admin,$contexts['a'],$fp)===1,
    'replayed revocation wrote audit');

$after=$scope->run($contexts['a'],fn(PDO $db)=>
    (new GoogleSearchConsoleEncryptedTokenVault($db,$key))
        ->inspectSyntheticCandidate('demo-tenant','portal-f114-a',$fp,$now));
expect114($after===null,'revoked token candidate still readable');
$other=$scope->run($contexts['b'],fn(PDO $db)=>
    (new GoogleSearchConsoleEncryptedTokenVault($db,$key))
        ->inspectSyntheticCandidate('other-tenant','portal-f114-b',$fp,$now));
expect114($other!==null && $other->safeReview()['tokenUseAuthorized']===false,
    'other tenant candidate lost isolation or gained authority');
expect114(grant114($admin,$contexts['b'],$fp)['grant_status']==='active',
    'other tenant grant revoked');
expect114(audit114($admin,$contexts['b'],$fp)===0,
    'other tenant received audit event');

// An unprivileged audit role can UPDATE grant + candidate, but cannot
// commit either because it cannot INSERT the mandatory audit event.
$withoutAudit=new PortalSeoPgGrantTokenRevocationLab($noAudit);
expect114(!$withoutAudit->revoke($contexts['noaudit'],$site,$now),
    'missing audit write privilege did not block the whole change');
expect114(grant114($admin,$contexts['noaudit'],$fp)['grant_status']==='active'
    && token114($admin,$contexts['noaudit'],$fp)['revoked_at']===null
    && audit114($admin,$contexts['noaudit'],$fp)===0,
    'audit failure did not roll back grant and token');

// A role with audit privileges but no token UPDATE must also roll back
// the property-grant revocation.
$withoutToken=new PortalSeoPgGrantTokenRevocationLab($noToken);
expect114(!$withoutToken->revoke($contexts['notoken'],$site,$now),
    'missing token write privilege did not block grant change');
expect114(grant114($admin,$contexts['notoken'],$fp)['grant_status']==='active'
    && token114($admin,$contexts['notoken'],$fp)['revoked_at']===null
    && audit114($admin,$contexts['notoken'],$fp)===0,
    'token failure left partial grant/audit mutation');

// Revoking a grant which never had a candidate still requires audit.
expect114($review->revoke($contexts['empty'],$site,$now),
    'valid grant without candidate should be revocable');
expect114(token114($admin,$contexts['empty'],$fp)===null &&
    audit114($admin,$contexts['empty'],$fp)===1,
    'missing candidate must not suppress audit');

// All SQL sessions should return to no visible rows without a tenant GUC.
foreach(['portal_gsc_property_grants','portal_gsc_oauth_token_candidates',
    'portal_gsc_security_events'] as $table)
    expect114((int)$service->query('SELECT COUNT(*) FROM '.$table)
        ->fetchColumn()===0,'tenant GUC leaked after transaction');

putenv('APP_ENV=production');
expect114(!$review->revoke($contexts['b'],$site,$now),
    'production mode unexpectedly allowed synthetic revocation');
expect114(grant114($admin,$contexts['b'],$fp)['grant_status']==='active',
    'prod guard changed the other tenant grant');
expect114(!PortalSeoPgGrantTokenRevocationLab::authorizesGoogleRevocation()
    && !PortalSeoPgGrantTokenRevocationLab::authorizesOAuthTokenUse()
    && !PortalSeoPgGrantTokenRevocationLab::authorizesCustomerDisplay(),
    'review-only code grants customer or Google authorization');

echo "SEO F114 PG18: grant + encrypted token candidate + audit atomic;\n".
     "noaudit/notoken roles rolled back; tenant isolation and replay PASS;\n".
     "no Google OAuth authorization, data reads or network performed\n";
