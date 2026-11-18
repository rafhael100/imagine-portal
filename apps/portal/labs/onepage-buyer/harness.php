<?php
/** Proposed PostgreSQL acceptance harness. Never run in the blocked executor. */
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../onepage-buyer/CoreSchema.php';
require __DIR__.'/Guard.php';
require __DIR__.'/BuyerQuoteAdapter.php';
use App\Labs\OnepageBuyer\Guard;
use App\Labs\OnepageBuyer\BuyerQuoteAdapter;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\Artisan;

$checks=[];
function checkLab(bool $ok,string $name):void {
    global $checks;
    if(!$ok)throw new RuntimeException('CHECK_FAILED: '.$name);
    $checks[]=$name;
}
function requestLab(?string $reference,string $phase='business'):Request {
    $request=Request::create('/not-registered','POST');
    $session=new Store('onepage-lab',new ArraySessionHandler(120));$session->start();
    if($reference!==null)$session->put('portal.browser_auth',['phase'=>$phase,'reference'=>$reference]);
    $request->setLaravelSession($session);return $request;
}
try {
    if($argc!==3||getenv('ONEPAGE_BUYER_PG_LAB')!=='DISPOSABLE_ONLY')throw new RuntimeException('LAB_OPT_IN_REQUIRED');
    Guard::offline();
    $app=require __DIR__.'/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $proof=bin2hex(random_bytes(32));$key=random_bytes(32);$pepper=bin2hex(random_bytes(32));
    $secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $password=PortalPasswordCredential::makeHash('synthetic-password-only');
    $sealed=PortalTotpSecretCipher::seal($secret,$key);
    Guard::fixtures($password,$sealed,$key,$secret,'synthetic-password-only');
    // Guard precedes connection creation. Cache/env overrides are inspected as loaded.
    $verified=function(string $database)use($app,$argv){
        Guard::configuration($app->environment(),config('database.default'),config('database.connections.pgsql'),$database);
        $db=$app['db']->connection('pgsql');
        Guard::configuration($app->environment(),config('database.default'),$db->getConfig(),$database);
        $pdo=$db->getPdo();
        $identity=Guard::identity($pdo,$argv[1],$argv[2],$database);
        $configDigest=hash('sha256',serialize(config('database.connections.pgsql')));
        $db->setReconnector(static function(){throw new RuntimeException('RECONNECTION_FORBIDDEN');});
        $barrier=function()use($app,$db,$pdo,$database,$identity,$configDigest,$argv){
            Guard::configuration($app->environment(),config('database.default'),$db->getConfig(),$database);
            if($pdo!==$db->getPdo() || hash('sha256',serialize(config('database.connections.pgsql')))!==$configDigest
                || Guard::identity($pdo,$argv[1],$argv[2],$database)!==$identity)
                throw new RuntimeException('PREPARATION_IDENTITY_CHANGED');
        };
        $db->beforeExecuting(static function()use($barrier){$barrier();});
        return [$db,$pdo,$barrier,$identity];
    };
    // Maintenance DB is verified too: CREATE DATABASE is not an unchecked setup step.
    [$maintenance]=$verified('postgres');
    if($maintenance->selectOne("SELECT 1 FROM pg_database WHERE datname='portal_onepage_buyer_ci'"))
        throw new RuntimeException('FRESH_DATABASE_REQUIRED');
    $maintenance->statement('CREATE DATABASE portal_onepage_buyer_ci');
    $app['db']->purge('pgsql');config(['database.connections.pgsql.database'=>'portal_onepage_buyer_ci']);
    [$db,$bootstrap,$barrier,$identity]=$verified('portal_onepage_buyer_ci');
    checkLab((int)$db->selectOne("SELECT count(*) n FROM information_schema.tables WHERE table_schema='public'")->n===0,'fresh empty database');
    $migrations=[
        '2026_11_09_000002_create_portal_identity_foundation.php',
        '2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php',
        '2026_11_10_000004_add_org_revision_to_portal_sessions.php',
        '2026_11_10_000005_create_portal_auth_throttle_buckets.php',
        '2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php',
        '2026_11_11_000012_fence_portal_membership_revision.php',
        '2026_11_11_000013_fence_portal_organization_revision.php',
        '2026_11_11_000014_guard_portal_session_issuance.php',
        '2026_11_12_000016_fence_portal_identity_revision.php',
    ];
    foreach($migrations as $file){
        $barrier();
        if(Artisan::call('migrate',['--path'=>'database/migrations/'.$file,'--force'=>true,'--no-interaction'=>true])!==0)
            throw new RuntimeException('MIGRATION_FAILED: '.$file);
        $barrier();
    }
    foreach(['database/security/portal_browser_auth.sql','database/security/portal_browser_principal_candidate.sql','labs/onepage-buyer/store.sql'] as $file)
        $db->unprepared(\App\Labs\OnepageBuyer\CoreSchema::read(base_path($file)));
    // Only synthetic roles in this verified fresh cluster; cluster-local trust auth.
    $db->statement('ALTER ROLE portal_browser_runtime LOGIN');
    $db->statement('ALTER ROLE onepage_buyer_runtime LOGIN');
    $db->insert('INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)',[$proof]);
    $fixtures=[];
    foreach(['a','b','c','d'] as $i=>$label){
        $n=$i+1;
        $actor=sprintf('%08d-1111-4111-8111-111111111111',$n);
        $company=sprintf('%08d-2222-4222-8222-222222222222',$n);
        $member=sprintf('%08d-3333-4333-8333-333333333333',$n);
        $email='buyer-'.$label.'@example.invalid';
        $db->insert("INSERT INTO portal_identities(id,email_normalized,status,password_hash,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision) VALUES(?,?,'active',?,?,clock_timestamp()-interval '1 minute',clock_timestamp()-interval '1 minute',1)",[$actor,$email,$password,$sealed]);
        $db->insert("INSERT INTO portal_organizations(id,reference,display_name,status,security_revision) VALUES(?,?,'Synthetic buyer company','active',1)",[$company,'onepage-'.$label]);
        if($label!=='c')$db->insert("INSERT INTO portal_memberships(id,identity_id,organization_id,role,status,membership_revision) VALUES(?,?,?,?,'active',1)",[$member,$actor,$company,$label==='d'?'manager':'member']);
        $fixtures[$label]=compact('actor','company','member','email');
    }
    $connect=static function(string $role)use($barrier,$bootstrap,$identity):PDO{
        if(!in_array($role,['portal_browser_runtime','onepage_buyer_runtime'],true))throw new RuntimeException('LAB_ROLE_REQUIRED');
        $barrier();
        $runtime=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci',$role,'',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $barrier(); Guard::runtime($runtime,$bootstrap,$identity,$role); $barrier();
        return $runtime;
    };
    $auth=new PostgresPortalBrowserAuthentication($connect('portal_browser_runtime'),'portal_onepage_buyer_ci',$proof,$key,$pepper);
    $store=new BuyerQuoteAdapter($connect('onepage_buyer_runtime'));
    $selection=['site'=>'onepage','channels'=>[],'tier'=>'minimum'];
    checkLab($store->create(requestLab(null),'onepage-1',$selection)===['status'=>'denied'],'no session denied');
    checkLab($auth->beginPassword($fixtures['c']['email'],'synthetic-password-only',$fixtures['c']['company'],'192.0.2.3')===null,'missing membership denied');
    $sessions=[];
    foreach(['a','b','d'] as $label){
        $f=$fixtures[$label];
        $pending=$auth->beginPassword($f['email'],'synthetic-password-only',$f['company'],'192.0.2.10');
        checkLab(is_string($pending),'native password '.$label);
        checkLab(!$auth->isActiveBusinessSession($pending),'pending MFA not active '.$label);
        checkLab($store->create(requestLab($pending,'pending_mfa'),'onepage-1',$selection)===['status'=>'denied'],'pending MFA store denied '.$label);
        $counter=(int)$db->selectOne('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint c')->c;
        $hmac=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),'12345678901234567890',true);
        $offset=ord($hmac[19])&15;$value=unpack('N',substr($hmac,$offset,4))[1]&0x7fffffff;
        $code=str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
        $active=$auth->completeMfa($pending,$code,'192.0.2.10');
        checkLab(is_string($active)&&$auth->isActiveBusinessSession($active),'native TOTP business session '.$label);
        $sessions[$label]=$active;
    }
    $a=requestLab($sessions['a']);$b=requestLab($sessions['b']);
    checkLab($store->create(requestLab($sessions['d']),'onepage-1',$selection)===['status'=>'denied'],'non-buyer manager denied');
    $first=$store->create($a,'onepage-1',$selection);
    checkLab(($first['status']??null)==='draft','create own quote');
    $expectedQuote=(new App\Homologation\Commerce\CompositeQuotePreview())->quote($selection);
    $actualQuote=$first['quote'];ksort($expectedQuote);ksort($actualQuote);
    checkLab($actualQuote===$expectedQuote,'current server catalog');
    checkLab($store->create($a,'onepage-1',$selection)===$first,'idempotent repeat');
    $store=null;$fresh=new BuyerQuoteAdapter($connect('onepage_buyer_runtime'));
    checkLab($fresh->resume($a,$first['id'])===$first,'resume new connection');
    checkLab($fresh->resume($b,$first['id'])===['status'=>'denied'],'other company normal denial');
    $second=$fresh->create($b,'onepage-1',$selection);
    checkLab(($second['status']??null)==='draft'&&$second['id']!==$first['id'],'same key separate companies');
    checkLab($fresh->create($a,'onepage-1',array_replace($selection,['tier'=>'recommended']))===['status'=>'conflict'],'changed selection conflict');
    checkLab((int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.drafts')->n===2,'exactly two drafts');
    // Only a negative expired fixture is inserted. Positive sessions above are native-issued.
    $expired=str_repeat('e',64);$f=$fixtures['a'];
    $db->insert("INSERT INTO portal_auth_sessions(id,session_sha256,identity_id,organization_id,status,session_purpose,identity_revision,membership_revision,organization_revision,issued_at,expires_at,mfa_verified_at) VALUES(gen_random_uuid(),?,?,?,'active','business',1,1,1,clock_timestamp()-interval '2 hours',clock_timestamp()-interval '1 hour',clock_timestamp()-interval '2 hours')",[hash('sha256',$expired),$f['actor'],$f['company']]);
    checkLab($fresh->resume(requestLab($expired),$first['id'])===['status'=>'denied'],'expired session denied');
    checkLab($auth->revoke($sessions['a']),'native revocation committed');
    checkLab($fresh->resume($a,$first['id'])===['status'=>'denied'],'revocation prevents resume');
    checkLab($fresh->create($a,'new-key',$selection)===['status'=>'denied'],'revocation prevents create');
    $db->update("UPDATE portal_memberships SET status='suspended' WHERE id=?",[$fixtures['b']['member']]);
    checkLab($fresh->resume($b,$second['id'])===['status'=>'denied'],'membership suspension denied');
    checkLab((int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.drafts')->n===2,'denials never write');
    $profile=$db->selectOne("SELECT NOT rolsuper AND NOT rolbypassrls AND NOT rolinherit AND NOT rolcreaterole AND NOT rolcreatedb AND NOT rolreplication AND NOT EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) AS safe FROM pg_roles r WHERE rolname='onepage_buyer_runtime'");
    checkLab(in_array($profile->safe,[true,'t',1,'1'],true),'runtime profile minimal');
    $privileges=$db->selectOne("SELECT has_table_privilege('onepage_buyer_runtime','onepage_buyer_lab.drafts','SELECT,INSERT,UPDATE,DELETE,TRUNCATE') OR has_table_privilege('onepage_buyer_runtime','public.portal_identities','SELECT,INSERT,UPDATE,DELETE,TRUNCATE') OR has_any_column_privilege('onepage_buyer_runtime','onepage_buyer_lab.drafts','SELECT,INSERT,UPDATE,REFERENCES') OR has_any_column_privilege('onepage_buyer_runtime','public.portal_identities','SELECT,INSERT,UPDATE,REFERENCES') AS direct_access");
    checkLab(in_array($privileges->direct_access,[false,'f',0,'0'],true),'runtime has no direct table access');
    checkLab((int)$db->selectOne("SELECT count(*) n FROM pg_class WHERE oid='onepage_buyer_lab.drafts'::regclass AND relrowsecurity AND relforcerowsecurity")->n===1,'draft FORCE RLS');
    echo json_encode(['status'=>'PASS','checks'=>count($checks),'scenarios'=>$checks,'database'=>$identity['db'],
        'system_identifier'=>$identity['system_id'],'scope'=>'synthetic laboratory only','psp_calls'=>0,'orders'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e){
    // Do not print PDO diagnostics, password material, keys or session references.
    $message=$e instanceof PDOException?'SQL_HARNESS_FAILED':$e->getMessage();
    fwrite(STDERR,"HARNESS_FAILED: ".$message."\n");exit(1);
}
