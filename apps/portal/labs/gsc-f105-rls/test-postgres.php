<?php
declare(strict_types=1);

/**
 * F105: real disposable PostgreSQL RLS test of the F104 daily bridge.
 * All provider data and grants are synthetic. There is NO authentication,
 * Google connection or permission for user-facing display.
 */
$root=dirname(__DIR__,2).'/app/Domain/SeoReview/';
foreach ([
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleRecordedPropertyGrant.php',
    'GoogleSearchConsoleTenantScopedTransaction.php',
    'GoogleSearchConsoleTenantGrantGateway.php',
    'OfflineOpenSeoDailyPreview.php',
    'OfflineOpenSeoRlsReviewGateway.php',
] as $f) require_once $root.$f;

use App\Domain\SeoReview\OfflineOpenSeoRlsReviewGateway as Review;

if (PHP_SAPI !== 'cli' || $argc !== 2 ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('PORTAL_OPENSEO_DAILY_LAB') !== '1' ||
    getenv('PORTAL_GSC_F105_RLS_LAB') !== '1' ||
    getenv('PORTAL_GSC_LEDGER_DSN') !==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test') {
    fwrite(STDERR,"F105 RLS lab: isolated test runtime required\n");
    exit(2);
}
function check(bool $value): void {
    if (!$value) throw new RuntimeException('F105 synthetic RLS review refused');
}
$owner=null;
$probe=null;
$role='gsc_f105_preview_probe';
$now=1790000000;
try {
    $fixture=file_get_contents($argv[1]);
    check(is_string($fixture) && strlen($fixture) < 65536);
    $dto=json_decode($fixture, true, 16, JSON_THROW_ON_ERROR);
    check(is_array($dto));

    $dsn=getenv('PORTAL_GSC_LEDGER_DSN');
    $owner=new PDO($dsn, getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'), [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    $owner->exec("CREATE ROLE gsc_f105_preview_probe LOGIN
        PASSWORD 'f105_ci_synthetic_only'
        NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS");
    $owner->exec("GRANT CONNECT ON DATABASE imagine_gsc_ci_test
        TO gsc_f105_preview_probe");
    $owner->exec("GRANT USAGE ON SCHEMA public TO gsc_f105_preview_probe");
    $owner->exec("GRANT SELECT ON portal_gsc_property_grants
        TO gsc_f105_preview_probe");

    $tenant='imagine-demo';
    $actor='review-operator';
    $site='sc-domain:example.com';
    $fingerprint=hash('sha256',$site);
    $scope='https://www.googleapis.com/auth/webmasters.readonly';
    $add=$owner->prepare(
        'INSERT INTO portal_gsc_property_grants
         (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
          verified_at,expires_at)
         VALUES (:tenant,:actor,:fingerprint,:scope,:status,:verified,:expires)'
    );
    foreach ([
        [$tenant,$actor,$fingerprint],
        ['other-tenant',$actor,$fingerprint],
        [$tenant,'different-actor',$fingerprint],
        [$tenant,$actor,hash('sha256','sc-domain:example.net')],
    ] as [$t,$a,$fp]) $add->execute([
        'tenant'=>$t,'actor'=>$a,'fingerprint'=>$fp,
        'scope'=>$scope,'status'=>'active','verified'=>$now-100,
        'expires'=>$now+300,
    ]);

    $probe=new PDO($dsn,$role,'f105_ci_synthetic_only',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    check((int)$probe->query('SELECT COUNT(*) FROM portal_gsc_property_grants')
        ->fetchColumn() === 0);
    $gate=new Review($probe);
    $context=['tenantRef'=>$tenant,'actorRef'=>$actor];
    check(!Review::authorizesGoogleHttp() &&
        !Review::authorizesCustomerDisplay() &&
        !Review::authorizesPortalMembership() &&
        !Review::canPublish());

    $accepted=$gate->review($context,$site,$now,$dto);
    check(is_array($accepted));
    check($accepted['contractVersion'] ===
        'portal-gsc-f105-recorded-review-lab-v1');
    check($accepted['status'] === 'RECORDED_GRANT_NOT_LIVE_AUTHORIZATION');
    check($accepted['scope']['tenantRef'] === $tenant);
    check($accepted['scope']['propertyFingerprint'] === $fingerprint);
    foreach ([
        'sourceOriginVerified','sessionMfaVerified',
        'backendTenantMembershipVerified','googleOAuthVerifiedNow',
        'customerDisplayAuthorized','productionReadAuthorized',
        'publicationAuthorized','networkPerformed',
    ] as $flag) check($accepted[$flag] === false);
    check($accepted['summary']['clicks'] === $dto['summary']['clicks']);
    check($accepted['summary']['impressions'] === $dto['summary']['impressions']);
    $output=json_encode($accepted,JSON_THROW_ON_ERROR);
    check(!str_contains($output,'example.com') &&
        !str_contains($output,'access_token') &&
        !str_contains($output,'review-operator'));

    $denials=[
        [['tenantRef'=>'other-tenant','actorRef'=>$actor],$site,$now,$dto],
        [['tenantRef'=>$tenant,'actorRef'=>'different-actor'],$site,$now,$dto],
        [['tenantRef'=>$tenant,'actorRef'=>'unknown-actor'],$site,$now,$dto],
        [['tenantRef'=>$tenant,'actorRef'=>$actor,'role'=>'admin'],$site,$now,$dto],
        [$context,'sc-domain:example.net',$now,$dto],
        [$context,'sc-domain:example.com',$now+1000,$dto],
        [$context,$site,$now,array_merge($dto,['tenantRef'=>'other-tenant'])],
        [$context,$site,$now,array_merge($dto,['propertyFingerprint'=>
            hash('sha256','sc-domain:example.net')])],
        [$context,$site,$now,array_merge($dto,['oauthVerifiedForImagine'=>true])],
        [$context,$site,$now,array_merge($dto,['customerDisplayAuthorized'=>true])],
        [$context,$site,$now,array_merge($dto,['sourceCompletenessNotProven'=>false])],
        [$context,$site,$now,array_merge($dto,['summary'=>array_merge(
            $dto['summary'],['clicks'=>999])])],
    ];
    // The other-tenant and other-actor rows are real records, but do not
    // authorize the fixed F104 tenant/actor review identity.
    foreach ($denials as $candidate) check($gate->review(...$candidate)===null);
    check(!$probe->inTransaction());
    check((int)$probe->query('SELECT COUNT(*) FROM portal_gsc_property_grants')
        ->fetchColumn() === 0);
    check((new Review($owner))->review($context,$site,$now,$dto) === null);

    $owner->prepare(
        "UPDATE portal_gsc_property_grants
         SET grant_status='revoked',revoked_at=:when
         WHERE tenant_ref=:tenant AND actor_ref=:actor
           AND property_fingerprint=:fp"
    )->execute(['when'=>$now,'tenant'=>$tenant,'actor'=>$actor,
        'fp'=>$fingerprint]);
    check($gate->review($context,$site,$now,$dto)===null);
    check((int)$probe->query('SELECT COUNT(*) FROM portal_gsc_property_grants')
        ->fetchColumn()===0);

    putenv('PORTAL_GSC_F105_RLS_LAB=0');
    check($gate->review($context,$site,$now,$dto)===null);
    putenv('PORTAL_GSC_F105_RLS_LAB=1');
    putenv('APP_ENV=production');
    check($gate->review($context,$site,$now,$dto)===null);

    echo "F105: RLS tenant/actor/property, revocation, privileged role ".
        "and 12 hostile reviews blocked; no customer display\n";
} catch (Throwable) {
    fwrite(STDERR,"F105 RLS lab: guarded preview failed\n");
    exit(1);
} finally {
    $probe=null;
    if($owner instanceof PDO) {
        try {
            $owner->exec('DROP OWNED BY gsc_f105_preview_probe');
            $owner->exec('DROP ROLE IF EXISTS gsc_f105_preview_probe');
        } catch(Throwable) {}
    }
}
