<?php
declare(strict_types=1);

/** Real Laravel HTTP kernel + first-party PG18 identity; synthetic Google only. */
if (PHP_SAPI!=='cli' || $argc!==2 || getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_HTTP_KERNEL_LAB')!=='1' ||
    getenv('PGHOST')!=='127.0.0.1' || getenv('PGDATABASE')!=='portal_gsc_f115_lab' ||
    !extension_loaded('pdo_pgsql') || !extension_loaded('curl') ||
    !extension_loaded('sodium') || basename($argv[1])!=='SeoReview') {
    fwrite(STDERR,"GSC HTTP kernel: disposable CLI PostgreSQL18 laboratory required\n");exit(2);
}
$portal=dirname(__DIR__,2);
require $portal.'/vendor/autoload.php';
foreach (['GoogleSearchConsoleOAuthPreflight.php','GoogleSearchConsoleOAuthCallbackPolicy.php',
    'GoogleSearchConsoleOAuthTokenExchangePlan.php','GoogleSearchConsoleOAuthTokenResponsePolicy.php',
    'GoogleSearchConsolePropertyIdentifier.php','GoogleSearchConsoleEncryptedTokenVault.php'] as $file)
    require_once $argv[1].'/'.$file;
require __DIR__.'/HttpKernelLab.php';

use App\Domain\Identity\Browser\DenyingPortalBrowserAuthentication;
use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenVault as Vault;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenCandidate;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenPostRequest;
use App\Domain\SeoReview\OfflinePortalGscLoopbackTokenTransportReview as Transport;
use App\Domain\SeoReview\OfflinePortalGscOAuthStateStartReview as Issuer;
use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication as Browser;
use App\Infrastructure\Identity\PostgresPortalPermissionPreflight as Preflight;
use App\Infrastructure\SeoReview\GoogleSearchConsoleHttpsTransportCandidate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Imagine\Portal\GscHttpKernelLab\LabController;
use Imagine\Portal\GscHttpKernelLab\LabFlow;

const LAB_ORIGIN='https://portal.example.test';
const LAB_SITE='sc-domain:example.com';
$checks=0;$failed=false;$owner=null;$db=null;$browserDb=null;$revoker=null;
$directory=null;$fixtureOwned=false;
$tlsMode=getenv('PORTAL_GSC_HTTP_KERNEL_HTTPS_LAB')==='1';
function labCheck(bool $ok,string $label): void
{
    global $checks;
    $checks++;
    if (!$ok)throw new RuntimeException($label);
}
function labTotp(int $counter): string
{
    $mac=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),
        $counter%4294967296),'12345678901234567890',true);
    $offset=ord($mac[19])&15;
    return str_pad((string)((unpack('N',substr($mac,$offset,4))[1]&0x7fffffff)
        %1000000),6,'0',STR_PAD_LEFT);
}
function labEnvelope(string $scenario): GoogleSearchConsoleOAuthTokenPostRequest
{
    return new GoogleSearchConsoleOAuthTokenPostRequest([
        'client_id'=>'1234567890-f116syntheticonly.apps.googleusercontent.com',
        'client_secret'=>'synthetic-secret-f116-private-only','code'=>$scenario,
        'code_verifier'=>str_repeat('A',43),
        'redirect_uri'=>LAB_ORIGIN.'/integrations/google/search-console/callback',
        'grant_type'=>'authorization_code',
    ]);
}

