<?php
declare(strict_types=1);

/**
 * F106 — disposable PostgreSQL 16 only. No Google, real sessions, customers
 * or live credentials. Verifies UUID/revision binding + grant atomically
 * under a nonprivileged tenant RLS transaction.
 */
$root=dirname(__DIR__,2).'/app/Domain/SeoReview/';
foreach ([
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleRecordedPropertyGrant.php',
    'GoogleSearchConsoleTenantScopedTransaction.php',
    'OfflineOpenSeoDailyPreview.php',
    'OfflinePortalGscIdentityBindingReview.php',
] as $f) require_once $root.$f;

use App\Domain\SeoReview\OfflinePortalGscIdentityBindingReview as Review;

if (PHP_SAPI!=='cli' || $argc!==2 ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_F106_BINDING_LAB')!=='1' ||
    getenv('PORTAL_OPENSEO_DAILY_LAB')!=='1' ||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test') {
    fwrite(STDERR,"GSC F106: private PostgreSQL lab only\n");exit(2);
}
function ensure(bool $ok):void {
    if(!$ok)throw new RuntimeException('F106 identity binding lab refused');
}
$owner=null;$probe=null;
$orgA='c8a2d30a-82dd-48e7-8c51-f313d7469681';
$userA='46fd090a-11fd-4b68-917b-c3ae903dac9c';
$orgB='197d34aa-5750-4efe-bb11-d35bd8d23122';
$userB='2ba77d51-f862-4d78-9b28-96ad78be7a16';
$now=1790000000;
$tenant='imagine-demo';$actor='review-operator';
$property='sc-domain:example.com';$propertyHash=hash('sha256',$property);
try {
    $payload=file_get_contents($argv[1]);
    ensure(is_string($payload)&&strlen($payload)<65536);
    $dto=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
    ensure(is_array($dto));
    $dsn=getenv('PORTAL_GSC_LEDGER_DSN');
    $owner=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    $owner->exec("CREATE ROLE gsc_f106_scope_probe LOGIN
        PASSWORD 'f106_ci_synthetic_only'
        NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS");
    $owner->exec("CREATE ROLE gsc_f106_privilege_probe LOGIN
        PASSWORD 'f106_ci_privileged_only'
        NOSUPERUSER NOCREATEDB CREATEROLE NOINHERIT NOBYPASSRLS");
    $owner->exec("CREATE ROLE gsc_f106_inherit_probe LOGIN
        PASSWORD 'f106_ci_inherit_only'
        NOSUPERUSER NOCREATEDB NOCREATEROLE INHERIT NOBYPASSRLS");
    foreach (['gsc_f106_scope_probe','gsc_f106_privilege_probe',
        'gsc_f106_inherit_probe'] as $role) {
        $owner->exec("GRANT CONNECT ON DATABASE imagine_gsc_ci_test TO {$role}");
        $owner->exec("GRANT USAGE ON SCHEMA public TO {$role}");
        $owner->exec("GRANT SELECT ON portal_gsc_identity_scope_links TO {$role}");
        $owner->exec("GRANT SELECT ON portal_gsc_property_grants TO {$role}");
    }
    $flags=$owner->query("SELECT relrowsecurity,relforcerowsecurity
        FROM pg_class WHERE oid=to_regclass('portal_gsc_identity_scope_links')")
        ->fetch(PDO::FETCH_ASSOC);
    ensure(is_array($flags)&&
        in_array($flags['relrowsecurity'],[true,'t','1',1],true)&&
        in_array($flags['relforcerowsecurity'],[true,'t','1',1],true));

    $add=$owner->prepare("INSERT INTO portal_gsc_identity_scope_links
        (portal_organization_id,portal_identity_id,tenant_ref,actor_ref,
        organization_revision,membership_revision,issued_at,expires_at,
        link_status,revoked_at)
        VALUES (CAST(:org AS uuid),CAST(:id AS uuid),:tenant,:actor,
        :org_rev,:mem_rev,:issued,:expires,:status,NULL)");
    foreach([
        [$orgA,$userA,$tenant,$actor,5,7],
        [$orgB,$userB,'other-tenant','other-operator',9,4],
    ] as [$org,$id,$scopeTenant,$scopeActor,$orgRev,$memberRev])
        $add->execute([
            'org'=>$org,'id'=>$id,'tenant'=>$scopeTenant,
            'actor'=>$scopeActor,'org_rev'=>$orgRev,'mem_rev'=>$memberRev,
            'issued'=>$now-100,'expires'=>$now+600,'status'=>'active',
        ]);
    $scope='https://www.googleapis.com/auth/webmasters.readonly';
    $grant=$owner->prepare("INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
        verified_at,expires_at)
        VALUES (:tenant,:actor,:property,:scope,'active',:verified,:expires)");
    foreach([
        [$tenant,$actor,$propertyHash],
        ['other-tenant','other-operator',$propertyHash],
    ] as [$t,$a,$p])$grant->execute([
        'tenant'=>$t,'actor'=>$a,'property'=>$p,
        'scope'=>$scope,'verified'=>$now-50,'expires'=>$now+300
    ]);
    $principal=[
        'organizationUuid'=>$orgA,'identityUuid'=>$userA,
        'organizationRevision'=>5,'membershipRevision'=>7,
        'sessionIssuedAt'=>$now-30,'sessionExpiresAt'=>$now+200
    ];
    $probe=new PDO($dsn,'gsc_f106_scope_probe','f106_ci_synthetic_only',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    foreach(['portal_gsc_identity_scope_links','portal_gsc_property_grants']
        as $table)ensure((int)$probe->query(
        "SELECT COUNT(*) FROM {$table}")->fetchColumn()===0);

    $review=new Review($probe);
    ensure(!Review::authorizesGoogleHttp() &&
        !Review::authorizesPortalMembership() &&
        !Review::authorizesCustomerDisplay() && !Review::canPublish());
    $result=$review->inspect($principal,$property,$now,$dto);
    ensure(is_array($result)&&
        $result['contractVersion']==='portal-gsc-f106-identity-binding-review-lab-v1'&&
        $result['identityBindingRecorded']===true &&
        $result['recordedGrantFresh']===true &&
        $result['summary']['clicks']===$dto['summary']['clicks'] &&
        $result['summary']['impressions']===$dto['summary']['impressions']);
    foreach([
        'sessionAuthenticated','sessionMfaVerified',
        'tenantMembershipVerified','googlePropertyVerifiedNow',
        'sourceOriginVerified','customerDisplayAuthorized',
        'productionReadAuthorized','publicationAuthorized','networkPerformed'
    ] as $flag)ensure($result[$flag]===false);
    $output=json_encode($result,JSON_THROW_ON_ERROR);
    ensure(!str_contains($output,'example.com') &&
        !str_contains($output,$orgA) &&
        !str_contains($output,$userA) &&
        !str_contains($output,$actor));

    $denials=[
        [['organizationUuid'=>$orgB],$property,$now,$dto],
        [['identityUuid'=>$userB],$property,$now,$dto],
        [['organizationRevision'=>4],$property,$now,$dto],
        [['membershipRevision'=>6],$property,$now,$dto],
        [['sessionExpiresAt'=>$now],$property,$now,$dto],
        [['sessionIssuedAt'=>$now+1],$property,$now,$dto],
        [['sessionIssuedAt'=>$now-90000],$property,$now,$dto],
        [['mfaVerified'=>true],$property,$now,$dto],
        [['identityUuid'=>'not-a-uuid'],$property,$now,$dto],
        [[], 'sc-domain:example.net',$now,$dto],
        [[], $property,$now+1000,$dto],
        [[], $property,$now,array_merge($dto,['tenantRef'=>'other-tenant'])],
        [[], $property,$now,array_merge($dto,['propertyFingerprint'=>
            hash('sha256','sc-domain:example.net')])],
        [[], $property,$now,array_merge($dto,['customerDisplayAuthorized'=>true])],
        [[], $property,$now,array_merge($dto,['summary'=>array_merge(
            $dto['summary'],['clicks'=>999])])],
    ];
    $rejected=0;
    foreach ($denials as [$delta,$site,$clock,$response]) {
        $candidate=array_merge($principal,$delta);
        ensure($review->inspect($candidate,$site,$clock,$response)===null);
        $rejected++;
    }
    ensure(!$probe->inTransaction());
    foreach (['portal_gsc_identity_scope_links','portal_gsc_property_grants']
        as $table)ensure((int)$probe->query(
            "SELECT COUNT(*) FROM {$table}")->fetchColumn()===0);
    ensure((new Review($owner))->inspect($principal,$property,$now,$dto)===null);
    foreach([
        ['gsc_f106_privilege_probe','f106_ci_privileged_only'],
        ['gsc_f106_inherit_probe','f106_ci_inherit_only'],
    ] as [$role,$password]) {
        $elevated=new PDO($dsn,$role,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        ensure((new Review($elevated))->inspect($principal,$property,$now,$dto)===null);
        $elevated=null;
        $rejected++;
    }
    // Change the membership revision without issuing a new session;
    // stale revision must now be rejected, including for an active grant.
    $owner->prepare("UPDATE portal_gsc_identity_scope_links
        SET membership_revision=8 WHERE tenant_ref=:tenant AND actor_ref=:actor")
        ->execute(['tenant'=>$tenant,'actor'=>$actor]);
    ensure($review->inspect($principal,$property,$now,$dto)===null);
    $owner->prepare("UPDATE portal_gsc_identity_scope_links
        SET membership_revision=7 WHERE tenant_ref=:tenant AND actor_ref=:actor")
        ->execute(['tenant'=>$tenant,'actor'=>$actor]);
    ensure(is_array($review->inspect($principal,$property,$now,$dto)));
    $owner->prepare("UPDATE portal_gsc_identity_scope_links
        SET link_status='revoked',revoked_at=:clock
        WHERE tenant_ref=:tenant AND actor_ref=:actor")
        ->execute(['clock'=>$now,'tenant'=>$tenant,'actor'=>$actor]);
    ensure($review->inspect($principal,$property,$now,$dto)===null);
    $owner->prepare("UPDATE portal_gsc_identity_scope_links
        SET link_status='active',revoked_at=NULL
        WHERE tenant_ref=:tenant AND actor_ref=:actor")
        ->execute(['tenant'=>$tenant,'actor'=>$actor]);
    $owner->prepare("UPDATE portal_gsc_property_grants
        SET grant_status='revoked',revoked_at=:clock
        WHERE tenant_ref=:tenant AND actor_ref=:actor")
        ->execute(['clock'=>$now,'tenant'=>$tenant,'actor'=>$actor]);
    ensure($review->inspect($principal,$property,$now,$dto)===null);
    putenv('PORTAL_GSC_F106_BINDING_LAB=0');
    ensure($review->inspect($principal,$property,$now,$dto)===null);
    putenv('PORTAL_GSC_F106_BINDING_LAB=1');
    putenv('APP_ENV=production');
    ensure($review->inspect($principal,$property,$now,$dto)===null);
    echo "F106: atomic RLS identity+grant review, {$rejected} hostile ".
        "inputs rejected; revisions, revocations and privileged roles blocked\n";
} catch (Throwable) {
    fwrite(STDERR,"GSC F106: isolated identity review failed\n");exit(1);
} finally {
    $probe=null;
    if ($owner instanceof PDO) {
        try {
            $owner->exec("DELETE FROM portal_gsc_identity_scope_links
                WHERE tenant_ref IN ('imagine-demo','other-tenant')
                AND actor_ref IN ('review-operator','other-operator')");
            $owner->exec("DELETE FROM portal_gsc_property_grants
                WHERE tenant_ref IN ('imagine-demo','other-tenant')
                AND actor_ref IN ('review-operator','other-operator')");
            foreach(['gsc_f106_scope_probe','gsc_f106_privilege_probe',
                'gsc_f106_inherit_probe'] as $role) {
                $owner->exec("DROP OWNED BY {$role}");
                $owner->exec("DROP ROLE IF EXISTS {$role}");
            }
        } catch (Throwable) {}
    }
}
