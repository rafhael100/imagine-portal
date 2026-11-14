<?php
declare(strict_types=1);

/**
 * Portal GSC F114: issue an actual *synthetic* business session through the
 * Portal's Argon2id/TOTP database adapter, then check recorded GSC link and
 * explicit seo.gsc.view under ONE real PostgreSQL18 RLS transaction.
 * NO customer session, Google OAuth, HTTP route or release authorization.
 */
if(PHP_SAPI!=='cli'||$argc!==3||
   getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_F114_OAUTH_LAB')!=='1'||
   getenv('PORTAL_GSC_F115_CALLBACK_LAB')!=='1'||
   getenv('PORTAL_GSC_F109_VIEW_LAB')!=='1'||
   getenv('PORTAL_GSC_F108_ATOMIC_LAB')!=='1'||
   getenv('PORTAL_OPENSEO_DAILY_LAB')!=='1'||
   getenv('PGHOST')!=='127.0.0.1'||
   getenv('PGDATABASE')!=='portal_gsc_f115_lab'||
   basename($argv[2])!=='OfflinePortalGscMetricsGrantReview.php'){
    fwrite(STDERR,"F114 private PostgreSQL18 proof only\n");exit(2);
}
require_once dirname(__DIR__,2).'/vendor/autoload.php';
$root=dirname($argv[2]);
foreach([
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsoleOAuthStateLedger.php',
    'GoogleSearchConsoleOAuthCallbackPolicy.php',
    'GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleTenantScopedTransaction.php',
    'OfflineOpenSeoDailyPreview.php',
    'OfflinePortalGscMetricsGrantReview.php',
] as $f)require_once $root.'/'.$f;

use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication as Browser;
use App\Infrastructure\Identity\PostgresPortalPermissionPreflight as PermissionPreflight;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\SeoReview\OfflinePortalGscMetricsGrantReview as Gate;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthStateLedger as Ledger;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\OfflinePortalGscOAuthStateStartReview as Issuer;
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflinePortalGscOAuthStateStartReview.php';

