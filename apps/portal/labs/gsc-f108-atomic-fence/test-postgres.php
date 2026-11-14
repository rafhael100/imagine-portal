<?php
declare(strict_types=1);

/**
 * F108 — disposable PostgreSQL16 forced RLS/row-lock cross-module test.
 * No actual Portal HTTP session or Google credentials.
 */
$root=dirname(__DIR__,2).'/app/Domain/SeoReview/';
foreach ([
 'GoogleSearchConsoleOAuthPreflight.php',
 'GoogleSearchConsolePropertyIdentifier.php',
 'GoogleSearchConsoleTenantScopedTransaction.php',
 'OfflineOpenSeoDailyPreview.php',
 'OfflinePortalGscAtomicFenceReview.php',
] as $path) require_once $root.$path;

use App\Domain\SeoReview\OfflinePortalGscAtomicFenceReview as Atomic;

function checkF108(bool $value):void {
    if(!$value)throw new RuntimeException('F108 isolated concurrency proof failed');
}
if (PHP_SAPI!=='cli' || $argc!==2 ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_F108_ATOMIC_LAB')!=='1' ||
    getenv('PORTAL_OPENSEO_DAILY_LAB')!=='1' ||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test') {
    fwrite(STDERR,"F108 requires disposable PostgreSQL CI\n");exit(2);
}
$owner=null;$reader=null;$revoker=null;
$ref=bin2hex(random_bytes(32));
$org='c8a2d30a-82dd-48e7-8c51-f313d7469681';
$identity='46fd090a-11fd-4b68-917b-c3ae903dac9c';
$membership='3305d451-b6ce-4e2b-81a7-28285a5a6261';
$tenant='imagine-demo';$gscActor='review-operator';
$property='sc-domain:example.com';$fp=hash('sha256',$property);
$now=time();
$blocked=0;$denied=0;
try {
    $fixture=file_get_contents($argv[1]);
    checkF108(is_string($fixture)&&strlen($fixture)<65536);
    $dto=json_decode($fixture,true,16,JSON_THROW_ON_ERROR);
    checkF108(is_array($dto));
    $dsn=getenv('PORTAL_GSC_LEDGER_DSN');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $owner=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),$options);
    $reader=new PDO($dsn,'portal_gsc_f108_reader',
        'f108_ci_synthetic_only',$options);
    $revoker=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),$options);

    $role=$owner->query("SELECT rolcanlogin,rolsuper,rolbypassrls,
      rolcreatedb,rolcreaterole,rolinherit FROM pg_roles
      WHERE rolname='portal_gsc_f108_owner'")->fetch(PDO::FETCH_ASSOC);
    checkF108(is_array($role));
    foreach (['rolcanlogin','rolsuper','rolbypassrls','rolcreatedb',
        'rolcreaterole','rolinherit'] as $key)
        checkF108(in_array($role[$key],[false,'f',0,'0'],true));
    foreach(['portal_identities','portal_auth_sessions',
        'portal_gsc_identity_scope_links','portal_gsc_property_grants']
        as $table) {
        $q=$owner->prepare(
            "SELECT has_table_privilege('portal_gsc_f108_reader',:t,'SELECT'),
                    has_table_privilege('portal_gsc_f108_reader',:t,'UPDATE')"
        );
        $q->execute(['t'=>'public.'.$table]);
        $flags=$q->fetch(PDO::FETCH_NUM);
        checkF108(is_array($flags));
        foreach($flags as $flag)
            checkF108(in_array($flag,[false,'f',0,'0'],true));
        $rls=$owner->prepare("SELECT relrowsecurity,relforcerowsecurity
            FROM pg_class WHERE oid=to_regclass(:table)");
        $rls->execute(['table'=>'public.'.$table]);
        $flags=$rls->fetch(PDO::FETCH_NUM);
        checkF108(is_array($flags));
        foreach($flags as $flag)
            checkF108(in_array($flag,[true,'t',1,'1'],true));
    }
    checkF108(!Atomic::canAuthorizeCustomer() &&
        !Atomic::canQueryGoogle() && !Atomic::canPublish());

    $owner->prepare("INSERT INTO portal_identities
        (id,status,security_revision,email_verified_at,mfa_confirmed_at)
        VALUES (?,'active',1,now()-interval '1 hour',
                      now()-interval '1 hour')")->execute([$identity]);
    $owner->prepare("INSERT INTO portal_organizations
        (id,status,security_revision) VALUES (?,'active',5)")
        ->execute([$org]);
    $owner->prepare("INSERT INTO portal_memberships
        (id,identity_id,organization_id,status,membership_revision)
        VALUES (?,?,?,'active',7)")
        ->execute([$membership,$identity,$org]);
    $owner->prepare("INSERT INTO portal_auth_sessions
        (session_sha256,identity_id,organization_id,status,session_purpose,
        issued_at,expires_at,mfa_verified_at,identity_revision,
        membership_revision,organization_revision)
        VALUES (?,?,?,'active','business',now()-interval '2 minutes',
        now()+interval '30 minutes',now()-interval '45 seconds',1,7,5)")
        ->execute([hash('sha256',$ref),$identity,$org]);
    $owner->prepare("INSERT INTO portal_gsc_identity_scope_links
        (portal_organization_id,portal_identity_id,tenant_ref,actor_ref,
        organization_revision,membership_revision,issued_at,expires_at,
        link_status,revoked_at)
        VALUES (?,?,?,?,5,7,?,?, 'active',NULL)")
        ->execute([$org,$identity,$tenant,$gscActor,$now-90,$now+900]);
    $owner->prepare("INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
        verified_at,expires_at)
        VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly',
        'active',?,?)")
        ->execute([$tenant,$gscActor,$fp,$now-30,$now+600]);

    $guard=new Atomic($reader);
    $review=static fn(?Closure $interloper=null,
        mixed $session=null,mixed $orgUuid=null,mixed $site=null,
        mixed $metrics=null) => $guard->inspect(
          $session??$ref,$orgUuid??$org,$site??$property,time(),
          $metrics??$dto,$interloper
        );
    $ok=$review();
    checkF108(is_array($ok) &&
        $ok['contractVersion']==='portal-gsc-f108-atomic-review-lab-v1' &&
        $ok['rowLocksHeldDuringReview']===true &&
        $ok['sessionIssuedAtReadFromDatabase']===true &&
        $ok['firstPartyRowAndGscGrantReadInOneTransaction']===true &&
        $ok['summary']['clicks']===$dto['summary']['clicks']);
    foreach([
      'permissionForSeoVerified','sessionAuthenticatedForProduction',
      'googleOAuthVerifiedNow','sourceOriginVerified',
      'customerDisplayAuthorized','productionReadAuthorized',
      'publicationAuthorized','networkPerformed'
    ] as $flag)checkF108($ok[$flag]===false);
    $encoded=json_encode($ok,JSON_THROW_ON_ERROR);
    foreach([$ref,$identity,$membership,$org,$property,$gscActor]
       as $secret)checkF108(!str_contains($encoded,$secret));

    // The transaction holding the six row locks is deliberately still open
    // when another connection attempts to revoke each record. Every
    // contender must TIME OUT, not succeed, until the reviewer commits.
    $targets=[
      ['portal_auth_sessions','status','session_sha256',hash('sha256',$ref)],
      ['portal_identities','status','id',$identity],
      ['portal_organizations','status','id',$org],
      ['portal_memberships','status','id',$membership],
      ['portal_gsc_identity_scope_links','link_status','tenant_ref',$tenant],
      ['portal_gsc_property_grants','grant_status','tenant_ref',$tenant],
    ];
    foreach($targets as [$table,$valueField,$keyField,$keyValue]) {
        $probe=static function()use($revoker,$table,$valueField,
                        $keyField,$keyValue,&$blocked):bool {
            $revoker->beginTransaction();
            try{
                $revoker->exec("SET LOCAL lock_timeout='150ms'");
                $query=$revoker->prepare("UPDATE public.{$table}
                    SET {$valueField}={$valueField}
                    WHERE {$keyField}=:ref");
                $query->execute(['ref'=>$keyValue]);
                // If a writer was able to commit, the row is not fenced.
                $revoker->rollBack();
                return false;
            }catch(PDOException $e){
                if($revoker->inTransaction())$revoker->rollBack();
                if($e->getCode()!=='55P03')return false;
                $blocked++;
                return true;
            }
        };
        checkF108(is_array($review($probe)));
    }
    checkF108($blocked===count($targets));
    checkF108(!$reader->inTransaction()&&!$revoker->inTransaction());

    foreach([
       ['session'=>str_repeat('0',64)],
       ['session'=>strtoupper($ref)],
       ['session'=>$ref.'0'],
       ['orgUuid'=>'197d34aa-5750-4efe-bb11-d35bd8d23122'],
       ['site'=>'sc-domain:example.net'],
       ['metrics'=>array_merge($dto,['tenantRef'=>'other-tenant'])],
       ['metrics'=>array_merge($dto,['summary'=>array_merge($dto['summary'],
            ['clicks'=>999])])],
       ['metrics'=>array_merge($dto,['customerDisplayAuthorized'=>true])],
    ] as $candidate){
        $actual=$guard->inspect(
            $candidate['session']??$ref,
            $candidate['orgUuid']??$org,
            $candidate['site']??$property,
            time(),$candidate['metrics']??$dto
        );
        checkF108($actual===null);$denied++;
    }

    // Each committed revocation MUST invalidate a NEW observation.
    $mutations=[
      ["UPDATE portal_auth_sessions SET status='revoked',
           revoked_at=now() WHERE session_sha256=?",hash('sha256',$ref),
       "UPDATE portal_auth_sessions SET status='active',
           revoked_at=NULL WHERE session_sha256=?"],
      ["UPDATE portal_auth_sessions SET session_purpose='invitation'
           WHERE session_sha256=?",hash('sha256',$ref),
       "UPDATE portal_auth_sessions SET session_purpose='business'
           WHERE session_sha256=?"],
      ["UPDATE portal_auth_sessions SET mfa_verified_at=NULL
           WHERE session_sha256=?",hash('sha256',$ref),
       "UPDATE portal_auth_sessions SET mfa_verified_at=now()-interval '30 seconds'
           WHERE session_sha256=?"],
      ["UPDATE portal_memberships SET membership_revision=8
           WHERE id=?",$membership,
       "UPDATE portal_memberships SET membership_revision=7 WHERE id=?"],
      ["UPDATE portal_organizations SET security_revision=6
           WHERE id=?",$org,
       "UPDATE portal_organizations SET security_revision=5 WHERE id=?"],
      ["UPDATE portal_gsc_identity_scope_links
           SET link_status='revoked',revoked_at=? WHERE tenant_ref=?",
       [$now,$tenant],
       "UPDATE portal_gsc_identity_scope_links
           SET link_status='active',revoked_at=NULL WHERE tenant_ref=?",
       [$tenant]],
      ["UPDATE portal_gsc_property_grants
           SET grant_status='revoked',revoked_at=? WHERE tenant_ref=?",
       [$now,$tenant],
       "UPDATE portal_gsc_property_grants
           SET grant_status='active',revoked_at=NULL WHERE tenant_ref=?",
       [$tenant]],
    ];
    foreach($mutations as [$change,$arg,$restore,$restoreArg]){
        $args=is_array($arg)?$arg:[$arg];
        $owner->prepare($change)->execute($args);
        checkF108($review()===null);$denied++;
        $owner->prepare($restore)->execute(
            is_array($restoreArg)?$restoreArg:[$restoreArg]);
        checkF108(is_array($review()));
    }
    putenv('PORTAL_GSC_F108_ATOMIC_LAB=0');
    checkF108($review()===null);$denied++;
    putenv('PORTAL_GSC_F108_ATOMIC_LAB=1');
    putenv('APP_ENV=production');
    checkF108($review()===null);$denied++;
    putenv('APP_ENV=testing');
    checkF108((new Atomic($owner))->inspect(
        $ref,$org,$property,time(),$dto)===null);
    $denied++;
    checkF108($reader->inTransaction()===false);

    echo "F108: 6 concurrent UPDATE attempts blocked by row locks; ".
       "{$denied} hostile/revocation cases denied; genuine issuedAt observed ".
       "in one transaction; no customer authority\n";
}catch(Throwable $e) {
    fwrite(STDERR,"F108: isolated atomic fence proof failed\n");
    exit(1);
}finally {
    $reader=null;$revoker=null;
    if($owner instanceof PDO){
        try{
          $owner->prepare("DELETE FROM portal_auth_sessions
            WHERE session_sha256=?")->execute([hash('sha256',$ref)]);
          $owner->prepare("DELETE FROM portal_memberships
            WHERE identity_id=?")->execute([$identity]);
          $owner->prepare("DELETE FROM portal_identities
            WHERE id=?")->execute([$identity]);
          $owner->prepare("DELETE FROM portal_organizations
            WHERE id=?")->execute([$org]);
          $owner->prepare("DELETE FROM portal_gsc_identity_scope_links
            WHERE tenant_ref=?")->execute([$tenant]);
          $owner->prepare("DELETE FROM portal_gsc_property_grants
            WHERE tenant_ref=?")->execute([$tenant]);
        }catch(Throwable){}
    }
}
