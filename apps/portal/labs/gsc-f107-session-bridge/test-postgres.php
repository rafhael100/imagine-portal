<?php
declare(strict_types=1);

/**
 * F107 real PostgreSQL, synthetic first-party session observation.
 * Reader source MUST be checked out from exact pinned Portal commit.
 * This is not an HTTP test or a business authorization result.
 */
$root=dirname(__DIR__,2).'/app/Domain/SeoReview/';
foreach ([
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleRecordedPropertyGrant.php',
    'GoogleSearchConsoleTenantScopedTransaction.php',
    'OfflineOpenSeoDailyPreview.php',
    'OfflinePortalGscIdentityBindingReview.php',
    'OfflinePortalGscSessionCandidateReview.php',
] as $f) require_once $root.$f;

if (PHP_SAPI!=='cli'||$argc!==3||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_F107_SESSION_LAB')!=='1'||
    getenv('PORTAL_GSC_F106_BINDING_LAB')!=='1'||
    getenv('PORTAL_OPENSEO_DAILY_LAB')!=='1'||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
    'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test') {
    fwrite(STDERR,"F107: disposable CLI PostgreSQL only\n");exit(2);
}
if (basename($argv[2])!=='PostgresPortalSessionPrincipalCandidateReader.php') {
    fwrite(STDERR,"F107: pinned identity reader source required\n");exit(2);
}
require_once $argv[2];

use App\Infrastructure\Identity\PostgresPortalSessionPrincipalCandidateReader as Reader;
use App\Domain\SeoReview\OfflinePortalGscIdentityBindingReview as RecordedReview;
use App\Domain\SeoReview\OfflinePortalGscSessionCandidateReview as Bridge;