function requireF114(bool $b):void {
    if(!$b) {
        // Emit only a source line number. Never print PDO errors, secret
        // values, bearer references, payloads or provider responses in CI.
        $caller=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1)[0]??[];
        throw new RuntimeException('F418 assertion line '.(int)($caller['line']??0));
    }
}
$sessionRef=null;$pendingRef=null;
$org='22222222-2222-4222-8222-222222222220';
$user='11111111-1111-4111-8111-111111111110';
$member='33333333-3333-4333-8333-333333333330';
$connect='44444444-4444-4444-8444-444444444440';
$view='55555555-5555-4555-8555-555555555550';
$gscTenant='imagine-demo';$gscActor='review-operator';
$site='sc-domain:example.com';$now=time();
$owner=null;$browserPdo=null;$gscPdo=null;$revoker=null;
$stateDb=null;$issuerDb=null;
try{
    $payload=file_get_contents($argv[1]);
    requireF114(is_string($payload)&&strlen($payload)<65536);
    $dto=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
    requireF114(is_array($dto));
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f115_lab';
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false];
    $owner=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),$opts);
    requireF114((int)$owner->query(
        "SELECT current_setting('server_version_num')::integer/10000"
    )->fetchColumn()===18);
    $browserPdo=new PDO($dsn,'portal_browser_runtime',
        'f114_browser_runtime_only',$opts);
    $gscPdo=new PDO($dsn,'portal_gsc_f108_reader',
        'f108_ci_synthetic_only',$opts);
    $revoker=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),$opts);
    foreach(['portal_auth_sessions','portal_memberships',
        'portal_membership_grants','portal_gsc_property_grants'] as $t){
        $acl=$owner->prepare("SELECT
            has_table_privilege('portal_gsc_f108_reader',:table,'SELECT'),
            has_table_privilege('portal_browser_runtime',:table,'SELECT')");
        $acl->execute(['table'=>'public.'.$t]);
        foreach($acl->fetch(PDO::FETCH_NUM) as $permission)
            requireF114(in_array($permission,[false,'f',0,'0'],true));
    }
    $proof=bin2hex(random_bytes(32));
    $key=random_bytes(32);
    $pepper=bin2hex(random_bytes(32));
    $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $hash=PortalPasswordCredential::makeHash('synthetic-password-only');
    $sealed=PortalTotpSecretCipher::seal($secret,$key);
    requireF114(is_string($hash)&&str_starts_with($hash,'$argon2id$')&&
        is_string($sealed));
    $owner->prepare("INSERT INTO portal_browser_private.proof_key(secret)
        VALUES (?)")->execute([$proof]);
    $owner->prepare("INSERT INTO portal_identities
        (id,email_normalized,status,password_hash,mfa_secret_ciphertext,
         email_verified_at,mfa_confirmed_at,security_revision,created_at,updated_at)
        VALUES (?,'f114@example.invalid','active',?,?,
          now()-interval '1 hour',now()-interval '1 hour',1,now(),now())")
        ->execute([$user,$hash,$sealed]);
    $owner->prepare("INSERT INTO portal_organizations
        (id,reference,display_name,status,security_revision,created_at,updated_at)
        VALUES (?,'f114-demo','Synthetic F114','active',5,now(),now())")
        ->execute([$org]);
    $owner->prepare("INSERT INTO portal_memberships
        (id,identity_id,organization_id,role,status,membership_revision,
         created_at,updated_at)
        VALUES (?,?,?,'member','active',1,now(),now())")
        ->execute([$member,$user,$org]);
    $add=$owner->prepare("INSERT INTO portal_membership_grants
        (id,membership_id,permission_code,created_at,updated_at)
        VALUES (?,?,?,now(),now())");
    $add->execute([$connect,$member,'seo.gsc.connect']);
    $add->execute([$view,$member,'seo.gsc.view']);

    $revision=(int)$owner->query(
        "SELECT membership_revision FROM portal_memberships")->fetchColumn();
    // F154 triggers from the real identity stack rotate the membership
    // revision on each explicit grant insertion (1 -> 2 -> 3).
    requireF114($revision===3);
    $owner->prepare("INSERT INTO portal_gsc_identity_scope_links
        (portal_organization_id,portal_identity_id,tenant_ref,actor_ref,
        organization_revision,membership_revision,issued_at,expires_at,
        link_status,revoked_at)
        VALUES (?,?,?,?,5,?,?,?,'active',NULL)")
        ->execute([$org,$user,$gscTenant,$gscActor,$revision,$now-100,$now+900]);
    $owner->prepare("INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
        verified_at,expires_at)
        VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly',
        'active',?,?)")
        ->execute([$gscTenant,$gscActor,hash('sha256',$site),$now-30,$now+600]);

    $auth=new Browser($browserPdo,'portal_gsc_f115_lab',$proof,$key,$pepper);
    $preflight=new PermissionPreflight($browserPdo,'portal_gsc_f115_lab');
    $pendingRef=$auth->beginPassword('f114@example.invalid',
        'synthetic-password-only',$org,'192.0.2.113');
    requireF114(is_string($pendingRef)&&strlen($pendingRef)===64);
    requireF114(!$auth->isActiveBusinessSession($pendingRef));
    $counter=(int)$owner->query(
        "SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint"
    )->fetchColumn();
    $digest=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),
        $counter%4294967296),'12345678901234567890',true);
    $offset=ord($digest[19])&15;
    $code=str_pad((string)((unpack('N',substr($digest,$offset,4))[1]&0x7fffffff)
        %1000000),6,'0',STR_PAD_LEFT);
    $sessionRef=$auth->completeMfa($pendingRef,$code,'192.0.2.113');
    requireF114(is_string($sessionRef)&&strlen($sessionRef)===64);
    requireF114($sessionRef!==$pendingRef);
    requireF114($auth->isActiveBusinessSession($sessionRef));
    requireF114(!$preflight->hasCandidateGrant($pendingRef,$org,'seo.gsc.view'));
    requireF114($preflight->hasCandidateGrant($sessionRef,$org,'seo.gsc.view'));
    requireF114($preflight->hasCandidateGrant($sessionRef,$org,'seo.gsc.connect'));
    requireF114(!$preflight->hasCandidateGrant($sessionRef,$org,'seo.gsc.*'));


    // F114: use the real first-party MFA bearer from the Portal browser
    // adapter to mint a PKCE state with NO HTTP and no browser-provided IDs.
    $stateKey=random_bytes(32);
    $registration=[
        'clientId'=>'1234567890-syntheticf114.apps.googleusercontent.com',
        'registeredRedirectUri'=>
          'https://portal.example.test/integrations/google/search-console/callback',
    ];
    $issuerDb=new PDO($dsn,'portal_gsc_f114_runtime',
        'f114_disposable_runtime_only',$opts);
    $stateDb=new PDO($dsn,'portal_gsc_f114_state_reader',
        'f114_disposable_state_reader_only',$opts);
    $issuer=new Issuer($issuerDb,$stateKey,$registration);
    requireF114(!Issuer::canAuthorizeCustomer()&&
        !Issuer::canCallGoogle()&&!Issuer::canPersistOAuthTokens());
    requireF114($issuer->begin($pendingRef,$org,time())===null);
    requireF114($issuer->begin(str_repeat('0',64),$org,time())===null);
    requireF114($issuer->begin($sessionRef,
        '197d34aa-5750-4efe-bb11-d35bd8d23122',time())===null);
    requireF114($issuer->begin(strtoupper($sessionRef),$org,time())===null);
    $lockAttempts=0;
    $onStartLocked=function()use($revoker,$sessionRef,$connect,&$lockAttempts):bool{
        foreach([
            ["UPDATE portal_auth_sessions SET status='revoked',revoked_at=now()
              WHERE session_sha256=?",hash('sha256',$sessionRef)],
            ["DELETE FROM portal_membership_grants WHERE id=?",$connect],
        ] as [$sql,$arg]){
            $revoker->beginTransaction();
            try{
                $revoker->exec("SET LOCAL lock_timeout='150ms'");
                $revoker->prepare($sql)->execute([$arg]);
                $revoker->rollBack();
                return false;
            }catch(PDOException $e){
                if($revoker->inTransaction())$revoker->rollBack();
                if($e->getCode()!=='55P03')return false;
                $lockAttempts++;
            }
        }
        return true;
    };
    $started=$issuer->begin($sessionRef,$org,time(),$onStartLocked);
    requireF114(is_array($started)&&$lockAttempts===2);
    requireF114($started['contractVersion']===
        'portal-gsc-f114-session-bound-pkce-lab-v1');
    foreach(['stateStoredInRestrictedLedger','backendDerivedContext',
        'firstPartySessionCheckedInSameTransaction','explicitConnectGrantRequired',
        'connectDoesNotImplyView','stateOneTimeUseRequired'] as $flag)
        requireF114($started[$flag]===true);
    foreach(['oauthVerified','googleHttpPerformed','googleTokenStored',
        'propertyAuthorizationVerified','customerDisplayAuthorized',
        'productionAuthorized'] as $flag)requireF114($started[$flag]===false);
    $public=$started['publicAuthorization'];
    requireF114($public['scope']===GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE);
    requireF114($public['codeChallengeMethod']==='S256');
    requireF114(is_string($public['state'])&&strlen($public['state'])===43);
    requireF114(str_starts_with($public['url'],
        'https://accounts.google.com/o/oauth2/v2/auth?'));
    $serialized=json_encode($started,JSON_THROW_ON_ERROR);
    foreach([$sessionRef,$pendingRef,$user,$org,$member,$connect,
        $stateKey] as $secretValue)
        requireF114(!str_contains($serialized,$secretValue));
    requireF114(!str_contains($serialized,'code_verifier='));
    requireF114(!str_contains($serialized,'client_secret='));

    $ctx=[
        'tenantRef'=>'porg-'.str_replace('-','',$org),
        'actorRef'=>'pusr-'.str_replace('-','',$user),
    ];
    $ledger=new Ledger($stateDb,$stateKey);
    // RLS in the existing GSC migration allows tenant alone. The new
    // RESTRICTIVE policy must additionally require the EXACT actor.
    requireF114($ledger->inspectPending($public['state'],$ctx,time())===null);
    $stateDb->beginTransaction();
    $stateDb->prepare(
        "SELECT set_config('app.tenant_ref',?,true),
                set_config('app.actor_ref',?,true)"
    )->execute([$ctx['tenantRef'],'pusr-'.str_repeat('0',32)]);
    requireF114($ledger->inspectPending($public['state'],$ctx,time())===null);
    $stateDb->rollBack();
    $stateDb->beginTransaction();
    $stateDb->prepare(
        "SELECT set_config('app.tenant_ref',?,true),
                set_config('app.actor_ref',?,true)"
    )->execute(['porg-'.str_repeat('0',32),$ctx['actorRef']]);
    requireF114($ledger->inspectPending($public['state'],$ctx,time())===null);
    $stateDb->rollBack();
    $stateDb->beginTransaction();
    $stateDb->prepare(
        "SELECT set_config('app.tenant_ref',?,true),
                set_config('app.actor_ref',?,true)"
    )->execute([$ctx['tenantRef'],$ctx['actorRef']]);
    $pending=$ledger->inspectPending($public['state'],$ctx,time());
    requireF114(is_array($pending)&&
        $pending['tenantRef']===$ctx['tenantRef']&&
        $pending['actorRef']===$ctx['actorRef']&&
        is_string($pending['codeVerifier'])&&
        preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$pending['codeVerifier'])===1);
    requireF114($ledger->inspectPending($public['state'],[
        'tenantRef'=>'other-tenant','actorRef'=>$ctx['actorRef'],
    ],time())===null);
    requireF114($ledger->inspectPending($public['state'],[
        'tenantRef'=>$ctx['tenantRef'],'actorRef'=>'other-actor',
    ],time())===null);
    // The state is persistently tied to the exact issuing session SHA
    // and membership security revision, not to an arbitrary later login
    // of the same actor in the same organization.
    $bind=$owner->prepare("SELECT session_sha256,organization_uuid,
        membership_revision FROM portal_gsc_f115_state_session_bindings
        WHERE state_hash=?");
    $bind->execute([hash('sha256',$public['state'])]);
    $origin=$bind->fetch(PDO::FETCH_ASSOC);
    requireF114(is_array($origin) &&
        $origin['session_sha256']===hash('sha256',$sessionRef) &&
        $origin['organization_uuid']===$org &&
        (int)$origin['membership_revision']===3);
    $stateDb->commit();
    $callback=['state'=>$public['state'],
        'code'=>'synthetic-authorization-code-f115'];
    requireF114($issuer->reviewBoundCallback(str_repeat('0',64),
        $org,$callback,time())===null);
    requireF114($issuer->reviewBoundCallback($pendingRef,
        $org,$callback,time())===null);
    requireF114($issuer->reviewBoundCallback($sessionRef,
        '197d34aa-5750-4efe-bb11-d35bd8d23122',
        $callback,time())===null);
    requireF114($issuer->reviewBoundCallback($sessionRef,
        $org,['state'=>$public['state'],'code'=>'invalid code'],
        time())===null);

    $callbackLockCount=0;
    $onCallbackLocked=function()use(
        $revoker,$sessionRef,$connect,&$callbackLockCount
    ):bool{
        foreach([
            ["UPDATE portal_auth_sessions SET status='revoked',revoked_at=now()
              WHERE session_sha256=?",hash('sha256',$sessionRef)],
            ["DELETE FROM portal_membership_grants WHERE id=?",$connect],
        ] as [$sql,$arg]){
            $revoker->beginTransaction();
            try{
                $revoker->exec("SET LOCAL lock_timeout='150ms'");
                $revoker->prepare($sql)->execute([$arg]);
                $revoker->rollBack();
                return false;
            }catch(PDOException $e){
                if($revoker->inTransaction())$revoker->rollBack();
                if($e->getCode()!=='55P03')return false;
                $callbackLockCount++;
            }
        }
        return true;
    };
    $reviewed=$issuer->reviewBoundCallback(
        $sessionRef,$org,$callback,time(),$onCallbackLocked
    );
    requireF114(is_array($reviewed)&&$callbackLockCount===2);
    requireF114($reviewed['contractVersion']===
        'portal-gsc-f115-session-bound-callback-lab-v1');
    foreach(['originalSessionBindingVerified',
        'originalMembershipRevisionVerified',
        'explicitConnectGrantVerifiedAtConsumption',
        'stateConsumedOnce','callbackCodeHandledOnlyInServerMemory']
        as $flag)requireF114($reviewed[$flag]===true);
    foreach(['googleOAuthVerified','tokenExchangeAuthorized',
        'googleHttpPerformed','tokenPersistenceAuthorized',
        'customerDisplayAuthorized','productionAuthorized']
        as $flag)requireF114($reviewed[$flag]===false);
    $safe=json_encode($reviewed,JSON_THROW_ON_ERROR);
    foreach([$callback['state'],$callback['code'],$sessionRef,
        $pendingRef,$stateKey,$user,$org,$member,$site]
        as $secretValue)requireF114(!str_contains($safe,$secretValue));
    requireF114($issuer->reviewBoundCallback(
        $sessionRef,$org,$callback,time())===null);

    // The original read-only, actor+tenant scoped ledger must also see
    // the state as consumed, never available to a different actor or a
    // second callback.
    $stateDb->beginTransaction();
    $stateDb->prepare(
        "SELECT set_config('app.tenant_ref',?,true),
                set_config('app.actor_ref',?,true)"
    )->execute([$ctx['tenantRef'],$ctx['actorRef']]);
    requireF114($ledger->inspectPending($public['state'],$ctx,time())===null);
    requireF114($ledger->consume($public['state'],$ctx,time())===null);
    $stateDb->commit();
    $isConsumed=$owner->prepare(
        'SELECT consumed_at FROM portal_gsc_oauth_states WHERE state_hash=?'
    );
    $isConsumed->execute([hash('sha256',$public['state'])]);
    requireF114($isConsumed->fetchColumn()!==null);

    // Create a second pending state but do not consume it. Actual Portal
    // logout MUST prevent its callback even though OAuth state has not
    // expired, demonstrating revocation of the ORIGINAL issuing bearer.
    $leftover=$issuer->begin($sessionRef,$org,time());
    requireF114(is_array($leftover) &&
        strlen($leftover['publicAuthorization']['state'])===43);
    requireF114(!$stateDb->inTransaction()&&!$issuerDb->inTransaction());

    putenv('PORTAL_GSC_F114_OAUTH_LAB=0');
    requireF114($issuer->begin($sessionRef,$org,time())===null);
    putenv('PORTAL_GSC_F114_OAUTH_LAB=1');

    $gate=new Gate($gscPdo);
    $inspect=static fn(?Closure $hook=null) =>
        $gate->inspect($sessionRef,$org,$site,time(),$dto,$hook);
    $review=$inspect();
    requireF114(is_array($review)&&
        $review['contractVersion']==='portal-gsc-f109-view-grant-review-lab-v1'&&
        $review['firstPartySessionAndGscRowsObservedAtomically']===true&&
        $review['explicitSeoGscViewRowObserved']===true&&
        $review['summary']['clicks']===$dto['summary']['clicks']);
    foreach([
        'sessionAuthenticatedForProduction',
        'seoViewPermissionAuthorizedForProduction',
        'googleOAuthVerifiedNow','sourceOriginVerified',
        'customerDisplayAuthorized','productionReadAuthorized',
        'publicationAuthorized','networkPerformed'
    ] as $flag)requireF114($review[$flag]===false);
    $json=json_encode($review,JSON_THROW_ON_ERROR);
    foreach([$pendingRef,$sessionRef,$user,$org,$member,$site,$gscActor]
        as $s)requireF114(!str_contains($json,$s));

    $locked=0;
    foreach([
        ["UPDATE portal_auth_sessions SET status='revoked',revoked_at=now()
          WHERE session_sha256=?",hash('sha256',$sessionRef)],
        ["DELETE FROM portal_membership_grants WHERE id=?",$view]
    ] as [$sql,$arg]){
        $onLocked=function()use($revoker,$sql,$arg,&$locked):bool{
            $revoker->beginTransaction();
            try{
                $revoker->exec("SET LOCAL lock_timeout='150ms'");
                $revoker->prepare($sql)->execute([$arg]);
                $revoker->rollBack();
                return false;
            }catch(PDOException $e){
                if($revoker->inTransaction())$revoker->rollBack();
                if($e->getCode()!=='55P03')return false;
                $locked++;
                return true;
            }
        };
        requireF114(is_array($inspect($onLocked)));
    }
    requireF114($locked===2&&!$gscPdo->inTransaction());

    requireF114($gate->inspect(str_repeat('0',64),
        $org,$site,time(),$dto)===null);
    requireF114($gate->inspect($sessionRef,
        '197d34aa-5750-4efe-bb11-d35bd8d23122',
        $site,time(),$dto)===null);
    requireF114($gate->inspect($pendingRef,
        $org,$site,time(),$dto)===null);
    // A real logout, not an admin SQL UPDATE, invalidates BOTH identity
    // preflight and the atomic GSC observation after commit.
    requireF114($auth->revoke($sessionRef));
    requireF114(!$auth->isActiveBusinessSession($sessionRef));
    requireF114(!$preflight->hasCandidateGrant($sessionRef,$org,'seo.gsc.view'));
    requireF114($inspect()===null);
    requireF114($issuer->begin($sessionRef,$org,time())===null);
    requireF114($issuer->reviewBoundCallback(
        $sessionRef,$org,[
          'state'=>$leftover['publicAuthorization']['state'],
          'code'=>'synthetic-authorization-code-f115'
        ],time())===null);
    $notConsumed=$owner->prepare(
        'SELECT consumed_at FROM portal_gsc_oauth_states WHERE state_hash=?'
    );
    $notConsumed->execute([
        hash('sha256',$leftover['publicAuthorization']['state'])
    ]);
    requireF114($notConsumed->fetchColumn()===null);
    putenv('PORTAL_GSC_F109_VIEW_LAB=0');
    requireF114($inspect()===null);
    putenv('PORTAL_GSC_F109_VIEW_LAB=1');
    putenv('APP_ENV=production');
    requireF114($inspect()===null);
    putenv('APP_ENV=testing');

    echo "F115: exact original-session/state binding; 2 initiation + ".
        "2 callback concurrent revocations fenced + 2 GSC fences; ".
        "one-use callback, replay and post-logout denial; NO GOOGLE HTTP\n";
}catch(Throwable $error){
    // Source coordinates only: exception messages may contain secrets.
    $place=basename($error->getFile()).':'.(int)$error->getLine();
    $stage=$error instanceof RuntimeException
        && str_starts_with($error->getMessage(),'F418 assertion line ')
        ? $error->getMessage() : get_class($error);
    fwrite(STDERR,"F418 callback integration refused at ".$place." / ".$stage."\n");
    exit(1);
}finally{
    $stateDb=null;$issuerDb=null;$gscPdo=null;$browserPdo=null;$revoker=null;
    if($owner instanceof PDO){
        try{
            $owner->exec('DELETE FROM portal_auth_attempt_buckets');
            $owner->prepare('DELETE FROM portal_membership_grants
                WHERE membership_id=?')->execute([$member]);
            $owner->prepare('DELETE FROM portal_auth_sessions
                WHERE identity_id=?')->execute([$user]);
            $owner->prepare('DELETE FROM portal_memberships
                WHERE id=?')->execute([$member]);
            $owner->prepare('DELETE FROM portal_identities
                WHERE id=?')->execute([$user]);
            $owner->prepare('DELETE FROM portal_organizations
                WHERE id=?')->execute([$org]);
            $owner->prepare('DELETE FROM portal_gsc_identity_scope_links
                WHERE tenant_ref=?')->execute([$gscTenant]);
            $owner->prepare('DELETE FROM portal_gsc_property_grants
                WHERE tenant_ref=?')->execute([$gscTenant]);
            $owner->exec('DELETE FROM portal_browser_private.proof_key');
        }catch(Throwable){}
    }
}