try {
    labCheck(LabFlow::enabled(),'all laboratory gates required');
    $https=null;
    if ($tlsMode) {
        $caFile=getenv('PORTAL_GSC_HTTPS_CA_FILE');
        labCheck(is_string($caFile) && $caFile!=='','TLS laboratory CA required');
        $https=GoogleSearchConsoleHttpsTransportCandidate::forTlsLab($caFile);
        labCheck($https!==null,'TLS laboratory factory must authorize only its fixed local sockets');
    }
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f115_lab';
    $owner=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),$options);
    labCheck((int)$owner->query("SELECT current_setting('server_version_num')::integer/10000")
        ->fetchColumn()===18,'PostgreSQL18 required');
    foreach (['portal_identities','portal_organizations','portal_auth_sessions',
        'portal_gsc_oauth_states','portal_gsc_oauth_token_candidates','portal_gsc_property_grants',
        'portal_browser_private.proof_key'] as $table)
        labCheck((int)$owner->query('SELECT count(*) FROM '.$table)->fetchColumn()===0,
            'empty disposable fixture required');
    $fixtureOwned=true;
    $browserDb=new PDO($dsn,'portal_browser_runtime','f114_browser_runtime_only',$options);
    $db=new PDO($dsn,'portal_gsc_f114_runtime','f114_disposable_runtime_only',$options);
    $revoker=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),$options);
    foreach(['portal_auth_sessions','portal_memberships','portal_membership_grants',
        'portal_gsc_f115_state_session_bindings'] as $table) {
        $q=$owner->prepare("SELECT has_table_privilege('portal_gsc_f114_runtime',?,'SELECT')");
        $q->execute([$table]);
        labCheck(in_array($q->fetchColumn(),[false,0,'0','f'],true),'runtime cannot read identity or binding tables');
    }

    $proof=bin2hex(random_bytes(32));$totpKey=random_bytes(32);
    $pepper=bin2hex(random_bytes(32));$stateKey=random_bytes(32);$vaultKey=random_bytes(32);
    $owner->prepare('INSERT INTO portal_browser_private.proof_key(secret) VALUES (?)')->execute([$proof]);
    $passwordHash=PortalPasswordCredential::makeHash('synthetic-password-only');
    $sealedTotp=PortalTotpSecretCipher::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$totpKey);
    labCheck(is_string($passwordHash)&&is_string($sealedTotp),'native credential fixtures');
    $subjectNumber=0;
    $subject=function(string $permission='seo.gsc.connect')use($owner,$passwordHash,$sealedTotp,&$subjectNumber):array {
        $number=++$subjectNumber;$suffix=str_pad((string)$number,12,'0',STR_PAD_LEFT);
        $s=['identity'=>'11111111-1111-4111-8111-'.$suffix,
            'organization'=>'22222222-2222-4222-8222-'.$suffix,
            'membership'=>'33333333-3333-4333-8333-'.$suffix,
            'grant'=>'44444444-4444-4444-8444-'.$suffix,
            'email'=>'http-lab-'.$number.'@example.invalid'];
        $owner->prepare("INSERT INTO portal_identities
            (id,email_normalized,status,password_hash,mfa_secret_ciphertext,email_verified_at,
             mfa_confirmed_at,security_revision,created_at,updated_at)
            VALUES (?,?,'active',?,?,now()-interval '1 hour',now()-interval '1 hour',1,now(),now())")
            ->execute([$s['identity'],$s['email'],$passwordHash,$sealedTotp]);
        $owner->prepare("INSERT INTO portal_organizations
            (id,reference,display_name,status,security_revision,created_at,updated_at)
            VALUES (?,?,'Synthetic HTTP laboratory','active',5,now(),now())")
            ->execute([$s['organization'],'http-lab-'.$number]);
        $owner->prepare("INSERT INTO portal_memberships
            (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
            VALUES (?,?,?,'member','active',1,now(),now())")
            ->execute([$s['membership'],$s['identity'],$s['organization']]);
        $owner->prepare('INSERT INTO portal_membership_grants
            (id,membership_id,permission_code,created_at,updated_at) VALUES (?,?,?,now(),now())')
            ->execute([$s['grant'],$s['membership'],$permission]);
        return $s;
    };
    $auth=new Browser($browserDb,'portal_gsc_f115_lab',$proof,$totpKey,$pepper);
    $preflight=new Preflight($browserDb,'portal_gsc_f115_lab');
    $issuer=new Issuer($db,$stateKey,[
        'clientId'=>'1234567890-syntheticf114.apps.googleusercontent.com',
        'registeredRedirectUri'=>LAB_ORIGIN.'/integrations/google/search-console/callback',
    ]);
    $vault=new Vault($db,$vaultKey);
    $app=require $portal.'/bootstrap/app.php';
    $kernel=$app->make(Kernel::class);$kernel->bootstrap();
    labCheck($app->make(PortalBrowserAuthentication::class) instanceof DenyingPortalBrowserAuthentication,
        'operational identity binding stays denying');
    foreach ($app['router']->getRoutes() as $route)
        labCheck($route->uri()==='up','operational routes remain closed');
    $directory=sys_get_temp_dir().'/gsc-http-kernel-'.bin2hex(random_bytes(12));
    labCheck(mkdir($directory,0700),'private temporary sessions');
    config(['app.url'=>LAB_ORIGIN,'app.debug'=>false,
        'app.key'=>'base64:'.base64_encode(random_bytes(32)),
        'session.driver'=>'file','session.files'=>$directory,'session.encrypt'=>true,
        'session.lottery'=>[0,100],'cache.default'=>'array']);
    $app->instance(PortalBrowserAuthentication::class,$auth);
    Route::middleware(['web',PortalBrowserAuthGuard::class])->group(function():void {
        Route::get('/__gsc-http-lab/csrf',[PortalBrowserAuthController::class,'csrf']);
        Route::post('/__gsc-http-lab/password',[PortalBrowserAuthController::class,'password']);
        Route::post('/__gsc-http-lab/mfa',[PortalBrowserAuthController::class,'mfa']);
        Route::get('/__gsc-http-lab/session',[PortalBrowserAuthController::class,'current']);
        Route::post('/__gsc-http-lab/logout',[PortalBrowserAuthController::class,'logout']);
        Route::post('/__gsc-http-lab/connect',[LabController::class,'connect']);
        Route::get('/__gsc-http-lab/callback',[LabController::class,'callback']);
        Route::post('/__gsc-http-lab/revoke',[LabController::class,'revoke']);
    });
    $forbidden=['synthetic-password-only','synthetic-secret-f116-private-only',
        'synthetic-good-with-refresh','code_verifier','client_secret',
        'synthetic-only-access-token-1234567890','synthetic-only-refresh-token-1234567890',
        $proof,$pepper,$stateKey,$vaultKey,$totpKey];
    $http=function(array &$jar,string $method,string $path,array $input=[],?string $csrf=null,
        array $headers=[],string $origin=LAB_ORIGIN)use($app,$kernel,&$forbidden) {
        // Forget every resolved session driver: cookies must recover encrypted
        // persisted server state on each independent Kernel Request.
        $app['session']->forgetDrivers();$app->forgetInstance('session.store');
        $server=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json',
            'HTTP_ORIGIN'=>LAB_ORIGIN,'REMOTE_ADDR'=>$jar['ip']??'192.0.2.250'];
        if ($csrf!==null)$server['HTTP_X_CSRF_TOKEN']=$csrf;
        $url=$origin.'/__gsc-http-lab/'.$path;
        if ($method==='GET' && $input!==[])$url.='?'.http_build_query($input);
        $cookies=isset($jar['cookie'])?[config('session.cookie')=>$jar['cookie']]:[];
        $request=Request::create($url,$method,[],$cookies,[],array_replace($server,$headers),
            $method==='GET'?'':json_encode($input,JSON_THROW_ON_ERROR));
        $response=$kernel->handle($request);$kernel->terminate($request,$response);
        labCheck($response->headers->get('X-Robots-Tag')==='noindex, nofollow, noarchive, nosnippet',
            'private headers survive kernel response');
        labCheck(str_contains((string)$response->headers->get('Cache-Control'),'no-store'),
            'responses cannot be cached');
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName()!==config('session.cookie'))continue;
            labCheck($cookie->isSecure() && $cookie->isHttpOnly() && !$cookie->getDomain() &&
                $cookie->getPath()==='/' && $cookie->getSameSite()==='lax','host-only secure cookie metadata');
            $jar['cookie']=$cookie->getValue();
        }
        foreach($forbidden as $secret)
            labCheck(!str_contains($response->getContent(),$secret),'no secret in HTTP response');
        return $response;
    };
    $json=static fn($response):array=>json_decode($response->getContent(),true,16,JSON_THROW_ON_ERROR);
    $csrf=function(array &$jar)use($http,$json):string {
        $r=$http($jar,'GET','csrf');labCheck($r->getStatusCode()===200,'csrf issued');
        return $json($r)['csrf_token'];
    };
    $bind=function(array $s,?Closure $after=null,?Closure $locked=null,string $site=LAB_SITE)
        use($app,$db,$issuer,$vault,$https,$tlsMode):LabFlow {
        config(['portal_development_auth.organization'=>$s['organization']]);
        $flow=new LabFlow($db,$issuer,$vault,$s['organization'],$site,$after,$locked,$https);
        labCheck($flow->usesHttpsLab()===$tlsMode,'configured transport mode cannot fall back silently');
        $app->instance(LabFlow::class,$flow);return $flow;
    };
    $login=function(array &$jar,array $s,int $counterOffset=0)use($http,$csrf,$owner,$app,&$forbidden):string {
        config(['portal_development_auth.organization'=>$s['organization']]);
        // Distinct synthetic clients respect the native five-attempt/IP
        // throttle; no throttle or TOTP counter is reset by the harness.
        $jar['ip']='192.0.2.'.(int)substr($s['identity'],-3);
        $r=$http($jar,'POST','password',['email'=>$s['email'],'password'=>'synthetic-password-only',
            'organization'=>$s['organization']],$csrf($jar));
        labCheck($r->getStatusCode()===202,'native password accepted');
        $pending=$app['session']->driver()->get('portal.browser_auth')['reference'];
        $forbidden[]=$pending;
        labCheck(!str_contains($r->getContent(),$pending),'password response cannot disclose newly issued reference');
        labCheck($http($jar,'GET','session')->getStatusCode()===401,'password alone is not business session');
        labCheck($http($jar,'POST','connect',[],$csrf($jar))->getStatusCode()===403,
            'pending MFA cannot start OAuth');
        $counter=(int)$owner->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')
            ->fetchColumn()+$counterOffset;
        $r=$http($jar,'POST','mfa',['code'=>labTotp($counter)],$csrf($jar));
        labCheck($r->getStatusCode()===200,'real TOTP accepted through kernel');
        $ref=$app['session']->driver()->get('portal.browser_auth')['reference'];
        $forbidden[]=$ref;
        labCheck(!str_contains($r->getContent(),$ref),'MFA response cannot disclose newly issued reference');
        labCheck(is_string($ref) && strlen($ref)===64 && $ref!==$pending,'MFA rotates database reference');
        labCheck($http($jar,'GET','session')->getStatusCode()===200,'persisted business session restored');
        return $ref;
    };
    $start=function(array &$jar)use($http,$csrf,$json,$owner):array {
        $r=$http($jar,'POST','connect',[],$csrf($jar));
        labCheck($r->getStatusCode()===200,'authenticated connect creates bound state');
        $callback=['state'=>$json($r)['labAuthorization']['state'],'code'=>'synthetic-good-with-refresh'];
        $q=$owner->prepare('SELECT s.consumed_at FROM portal_gsc_oauth_states s
            JOIN portal_gsc_f115_state_session_bindings b ON b.state_hash=s.state_hash
            WHERE s.state_hash=?');
        $q->execute([hash('sha256',$callback['state'])]);$row=$q->fetch(PDO::FETCH_ASSOC);
        labCheck(is_array($row) && $row['consumed_at']===null,'new state and original binding really exist');
        return $callback;
    };
    $consumed=function(array $callback)use($owner):bool {
        $q=$owner->prepare('SELECT consumed_at FROM portal_gsc_oauth_states WHERE state_hash=?');
        $q->execute([hash('sha256',$callback['state'])]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && is_numeric($row['consumed_at']) &&
            (int)$row['consumed_at']>=1600000000;
    };
    $countFor=function(array $s,string $table)use($owner):int {
        $q=$owner->prepare('SELECT count(*) FROM '.$table.' WHERE tenant_ref=? AND actor_ref=?');
        $q->execute(['porg-'.str_replace('-','',$s['organization']),
            'pusr-'.str_replace('-','',$s['identity'])]);return (int)$q->fetchColumn();
    };
    $setContext=function(array $s,string $ref,?array $callback=null)use($db):void {
        $db->prepare("SELECT set_config('app.tenant_ref',?,true),set_config('app.actor_ref',?,true),
            set_config('app.gsc_lab_session_digest',?,true),set_config('app.gsc_lab_organization',?,true),
            set_config('app.gsc_lab_state_hash',?,true)")->execute([
                'porg-'.str_replace('-','',$s['organization']),'pusr-'.str_replace('-','',$s['identity']),
                hash('sha256',$ref),$s['organization'],$callback===null?'':hash('sha256',$callback['state'])]);
    };
    $insertDenied=function(string $table,array $s)use($db):bool {
        $db->exec('SAVEPOINT unauthorized_insert');$denied=false;
        try {
            if ($table==='portal_gsc_oauth_token_candidates') {
                $q=$db->prepare("INSERT INTO portal_gsc_oauth_token_candidates
                    (tenant_ref,actor_ref,property_fingerprint,scope,sealed_tokens,issued_at,expires_at)
                    VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly','v1.synthetic',?,?)");
            } else {
                $q=$db->prepare("INSERT INTO portal_gsc_property_grants
                    (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,verified_at,expires_at)
                    VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly','active',?,?)");
            }
            $q->execute(['porg-'.str_replace('-','',$s['organization']),
                'pusr-'.str_replace('-','',$s['identity']),hash('sha256','synthetic-forged-property'),time(),time()+300]);
        } catch(PDOException $e) {$denied=$e->getCode()==='42501';}
        $db->exec('ROLLBACK TO SAVEPOINT unauthorized_insert');
        $db->exec('RELEASE SAVEPOINT unauthorized_insert');
        return $denied;
    };

    // No caller-supplied identity, cross-origin request or pending MFA may
    // reach the state ledger or provider transport.
    $a=$subject();$jarA=[];$flow=$bind($a);
    labCheck($http($jarA,'POST','connect',[],$csrf($jarA))->getStatusCode()===403,'anonymous connect denied');
    labCheck($http($jarA,'POST','connect')->getStatusCode()===419,'CSRF required');
    labCheck($http($jarA,'POST','connect',[],$csrf($jarA),
        ['HTTP_ORIGIN'=>'https://crm.example.test'])->getStatusCode()===419,'sibling origin denied');
    labCheck($http($jarA,'POST','connect',[],$csrf($jarA),
        ['HTTP_SEC_FETCH_SITE'=>'same-site'])->getStatusCode()===419,'same-site is not same-origin');
    labCheck($http($jarA,'GET','csrf',[],null,[],'http://portal.example.test')->getStatusCode()===403,
        'insecure request scheme denied');
    $refA=$login($jarA,$a);
    labCheck($preflight->hasCandidateGrant($refA,$a['organization'],'seo.gsc.connect') &&
        !$preflight->hasCandidateGrant($refA,$a['organization'],'seo.gsc.view'),'connect never implies view');
    labCheck($http($jarA,'POST','connect',['tenantRef'=>'forged','sessionReference'=>$refA],
        $csrf($jarA))->getStatusCode()===403,'body authority injection denied');
    labCheck($flow->tokenAttempts===0,'denials never attempt token HTTP');
    $callback=$start($jarA);
    labCheck($http($jarA,'GET','callback',[...$callback,'actorRef'=>'forged'])->getStatusCode()===403,
        'callback identity injection denied');
    $anonymous=[];
    labCheck($http($anonymous,'GET','callback',$callback)->getStatusCode()===403,'callback requires original browser session');
    $b=$subject();$jarB=[];$refB=$login($jarB,$b);$bind($a);
    labCheck($http($jarB,'GET','callback',$callback)->getStatusCode()===403,'cross-tenant callback denied');
    // A second legitimate MFA session for the SAME subject is still not the
    // original OAuth issuer. Use the next allowed TOTP tick, never reset replay state.
    $jarReplacement=[];$refReplacement=$login($jarReplacement,$a,1);$bind($a);
    labCheck($refReplacement!==$refA && $auth->isActiveBusinessSession($refA),'original and replacement are distinct live sessions');
    labCheck($http($jarReplacement,'GET','callback',$callback)->getStatusCode()===403,'same-user session substitution denied');
    labCheck(!$consumed($callback),'denied callers do not consume original state');

    $lockAttempts=0;
    $lockProof=function()use($revoker,$refA,$a,&$lockAttempts):bool {
        foreach([
            ["UPDATE portal_auth_sessions SET status='revoked',revoked_at=now() WHERE session_sha256=?",hash('sha256',$refA)],
            ['DELETE FROM portal_membership_grants WHERE id=?',$a['grant']],
        ] as [$sql,$arg]) {
            $revoker->beginTransaction();
            try {
                $revoker->exec("SET LOCAL lock_timeout='150ms'");
                $revoker->prepare($sql)->execute([$arg]);$revoker->rollBack();return false;
            } catch(PDOException $e) {
                if ($revoker->inTransaction())$revoker->rollBack();
                if ($e->getCode()!=='55P03')return false;$lockAttempts++;
            }
        }
        return true;
    };
    $flow=$bind($a,null,$lockProof);
    $r=$http($jarA,'GET','callback',$callback);
    labCheck($r->getStatusCode()===200 && $json($r)['labCandidateAndGrantRecorded']===true,
        'callback transports exact candidate into atomic vault and synthetic grant');
    labCheck($json($r)['tlsLabVerified']===$tlsMode,
        'callback reports only the transport exercised in this laboratory');
    labCheck($flow->tokenAttempts===1 && $flow->propertyAttempts===1 && $lockAttempts===2,
        'one token request one property request and two post-response revocation locks');
    labCheck($consumed($callback),'state consumption remains committed');
    labCheck($countFor($a,'portal_gsc_oauth_token_candidates')===1 &&
        $countFor($a,'portal_gsc_property_grants')===1,'one candidate and synthetic grant');
    labCheck($http($jarA,'GET','callback',$callback)->getStatusCode()===403 && $flow->tokenAttempts===1,
        'callback replay cannot repeat transport');
    labCheck(!$preflight->hasCandidateGrant($refA,$a['organization'],'seo.gsc.view'),
        'synthetic GSC grant cannot create Portal view permission');

    $db->beginTransaction();$setContext($a,$refA,$callback);
    $secret=$vault->inspectSyntheticCandidate('porg-'.str_replace('-','',$a['organization']),
        'pusr-'.str_replace('-','',$a['identity']),hash('sha256',LAB_SITE),time());
    labCheck($secret!==null,'same candidate can be decrypted under authenticated context');
    $tokens=$secret->takeForTrustedBackend();
    labCheck($tokens['accessToken']==='synthetic-only-access-token-1234567890' &&
        $tokens['refreshToken']==='synthetic-only-refresh-token-1234567890','vault stores actual provider response candidate');
    labCheck($vault->inspectSyntheticCandidate('porg-'.str_replace('-','',$a['organization']),
        'pusr-'.str_replace('-','',$a['identity']),hash('sha256',LAB_SITE),$tokens['expiresAt']+1)===null,
        'the actual stored candidate expires');
    unset($tokens,$secret);$db->commit();
    $sealed=(string)$owner->query('SELECT sealed_tokens FROM portal_gsc_oauth_token_candidates LIMIT 1')->fetchColumn();
    labCheck(str_starts_with($sealed,'v1.') && !str_contains($sealed,'synthetic-'),'vault contains ciphertext only');

    // Direct runtime SQL cannot manufacture authority just by setting scope.
    // Check both tables, not only the PHP wrapper's happy path.
    foreach(['portal_gsc_oauth_token_candidates','portal_gsc_property_grants'] as $table) {
        $db->beginTransaction();
        $db->prepare("SELECT set_config('app.tenant_ref',?,true),set_config('app.actor_ref',?,true)")
            ->execute(['porg-'.str_replace('-','',$a['organization']),'pusr-'.str_replace('-','',$a['identity'])]);
        labCheck((int)$db->query('SELECT count(*) FROM '.$table)->fetchColumn()===0,
            'forged tenant actor cannot read');
        labCheck($db->exec('UPDATE '.$table.' SET revoked_at=extract(epoch FROM clock_timestamp())::bigint')===0,
            'forged tenant actor cannot revoke');
        labCheck($insertDenied($table,$a),'forged tenant actor cannot insert');$db->rollBack();
    }
    $db->beginTransaction();$setContext($a,$refA,$callback);
    $db->prepare("SELECT set_config('app.actor_ref',?,true)")->execute([
        'pusr-'.str_replace('-','',$b['identity'])]);
    labCheck((int)$db->query('SELECT count(*) FROM portal_gsc_oauth_token_candidates')->fetchColumn()===0,
        'valid principal cannot read another actor');$db->commit();
    $db->beginTransaction();$setContext($a,$refA);
    foreach(['portal_gsc_oauth_token_candidates','portal_gsc_property_grants'] as $table)
        labCheck($insertDenied($table,$a),'live principal without original consumed state cannot insert');
    $db->commit();

    // Malformed token responses, redirects and insufficient property evidence
    // consume the state once but never manufacture another candidate/grant.
    foreach(['synthetic-redirect','synthetic-bad-json','synthetic-bad-expiry','synthetic-denied-scope'] as $scenario) {
        $flow=$bind($a);$bad=$start($jarA);$bad['code']=$scenario;
        labCheck($http($jarA,'GET','callback',$bad)->getStatusCode()===403,'invalid provider response refused');
        labCheck($consumed($bad) && $flow->tokenAttempts===1 && $flow->propertyAttempts===0,
            'failed token exchange stays consumed without property call');
    }
    foreach(['sc-domain:other.example','https://restricted.example.com/'] as $site) {
        $flow=$bind($a,site:$site);$bad=$start($jarA);
        labCheck($http($jarA,'GET','callback',$bad)->getStatusCode()===403 && $consumed($bad),
            'exact property and minimum provider permission required');
        labCheck($flow->tokenAttempts===1 && $flow->propertyAttempts===1,'property evidence comes from loopback bearer request');
    }
    labCheck($countFor($a,'portal_gsc_oauth_token_candidates')===1 &&
        $countFor($a,'portal_gsc_property_grants')===1,'negative providers create no new records');

    $viewOnly=$subject('seo.gsc.view');$jarView=[];$viewRef=$login($jarView,$viewOnly);$flow=$bind($viewOnly);
    labCheck($preflight->hasCandidateGrant($viewRef,$viewOnly['organization'],'seo.gsc.view') &&
        !$preflight->hasCandidateGrant($viewRef,$viewOnly['organization'],'seo.gsc.connect'),
        'view never implies connect');
    labCheck($http($jarView,'POST','connect',[],$csrf($jarView))->getStatusCode()===403 &&
        $flow->tokenAttempts===0,'view-only session cannot begin OAuth');

    foreach(['session','grant'] as $race) {
        $s=$subject();$jar=[];$ref=$login($jar,$s);
        $after=function()use($race,$auth,$ref,$owner,$s):bool {
            if ($race==='session')return $auth->revoke($ref);
            $q=$owner->prepare('DELETE FROM portal_membership_grants WHERE id=?');
            $q->execute([$s['grant']]);return $q->rowCount()===1;
        };
        $flow=$bind($s,$after);$raceCallback=$start($jar);
        labCheck($http($jar,'GET','callback',$raceCallback)->getStatusCode()===403,
            'post-consumption revocation prevents persistence');
        labCheck($consumed($raceCallback) && $flow->tokenAttempts===1 && $flow->propertyAttempts===1,
            'revocation occurs after both actual loopback responses');
        labCheck($countFor($s,'portal_gsc_oauth_token_candidates')===0 &&
            $countFor($s,'portal_gsc_property_grants')===0,'post-response race leaves no vault or grant');
    }

    // A second write failing rolls back the first write in the same transaction.
    $atomic=$subject();$jarAtomic=[];$refAtomic=$login($jarAtomic,$atomic);$flow=$bind($atomic);
    $owner->prepare("INSERT INTO portal_gsc_property_grants
        (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,verified_at,expires_at)
        VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly','active',?,?)")
        ->execute(['porg-'.str_replace('-','',$atomic['organization']),
            'pusr-'.str_replace('-','',$atomic['identity']),hash('sha256',LAB_SITE),time(),time()+300]);
    $atomicCallback=$start($jarAtomic);
    labCheck($http($jarAtomic,'GET','callback',$atomicCallback)->getStatusCode()===403 &&
        $consumed($atomicCallback),'grant write conflict consumes callback');
    labCheck($countFor($atomic,'portal_gsc_oauth_token_candidates')===0,'failed grant write rolls back vault write');

    // Existing rows also become inaccessible after a grant revocation, even
    // if all previously valid GUCs are retained by a direct SQL caller.
    $stale=$subject();$jarStale=[];$staleRef=$login($jarStale,$stale);$flow=$bind($stale);
    $staleCallback=$start($jarStale);
    labCheck($http($jarStale,'GET','callback',$staleCallback)->getStatusCode()===200,'candidate before grant revocation');
    $db->beginTransaction();$setContext($stale,$staleRef,$staleCallback);
    $owner->prepare('DELETE FROM portal_membership_grants WHERE id=?')->execute([$stale['grant']]);
    foreach(['portal_gsc_oauth_token_candidates','portal_gsc_property_grants'] as $table) {
        labCheck((int)$db->query('SELECT count(*) FROM '.$table)->fetchColumn()===0,
            'revoked connect grant with stale GUCs cannot read existing rows');
        labCheck($db->exec('UPDATE '.$table.' SET revoked_at=extract(epoch FROM clock_timestamp())::bigint')===0,
            'revoked connect grant with stale GUCs cannot update');
        labCheck($insertDenied($table,$stale),'revoked connect grant with stale GUCs cannot insert');
    }
    $db->commit();

    // Optional candidate sink is opt-in, one call per envelope, and failures
    // never turn into a safe success review or permit envelope replay.
    $sinkCalls=0;$sink=static function(GoogleSearchConsoleOAuthTokenCandidate $candidate)use(&$sinkCalls):bool {
        $sinkCalls++;return $candidate->safeReview()['tokenPersistenceAuthorized']===false;
    };
    $envelope=labEnvelope('synthetic-good-with-refresh');
    putenv('PORTAL_GSC_HTTP_KERNEL_LAB=0');
    labCheck((new Transport())->inspect($envelope,time(),$sink)===null && $sinkCalls===0,
        'disabled sink does not consume envelope');
    putenv('PORTAL_GSC_HTTP_KERNEL_LAB=1');
    labCheck(is_array((new Transport())->inspect($envelope,time(),$sink)) && $sinkCalls===1,
        'enabled sink receives exact opaque response candidate once');
    labCheck((new Transport())->inspect($envelope,time(),$sink)===null && $sinkCalls===1,
        'envelope replay cannot repeat sink');
    $envelope=labEnvelope('synthetic-good-with-refresh');
    labCheck((new Transport())->inspect($envelope,time(),static function():bool {
        throw new RuntimeException('synthetic sink failure');
    })===null && (new Transport())->inspect($envelope,time(),$sink)===null,
        'sink exception cannot reopen envelope');

    // Revoke through HTTP atomically, then logout the real DB session. OAuth
    // disconnect here is local only: no Google revocation call is claimed.
    $flow=$bind($a);$leftover=$start($jarA);
    labCheck($http($jarA,'POST','revoke',[],$csrf($jarA))->getStatusCode()===200,'HTTP disconnect revokes candidate and grant');
    $q=$owner->prepare('SELECT revoked_at FROM portal_gsc_oauth_token_candidates WHERE tenant_ref=?');
    $q->execute(['porg-'.str_replace('-','',$a['organization'])]);
    $revoked=$q->fetch(PDO::FETCH_ASSOC);
    labCheck(is_array($revoked) && is_numeric($revoked['revoked_at']) &&
        (int)$revoked['revoked_at']>=1600000000,'vault is durably revoked');
    $q=$owner->prepare('SELECT grant_status FROM portal_gsc_property_grants WHERE tenant_ref=?');
    $q->execute(['porg-'.str_replace('-','',$a['organization'])]);
    labCheck($q->fetchColumn()==='revoked','synthetic grant is durably revoked');
    $db->beginTransaction();$setContext($a,$refA,$callback);
    labCheck($db->exec('UPDATE portal_gsc_oauth_token_candidates SET revoked_at=NULL')===0,
        'direct SQL cannot reopen revoked token candidate');
    labCheck($db->exec("UPDATE portal_gsc_property_grants SET revoked_at=NULL,grant_status='active'")===0,
        'direct SQL cannot reopen revoked synthetic grant');
    labCheck($vault->inspectSyntheticCandidate('porg-'.str_replace('-','',$a['organization']),
        'pusr-'.str_replace('-','',$a['identity']),hash('sha256',LAB_SITE),time())===null,
        'revoked candidate is unavailable to backend vault');
    $db->commit();

    // Establish old GUCs without taking a principal lock, then revoke on the
    // separate native browser connection. RLS must recheck, not trust GUCs.
    $db->beginTransaction();$setContext($a,$refA,$callback);
    labCheck($http($jarA,'POST','logout',[],$csrf($jarA))->getStatusCode()===204,'native logout durably revokes original session');
    foreach(['portal_gsc_oauth_token_candidates','portal_gsc_property_grants'] as $table) {
        labCheck((int)$db->query('SELECT count(*) FROM '.$table)->fetchColumn()===0,
            'revoked session with stale GUCs cannot read');
        labCheck($db->exec('UPDATE '.$table.' SET revoked_at=extract(epoch FROM clock_timestamp())::bigint')===0,
            'revoked session with stale GUCs cannot update');
        labCheck($insertDenied($table,$a),'revoked session with stale GUCs cannot insert');
    }
    $db->commit();
    labCheck($http($jarA,'GET','callback',$leftover)->getStatusCode()===403 && !$consumed($leftover),
        'logout denies leftover unconsumed callback');
    labCheck(!$auth->isActiveBusinessSession($refA),'original business session is inactive');

    // Persisted browser envelopes contain no plaintext identity references or
    // credentials; response checks above also cover every denial and success.
    foreach(glob($directory.'/*')?:[] as $file) {
        $bytes=file_get_contents($file);
        foreach($forbidden as $secret)labCheck(!str_contains($bytes,$secret),'encrypted session leaks no secret');
    }
    labCheck(!$db->inTransaction() && !$browserDb->inTransaction(),'all runtime transactions closed');
    labCheck(!Issuer::canAuthorizeCustomer() && !Issuer::canPersistOAuthTokens() &&
        !Transport::canCallGoogle() && !Vault::authorizesTokenUse() &&
        !Vault::authorizesCustomerDisplay(),'operational authorization flags remain false');
} catch(Throwable $e) {
    $failed=true;
    // Never print PDO text, exception arguments, URLs, cookies or provider body.
    $label=$e instanceof RuntimeException && get_class($e)===RuntimeException::class
        ? $e->getMessage() : 'unexpected '.get_class($e).' at line '.$e->getLine();
    fwrite(STDERR,'GSC HTTP kernel failed: '.$label."\n");
} finally {
    putenv('PORTAL_GSC_HTTP_KERNEL_LAB=1');
    foreach([$db,$browserDb,$revoker] as $connection)
        if ($connection instanceof PDO && $connection->inTransaction())$connection->rollBack();
    if ($fixtureOwned && $owner instanceof PDO) {
        try {
            foreach(['portal_gsc_f115_state_session_bindings','portal_gsc_oauth_states',
                'portal_gsc_oauth_token_candidates','portal_gsc_property_grants',
                'portal_auth_attempt_buckets','portal_membership_grants','portal_auth_sessions',
                'portal_memberships','portal_identities','portal_organizations',
                'portal_browser_private.proof_key'] as $table)$owner->exec('DELETE FROM '.$table);
            labCheck((int)$owner->query('SELECT count(*) FROM portal_auth_sessions')->fetchColumn()===0,
                'synthetic authority rows cleaned');
        } catch(Throwable) {$failed=true;fwrite(STDERR,"GSC HTTP kernel fixture cleanup failed\n");}
    }
    if (is_string($directory) && is_dir($directory)) {
        foreach(glob($directory.'/*')?:[] as $file)unlink($file);
        rmdir($directory);
    }
}
if (!$failed)echo "GSC HTTP kernel: {$checks} checks; real Laravel middleware + PG18 MFA/state/vault; ".
    ($tlsMode?'verified TLS with ephemeral laboratory CA; ':'HTTP laboratory transport; ').
    "synthetic loopback token/property only; replay, tenant/session substitution, post-response revocation, ".
    "RLS forgery and atomic rollback checked; NO GOOGLE OR OPERATIONAL ENABLEMENT\n";
exit($failed?1:0);