function must(bool $v):void {
    if(!$v)throw new RuntimeException('F107 fail-closed review assertion');
}
$owner=null;$identityDb=null;$gscDb=null;
$now=time();
$org='c8a2d30a-82dd-48e7-8c51-f313d7469681';
$actor='46fd090a-11fd-4b68-917b-c3ae903dac9c';
$member='3305d451-b6ce-4e2b-81a7-28285a5a6261';
$otherOrg='197d34aa-5750-4efe-bb11-d35bd8d23122';
$property='sc-domain:example.com';
$tenant='imagine-demo';
$gscActor='review-operator';
$ref=bin2hex(random_bytes(32));
try {
    $bytes=file_get_contents($argv[1]);
    must(is_string($bytes)&&strlen($bytes)<65536);
    $dto=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
    must(is_array($dto));
    $dsn=getenv('PORTAL_GSC_LEDGER_DSN');
    $owner=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    $owner->exec("CREATE ROLE gsc_f107_limited_probe LOGIN
        PASSWORD 'f107_gsc_ci_only'
        NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS");
    $owner->exec("GRANT CONNECT ON DATABASE imagine_gsc_ci_test
        TO gsc_f107_limited_probe");
    $owner->exec("GRANT USAGE ON SCHEMA public TO gsc_f107_limited_probe");
    $owner->exec("GRANT SELECT ON portal_gsc_property_grants,
        portal_gsc_identity_scope_links TO gsc_f107_limited_probe");

    $owner->prepare("INSERT INTO portal_identities
        (id,status,security_revision,email_verified_at,mfa_confirmed_at)
        VALUES (?,'active',1,now()-interval '2 hours',now()-interval '2 hours')")
        ->execute([$actor]);
    $owner->prepare("INSERT INTO portal_organizations
        (id,status,security_revision) VALUES (?,'active',5)")
        ->execute([$org]);
    $owner->prepare("INSERT INTO portal_memberships
        (id,identity_id,organization_id,status,membership_revision)
        VALUES (?,?,?,'active',7)")->execute([$member,$actor,$org]);
    $owner->prepare("INSERT INTO portal_auth_sessions
        (session_sha256,identity_id,organization_id,status,session_purpose,
        issued_at,expires_at,mfa_verified_at,identity_revision,
        membership_revision,organization_revision)
        VALUES (?,?,?,'active','business',now()-interval '2 minutes',
        now()+interval '30 minutes',now()-interval '45 seconds',1,7,5)")
        ->execute([hash('sha256',$ref),$actor,$org]);
    $owner->prepare("INSERT INTO portal_gsc_identity_scope_links
        (portal_organization_id,portal_identity_id,tenant_ref,actor_ref,
        organization_revision,membership_revision,issued_at,expires_at,
        link_status,revoked_at)
        VALUES (?,?,?,?,5,7,?,?, 'active',NULL)")
        ->execute([$org,$actor,$tenant,$gscActor,$now-100,$now+1500]);
    $owner->prepare("INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
        verified_at,expires_at)
        VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly',
        'active',?,?)")
        ->execute([$tenant,$gscActor,hash('sha256',$property),
            $now-30,$now+900]);

    $identityDb=new PDO($dsn,'portal_browser_runtime','f107_local_only',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $gscDb=new PDO($dsn,'gsc_f107_limited_probe','f107_gsc_ci_only',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    foreach(['portal_identities','portal_auth_sessions',
        'portal_gsc_property_grants'] as $t) {
        // The identity reader has EXECUTE-only access, not even SELECT
        // privilege on these tables. Query role ACL from the lab owner.
        $priv=$owner->prepare(
            "SELECT has_table_privilege('portal_browser_runtime',:table,'SELECT')"
        );
        $priv->execute(['table'=>'public.'.$t]);
        must(in_array($priv->fetchColumn(),[false,'f','0',0],true));
    }
    must((int)$gscDb->query(
        'SELECT count(*) FROM portal_gsc_property_grants'
    )->fetchColumn()===0);
    $reader=new Reader($identityDb,'imagine_gsc_ci_test');
    $reviewer=new Bridge($reader,new RecordedReview($gscDb));
    $inspect=static fn(string $reference,string $expectedOrg) =>
        $reviewer->inspect($reference,$expectedOrg,$property,time(),$dto);

    $positive=$inspect($ref,$org);
    must(is_array($positive));
    must($positive['contractVersion']===
        'portal-gsc-f107-session-observation-lab-v1');
    must($positive['status']===
        'OBSERVED_SESSION_AND_RECORDED_GSC_NOT_AUTHORIZATION');
    must($positive['summary']['clicks']===$dto['summary']['clicks']);
    foreach([
        'singleTransactionAcrossIdentityAndGsc',
        'sessionAuthenticatedForProduction','sessionRevocationFenced',
        'googleOAuthVerifiedNow','customerDisplayAuthorized',
        'sourceOriginVerified','productionReadAuthorized',
        'publicationAuthorized','networkPerformed',
    ] as $flag)must($positive[$flag]===false);
    must($positive['firstPartySessionRowObserved']===true);
    must($positive['recordedIdentityLinkObserved']===true);
    must($positive['recordedGscGrantObserved']===true);
    $encoded=json_encode($positive,JSON_THROW_ON_ERROR);
    foreach([$ref,$actor,$org,$gscActor,$property] as $secret)
        must(!str_contains($encoded,$secret));
    must(!Bridge::canAuthorizeCustomer()&&!Bridge::canPublish()&&
        !Bridge::canQueryGoogle());

    $bad=0;
    foreach([
        [$ref,$otherOrg],
        [str_repeat('0',64),$org],
        [strtoupper($ref),$org],
        [$ref.'x',$org],
        ['crm-session',$org]
    ] as [$session,$organization]) {
        must($inspect($session,$organization)===null);$bad++;
    }
    $owner->prepare("UPDATE portal_auth_sessions
        SET session_purpose='invitation' WHERE session_sha256=?")
        ->execute([hash('sha256',$ref)]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_auth_sessions
        SET session_purpose='business',mfa_verified_at=NULL
        WHERE session_sha256=?")->execute([hash('sha256',$ref)]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_auth_sessions
        SET mfa_verified_at=now()-interval '45 seconds',
            status='revoked',revoked_at=now()
        WHERE session_sha256=?")->execute([hash('sha256',$ref)]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_auth_sessions
        SET status='active',revoked_at=NULL,
            expires_at=now()-interval '1 minute'
        WHERE session_sha256=?")->execute([hash('sha256',$ref)]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_auth_sessions
        SET expires_at=now()+interval '30 minutes',
            membership_revision=6 WHERE session_sha256=?")
        ->execute([hash('sha256',$ref)]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_auth_sessions
        SET membership_revision=7 WHERE session_sha256=?")
        ->execute([hash('sha256',$ref)]);
    must(is_array($inspect($ref,$org)));
    $owner->prepare("UPDATE portal_organizations
        SET security_revision=6 WHERE id=?")->execute([$org]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_organizations
        SET security_revision=5 WHERE id=?")->execute([$org]);
    $owner->prepare("UPDATE portal_memberships SET status='suspended'
        WHERE identity_id=? AND organization_id=?")
        ->execute([$actor,$org]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_memberships SET status='active'
        WHERE identity_id=? AND organization_id=?")
        ->execute([$actor,$org]);
    must(is_array($inspect($ref,$org)));

    $owner->prepare("UPDATE portal_gsc_identity_scope_links
        SET membership_revision=8 WHERE tenant_ref=? AND actor_ref=?")
        ->execute([$tenant,$gscActor]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_gsc_identity_scope_links
        SET membership_revision=7 WHERE tenant_ref=? AND actor_ref=?")
        ->execute([$tenant,$gscActor]);
    $owner->prepare("UPDATE portal_gsc_property_grants
        SET grant_status='revoked',revoked_at=?
        WHERE tenant_ref=? AND actor_ref=?")
        ->execute([$now,$tenant,$gscActor]);
    must($inspect($ref,$org)===null);$bad++;
    $owner->prepare("UPDATE portal_gsc_property_grants
        SET grant_status='active',revoked_at=NULL
        WHERE tenant_ref=? AND actor_ref=?")
        ->execute([$tenant,$gscActor]);
    putenv('PORTAL_GSC_F107_SESSION_LAB=0');
    must($inspect($ref,$org)===null);$bad++;
    putenv('PORTAL_GSC_F107_SESSION_LAB=1');
    putenv('APP_ENV=production');
    must($inspect($ref,$org)===null);$bad++;
    putenv('APP_ENV=testing');
    must(is_array($inspect($ref,$org)));

    // Existing reader requires a uniquely constrained first-party
    // PostgreSQL role. Our GSC admin or scope role is not a session reader.
    try {
        new Reader($owner,'imagine_gsc_ci_test');
        must(false);
    }catch(RuntimeException){$bad++;}
    must(!$gscDb->inTransaction());
    must(!$identityDb->inTransaction());

    echo "F107: first-party candidate SQL reader + GSC RLS ".
        "combined; {$bad} negative cases denied; NO CUSTOMER AUTHORITY\n";
}catch(Throwable) {
    fwrite(STDERR,"F107: synthetic first-party session bridge failed\n");
    exit(1);
}finally{
    $identityDb=null;$gscDb=null;
    if($owner instanceof PDO){
        try{
            $owner->prepare("DELETE FROM portal_auth_sessions
                WHERE session_sha256=?")->execute([hash('sha256',$ref)]);
            $owner->prepare("DELETE FROM portal_memberships
                WHERE identity_id=?")->execute([$actor]);
            $owner->prepare("DELETE FROM portal_identities
                WHERE id=?")->execute([$actor]);
            $owner->prepare("DELETE FROM portal_organizations
                WHERE id=?")->execute([$org]);
            $owner->prepare("DELETE FROM portal_gsc_identity_scope_links
                WHERE tenant_ref=? AND actor_ref=?")
                ->execute([$tenant,$gscActor]);
            $owner->prepare("DELETE FROM portal_gsc_property_grants
                WHERE tenant_ref=? AND actor_ref=?")
                ->execute([$tenant,$gscActor]);
            $owner->exec('DROP OWNED BY gsc_f107_limited_probe');
            $owner->exec('DROP ROLE IF EXISTS gsc_f107_limited_probe');
        }catch(Throwable){}
    }
}
