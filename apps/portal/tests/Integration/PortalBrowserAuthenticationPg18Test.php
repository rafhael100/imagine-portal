<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use App\Infrastructure\Identity\PostgresPortalPermissionPreflight;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use App\Domain\Organizations\OrganizationId;
use Illuminate\Support\Facades\Route;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

final class PortalBrowserAuthenticationPg18Test extends TestCase
{
    private const ACTOR = '11111111-1111-4111-8111-111111111111';
    private const ORG = '22222222-2222-4222-8222-222222222222';
    private const MEMBER = '33333333-3333-4333-8333-333333333333';
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private PDO $admin;
    private PDO $runtime;
    private string $proof;
    private string $key;
    private string $pepper;
    private PostgresPortalBrowserAuthentication $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing' || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1' || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab') {
            self::fail('Explicit disposable PG18 browser laboratory required.');
        }
        $this->admin = self::connect('postgres', (string) getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab', $this->admin->query('SELECT current_database()')->fetchColumn());
        self::assertSame(18, (int) $this->admin->query("SELECT current_setting('server_version_num')::integer/10000")->fetchColumn());
        $this->admin->exec('TRUNCATE public.portal_auth_sessions,public.portal_membership_grants,public.portal_memberships,
            public.portal_identities,public.portal_organizations,public.portal_auth_attempt_buckets CASCADE');
        $this->admin->exec('DELETE FROM portal_browser_private.proof_key');
        $this->proof = bin2hex(random_bytes(32));
        $this->key = random_bytes(32);
        $this->pepper = bin2hex(random_bytes(32));
        $q = $this->admin->prepare('INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)');
        $q->execute([$this->proof]);
        $hash = PortalPasswordCredential::makeHash('synthetic-password-only');
        $sealed = PortalTotpSecretCipher::seal(self::SECRET, $this->key);
        $q = $this->admin->prepare("INSERT INTO public.portal_identities
            (id,email_normalized,status,password_hash,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision)
            VALUES(?,'synthetic@example.invalid','active',?,?,clock_timestamp()-interval '1 minute',clock_timestamp()-interval '1 minute',1)");
        $q->execute([self::ACTOR,$hash,$sealed]);
        $q = $this->admin->prepare("INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision)
            VALUES(?,'browser-fixture','Synthetic browser only','active',1)");
        $q->execute([self::ORG]);
        $q = $this->admin->prepare("INSERT INTO public.portal_memberships(id,identity_id,organization_id,role,status,membership_revision)
            VALUES(?,?,?,'member','active',1)");
        $q->execute([self::MEMBER,self::ACTOR,self::ORG]);
        $this->runtime = self::connect('portal_browser_runtime', (string) getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        $this->adapter = new PostgresPortalBrowserAuthentication($this->runtime,'portal_browser_lab',$this->proof,$this->key,$this->pepper);
    }

    private static function connect(string $role, string $password): PDO
    {
        return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',$role,$password,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    }

    private function begin(string $ip = '192.0.2.1'): string
    {
        $pending = $this->adapter->beginPassword('synthetic@example.invalid','synthetic-password-only',self::ORG,$ip);
        self::assertIsString($pending);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $pending);
        return $pending;
    }

    private function otp(): string
    {
        $counter = (int) $this->admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
        $hmac = hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),'12345678901234567890',true);
        $offset = ord($hmac[19]) & 15;
        $value = unpack('N',substr($hmac,$offset,4))[1] & 0x7fffffff;
        return str_pad((string) ($value%1000000),6,'0',STR_PAD_LEFT);
    }

    private function active(): string
    {
        $reference = $this->adapter->completeMfa($this->begin(),$this->otp(),'192.0.2.1');
        self::assertIsString($reference);
        self::assertTrue($this->adapter->isActiveBusinessSession($reference));
        return $reference;
    }

    private function deniedSql(string $sql, array $args = []): void
    {
        try {
            $q=$this->runtime->prepare($sql);
            $q->execute($args);
        } catch (PDOException $e) {
            self::assertSame('42501',(string) $e->getCode());
            return;
        }
        self::fail('Runtime unexpectedly received direct privileged access.');
    }

    public function test_runtime_has_execute_only_access_and_cannot_read_keys_or_assume_owner(): void
    {
        foreach (['portal_identities','portal_auth_sessions','portal_memberships','portal_organizations','portal_auth_attempt_buckets'] as $table) {
            $this->deniedSql('SELECT * FROM public.' . $table);
        }
        $this->deniedSql('SELECT * FROM portal_browser_private.proof_key');
        $this->deniedSql('SET ROLE portal_browser_owner');
        $this->deniedSql("SELECT public.portal_browser_proof_ok('x','y')");
        self::assertSame(0, (int) $this->admin->query("SELECT count(*) FROM pg_roles WHERE rolname IN
            ('portal_browser_owner','portal_browser_runtime') AND (rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb OR rolinherit)")->fetchColumn());
        self::assertSame(0, (int) $this->admin->query("SELECT count(*) FROM pg_class WHERE relname IN
            ('portal_identities','portal_auth_sessions','portal_memberships','portal_organizations')
            AND (NOT relrowsecurity OR NOT relforcerowsecurity)")->fetchColumn());
    }

    public function test_password_pending_mfa_rotation_replay_and_durable_idempotent_logout(): void
    {
        self::assertNull($this->adapter->beginPassword('synthetic@example.invalid','incorrect-password',self::ORG,'192.0.2.1'));
        $pending=$this->begin();
        self::assertFalse($this->adapter->isActiveBusinessSession($pending));
        $row=$this->admin->query('SELECT session_sha256,status FROM public.portal_auth_sessions')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(hash('sha256',$pending),$row['session_sha256']);
        self::assertSame('pending_mfa',$row['status']);
        $active=$this->adapter->completeMfa($pending,$this->otp(),'192.0.2.1');
        self::assertIsString($active);
        self::assertNotSame($pending,$active);
        self::assertTrue($this->adapter->isActiveBusinessSession($active));
        self::assertNull($this->adapter->completeMfa($pending,$this->otp(),'192.0.2.1'));
        self::assertSame(1,(int) $this->admin->query("SELECT count(*) FROM public.portal_auth_sessions WHERE status='active'")->fetchColumn());
        $stored=json_encode($this->admin->query('SELECT * FROM public.portal_auth_sessions')->fetchAll(PDO::FETCH_ASSOC));
        self::assertStringNotContainsString($pending,$stored);
        self::assertStringNotContainsString($active,$stored);
        self::assertTrue($this->adapter->revoke($active));
        // Separate connection proves visibility after function completion/autocommit.
        $reader=self::connect('portal_browser_runtime',(string) getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        $other=new PostgresPortalBrowserAuthentication($reader,'portal_browser_lab',$this->proof,$this->key,$this->pepper);
        self::assertFalse($other->isActiveBusinessSession($active));
        self::assertTrue($other->revoke($active));
    }

    public function test_database_credential_without_proof_key_cannot_issue_a_session(): void
    {
        $row=$this->runtime->query("SELECT * FROM public.portal_browser_material('synthetic@example.invalid','".self::ORG."')")->fetch(PDO::FETCH_ASSOC);
        $q=$this->runtime->prepare('SELECT public.portal_browser_begin(?,CAST(? AS uuid),?,?,?)');
        $q->execute(['synthetic@example.invalid',self::ORG,str_repeat('a',64),$row['credential_fingerprint'],str_repeat('0',64)]);
        self::assertFalse($q->fetchColumn());
        self::assertSame(0,(int) $this->admin->query('SELECT count(*) FROM public.portal_auth_sessions')->fetchColumn());
    }

    public function test_pending_and_active_sessions_fail_after_security_revision_changes(): void
    {
        $pending=$this->begin();
        $this->admin->exec("UPDATE public.portal_organizations SET security_revision=security_revision+1");
        self::assertNull($this->adapter->completeMfa($pending,$this->otp(),'192.0.2.1'));
        $active=$this->active();
        $this->admin->exec("UPDATE public.portal_memberships SET role='manager'");
        self::assertFalse($this->adapter->isActiveBusinessSession($active));
    }

    public function test_suspended_identity_cannot_use_existing_session(): void
    {
        $active=$this->active();
        $this->admin->exec("UPDATE public.portal_identities SET status='suspended',security_revision=security_revision+1");
        self::assertFalse($this->adapter->isActiveBusinessSession($active));
    }

    public function test_account_throttle_survives_changing_source_ip(): void
    {
        for ($i=1;$i<=6;$i++) {
            self::assertNull($this->adapter->beginPassword('synthetic@example.invalid','wrong-password-value',self::ORG,'192.0.2.'.$i));
        }
        self::assertNull($this->adapter->beginPassword('synthetic@example.invalid','synthetic-password-only',self::ORG,'192.0.2.99'));
        self::assertSame(0,(int) $this->admin->query('SELECT count(*) FROM public.portal_auth_sessions')->fetchColumn());
        self::assertGreaterThanOrEqual(1,(int) $this->admin->query('SELECT count(*) FROM public.portal_auth_attempt_buckets WHERE attempts=6')->fetchColumn());
    }

    public function test_caller_transaction_and_admin_role_are_rejected(): void
    {
        try {
            new PostgresPortalBrowserAuthentication($this->admin,'portal_browser_lab',$this->proof,$this->key,$this->pepper);
            self::fail('Admin role accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('Dedicated Portal authentication role required.',$error->getMessage());
        }
        $this->runtime->beginTransaction();
        try {
            $this->adapter->revoke(str_repeat('a',64));
            self::fail('Caller-owned transaction accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('Dedicated autocommit PostgreSQL connection required.',$error->getMessage());
        } finally {
            $this->runtime->rollBack();
        }
    }

    public function test_failed_active_insert_rolls_back_counter_and_pending_revocation(): void
    {
        $pending=$this->begin();
        $this->admin->exec("CREATE FUNCTION public.browser_test_refuse_active() RETURNS trigger LANGUAGE plpgsql AS
            'BEGIN IF NEW.status=''active'' THEN RAISE EXCEPTION ''synthetic insert failure''; END IF; RETURN NEW; END'");
        $this->admin->exec('CREATE TRIGGER browser_test_refuse BEFORE INSERT ON public.portal_auth_sessions FOR EACH ROW EXECUTE FUNCTION public.browser_test_refuse_active()');
        try {
            try {
                $this->adapter->completeMfa($pending,$this->otp(),'192.0.2.1');
                self::fail('Synthetic insert failure not observed.');
            } catch (RuntimeException $error) {
                self::assertSame('Portal authentication database unavailable.',$error->getMessage());
            }
            self::assertSame(-1,(int) $this->admin->query('SELECT mfa_last_used_counter FROM public.portal_identities')->fetchColumn());
            self::assertSame('pending_mfa',$this->admin->query('SELECT status FROM public.portal_auth_sessions')->fetchColumn());
        } finally {
            $this->admin->exec('DROP TRIGGER browser_test_refuse ON public.portal_auth_sessions');
            $this->admin->exec('DROP FUNCTION public.browser_test_refuse_active()');
        }
        self::assertIsString($this->adapter->completeMfa($pending,$this->otp(),'192.0.2.1'));
    }

    public function test_concurrent_mfa_workers_cannot_issue_two_active_sessions(): void
    {
        $pending=$this->begin();
        $input=json_encode(['pending'=>$pending,'code'=>$this->otp(),'proof'=>$this->proof,'key'=>base64_encode($this->key),'pepper'=>$this->pepper],JSON_THROW_ON_ERROR);
        $workers=[];
        for($i=0;$i<2;$i++){
            $process=proc_open([PHP_BINARY,__DIR__.'/../Support/BrowserMfaRaceWorker.php'],
                [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            self::assertIsResource($process);
            fwrite($pipes[0],$input); fclose($pipes[0]);
            $workers[]=[$process,$pipes];
        }
        $issued=0;
        foreach($workers as [$process,$pipes]){
            $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
            $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
            self::assertSame(0,proc_close($process),$err);
            $issued+=(int) json_decode($out,true,512,JSON_THROW_ON_ERROR)['issued'];
        }
        self::assertSame(1,$issued);
        self::assertSame(1,(int) $this->admin->query("SELECT count(*) FROM public.portal_auth_sessions WHERE status='active'")->fetchColumn());
    }

    public function test_http_transport_uses_real_postgresql_adapter_and_committed_revocation(): void
    {
        $directory=sys_get_temp_dir().'/portal-real-pg-http-'.bin2hex(random_bytes(12));
        mkdir($directory,0700);
        config(['app.url'=>'https://portal.example.test','session.driver'=>'file','session.files'=>$directory,'session.encrypt'=>true]);
        $this->app->instance(PortalBrowserAuthentication::class,$this->adapter);
        Route::middleware(['web',PortalBrowserAuthGuard::class])->group(function():void{
            Route::get('/__pg-auth/csrf',[PortalBrowserAuthController::class,'csrf']);
            Route::post('/__pg-auth/password',[PortalBrowserAuthController::class,'password']);
            Route::post('/__pg-auth/mfa',[PortalBrowserAuthController::class,'mfa']);
            Route::get('/__pg-auth/session',[PortalBrowserAuthController::class,'current']);
            Route::post('/__pg-auth/logout',[PortalBrowserAuthController::class,'logout']);
        });
        $cookie=null;
        $request=function(string $method,string $path,array $body=[],?string $token=null)use(&$cookie){
            $this->app['session']->forgetDrivers();
            $this->app->forgetInstance('session.store');
            $server=['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_ORIGIN'=>'https://portal.example.test','REMOTE_ADDR'=>'192.0.2.10'];
            if($token!==null)$server['HTTP_X_CSRF_TOKEN']=$token;
            $response=$this->call($method,'https://portal.example.test/__pg-auth/'.$path,[],
                $cookie===null?[]:[config('session.cookie')=>$cookie],[], $server,json_encode($body,JSON_THROW_ON_ERROR));
            foreach($response->headers->getCookies()as$c)if($c->getName()===config('session.cookie'))$cookie=$c->getValue();
            return $response;
        };
        try{
            $token=$request('GET','csrf')->assertOk()->json('csrf_token');
            $request('POST','password',['email'=>'synthetic@example.invalid','password'=>'synthetic-password-only','organization'=>self::ORG],$token)->assertStatus(202);
            $request('GET','session')->assertUnauthorized();
            $token=$request('GET','csrf')->assertOk()->json('csrf_token');
            $request('POST','mfa',['code'=>$this->otp()],$token)->assertOk()->assertExactJson(['authenticated'=>true]);
            $request('GET','session')->assertOk();
            $token=$request('GET','csrf')->assertOk()->json('csrf_token');
            $request('POST','logout',[],$token)->assertNoContent();
            $request('GET','session')->assertUnauthorized();
            self::assertSame(0,(int)$this->admin->query("SELECT count(*) FROM public.portal_auth_sessions WHERE status='active'")->fetchColumn());
        }finally{
            foreach(glob($directory.'/*')?:[]as$file)unlink($file);
            rmdir($directory);
        }
    }


    public function test_permission_preflight_adapter_is_not_http_authority_and_enforces_dedicated_role():void
    {
        try {
            new PostgresPortalPermissionPreflight($this->admin,'portal_browser_lab');
            self::fail('Privileged admin connection must not become permission preflight.');
        } catch(RuntimeException $e) {
            self::assertSame('Dedicated Portal authentication role required.',$e->getMessage());
        }
        $preflight = new PostgresPortalPermissionPreflight($this->runtime,'portal_browser_lab');
        self::assertFalse($preflight->hasCandidateGrant('forged-session',self::ORG,'services.view'));
        self::assertFalse($preflight->hasCandidateGrant(str_repeat('a',64),self::ORG,'services.*'));
        self::assertFalse($preflight->hasCandidateGrant(str_repeat('a',64),'foreign-organization','services.view'));
        self::assertSame(['backend'=>'Portal PostgreSQL permission preflight'],$preflight->__debugInfo());

        $grant=$this->admin->prepare("INSERT INTO public.portal_membership_grants
          (id,membership_id,permission_code,created_at,updated_at)
          VALUES(?,?,?,clock_timestamp(),clock_timestamp())");
        $grant->execute(['66666666-6666-4666-8666-666666666666',self::MEMBER,'services.view']);
        $active=$this->active();
        self::assertTrue($preflight->hasCandidateGrant($active,self::ORG,'services.view'));
        self::assertFalse($preflight->hasCandidateGrant($active,self::ORG,'billing.view'));
        self::assertFalse($preflight->hasCandidateGrant($active,'44444444-4444-4444-8444-444444444444','services.view'));

        $this->runtime->beginTransaction();
        try {
            try {
                $preflight->hasCandidateGrant($active,self::ORG,'services.view');
                self::fail('Caller-controlled transaction must never be accepted.');
            } catch(RuntimeException $e) {
                self::assertSame('Dedicated autocommit Portal permission connection required.',$e->getMessage());
            }
        } finally {
            $this->runtime->rollBack();
        }
        self::assertTrue($preflight->hasCandidateGrant($active,self::ORG,'services.view'));
        $this->admin->exec("UPDATE public.portal_memberships SET status='suspended'");
        self::assertFalse($preflight->hasCandidateGrant($active,self::ORG,'services.view'));
    }

    // F302: executable only against the disposable PostgreSQL 18 laboratory.
    // This is a session + explicit-grant preflight, NEVER resource ownership.
    private function permissionPreflight(?string $reference,string $organization,string $permission):bool
    {
        $q=$this->runtime->prepare('SELECT public.portal_browser_permission_preflight(?,CAST(? AS uuid),?)');
        $q->execute([$reference===null?null:hash('sha256',$reference),$organization,$permission]);
        return in_array($q->fetchColumn(),[true,1,'1','t'],true);
    }

    public function test_browser_permission_preflight_never_implies_role_or_cross_tenant_authorization():void
    {
        // No explicit grant means no permission even for a valid MFA session.
        $this->admin->exec("UPDATE public.portal_memberships SET role='owner'");
        $active=$this->active();
        self::assertFalse($this->permissionPreflight($active,self::ORG,'services.view'));
        self::assertFalse($this->permissionPreflight($active,self::ORG,'services.manage'));
        self::assertFalse($this->permissionPreflight($active,self::ORG,'*'));
        self::assertFalse($this->permissionPreflight($active,self::ORG,'services.*'));
        self::assertFalse($this->permissionPreflight(null,self::ORG,'services.view'));
        self::assertFalse($this->permissionPreflight(str_repeat('0',64),self::ORG,'services.view'));
        self::assertFalse($this->permissionPreflight($active,'44444444-4444-4444-8444-444444444444','services.view'));

        $this->deniedSql('SELECT * FROM public.portal_membership_grants');
        $this->deniedSql('SELECT * FROM public.portal_auth_sessions');
        $this->deniedSql('SELECT * FROM public.portal_memberships');
    }

    public function test_grant_revocation_invalidates_existing_business_permission_preflight():void
    {
        $grant='55555555-5555-4555-8555-555555555555';
        $insert=$this->admin->prepare("INSERT INTO public.portal_membership_grants
          (id,membership_id,permission_code,created_at,updated_at)
          VALUES(?,?,?,clock_timestamp(),clock_timestamp())");
        $insert->execute([$grant,self::MEMBER,'services.view']);
        self::assertSame(2,(int)$this->admin->query(
            'SELECT membership_revision FROM public.portal_memberships'
        )->fetchColumn(),'Explicit grant issuance must rotate membership revision.');
        $active=$this->active();
        self::assertTrue($this->permissionPreflight($active,self::ORG,'services.view'));
        foreach(['services.manage','billing.view','members.manage','services.*','*'] as $ungranted){
            self::assertFalse($this->permissionPreflight($active,self::ORG,$ungranted));
        }
        self::assertFalse($this->permissionPreflight($active,'44444444-4444-4444-8444-444444444444','services.view'));
        self::assertFalse($this->permissionPreflight(str_repeat('a',64),self::ORG,'services.view'));

        $delete=$this->admin->prepare('DELETE FROM public.portal_membership_grants WHERE id=?');
        $delete->execute([$grant]);
        self::assertSame(1,$delete->rowCount());
        self::assertSame(3,(int)$this->admin->query('SELECT membership_revision FROM public.portal_memberships')->fetchColumn());
        self::assertFalse($this->permissionPreflight($active,self::ORG,'services.view'));
        self::assertFalse($this->adapter->isActiveBusinessSession($active));
        // Reissue of the same grant cannot resurrect a session from a prior revision.
        $insert->execute([$grant,self::MEMBER,'services.view']);
        self::assertSame(4,(int)$this->admin->query('SELECT membership_revision FROM public.portal_memberships')->fetchColumn());
        self::assertFalse($this->permissionPreflight($active,self::ORG,'services.view'));
        self::assertTrue($this->adapter->revoke($active));
        self::assertFalse($this->permissionPreflight($active,self::ORG,'services.view'));
    }


    public function test_same_identity_in_two_tenants_cannot_use_one_tenant_session_for_other_tenant_grants():void
    {
        $otherOrg='44444444-4444-4444-8444-444444444444';
        $otherMember='77777777-7777-4777-8777-777777777777';
        $org=$this->admin->prepare("INSERT INTO public.portal_organizations
            (id,reference,display_name,status,security_revision)
            VALUES(?,'same-actor-second-tenant','Second synthetic organization','active',1)");
        $org->execute([$otherOrg]);
        $member=$this->admin->prepare("INSERT INTO public.portal_memberships
            (id,identity_id,organization_id,role,status,membership_revision)
            VALUES(?,?,?,'owner','active',1)");
        $member->execute([$otherMember,self::ACTOR,$otherOrg]);
        $grant=$this->admin->prepare("INSERT INTO public.portal_membership_grants
            (id,membership_id,permission_code,created_at,updated_at)
            VALUES(?,?,?,clock_timestamp(),clock_timestamp())");
        $grant->execute(['88888888-8888-4888-8888-888888888888',self::MEMBER,'services.view']);
        $grant->execute(['99999999-9999-4999-8999-999999999999',$otherMember,'services.view']);

        $activeA=$this->active();
        $check=new PostgresPortalPermissionPreflight($this->runtime,'portal_browser_lab');
        self::assertTrue($check->hasCandidateGrant($activeA,self::ORG,'services.view'));
        self::assertFalse($check->hasCandidateGrant($activeA,$otherOrg,'services.view'),
            'A real grant in B must not make a browser session for A usable in B.');
        self::assertFalse($check->hasCandidateGrant($activeA,$otherOrg,'services.manage'));

        // Revoking a grant only in B advances B's membership revision;
        // it must not revoke A's otherwise valid MFA session.
        $q=$this->admin->prepare('DELETE FROM public.portal_membership_grants WHERE membership_id=?');
        $q->execute([$otherMember]);
        self::assertSame(1,$q->rowCount());
        self::assertTrue($check->hasCandidateGrant($activeA,self::ORG,'services.view'));
        self::assertFalse($check->hasCandidateGrant($activeA,$otherOrg,'services.view'));

        // The session is finally invalidated by revoking its own grant.
        $q->execute([self::MEMBER]);
        self::assertSame(1,$q->rowCount());
        self::assertFalse($check->hasCandidateGrant($activeA,self::ORG,'services.view'));
        self::assertFalse($this->adapter->isActiveBusinessSession($activeA));
    }


    public function test_positive_grant_preflight_cannot_unlock_resource_or_http_authorization():void
    {
        $grant=$this->admin->prepare("INSERT INTO public.portal_membership_grants
            (id,membership_id,permission_code,created_at,updated_at)
            VALUES(?,?,?,clock_timestamp(),clock_timestamp())");
        $grant->execute(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',self::MEMBER,'services.view']);
        $session=$this->active();
        $permission=new PostgresPortalPermissionPreflight($this->runtime,'portal_browser_lab');
        self::assertTrue($permission->hasCandidateGrant($session,self::ORG,'services.view'),
            'Positive PG18 preflight must actually be exercised.');

        // F305: a valid password + TOTP + explicit service grant does not
        // establish ownership of an arbitrary resource. The application's
        // trusted membership/resource/session sources are still deny-all.
        $authority=$this->app->make(PortalAuthorityBoundary::class);
        $verdict=$authority->inspect(
            $session,
            new OrganizationId(self::ORG),
            'unverified-synthetic-service-asset',
            'services.view',
            time(),
        );
        self::assertFalse($verdict->syntheticChecksPass);
        self::assertFalse($verdict->canAuthorizeHttp());

        $routes=array_map(static fn($route):string=>$route->uri(),
            iterator_to_array($this->app['router']->getRoutes()));
        self::assertSame(['up'],$routes,'There must be no live business or auth route.');
    }


    public function test_preflight_api_name_and_sensitive_reference_prevent_accidental_authority_use():void
    {
        $preflight=new PostgresPortalPermissionPreflight($this->runtime,'portal_browser_lab');
        self::assertFalse(method_exists($preflight,'allows'),
            'A generic allows() could be mistaken for authoritative HTTP access.');
        self::assertFalse(method_exists($preflight,'authorize'),
            'Resource authorization must have separate ownership evidence.');
        self::assertTrue(method_exists($preflight,'hasCandidateGrant'));
        $method=new \ReflectionMethod(PostgresPortalPermissionPreflight::class,'hasCandidateGrant');
        $params=$method->getParameters();
        self::assertSame('businessReference',$params[0]->getName());
        self::assertCount(1,$params[0]->getAttributes(\SensitiveParameter::class),
            'Bearer reference must be redacted if a future exception creates a stack trace.');
    }


    public function test_permission_preflight_database_failure_exposes_no_sql_or_credentials():void
    {
        $grant=$this->admin->prepare("INSERT INTO public.portal_membership_grants
            (id,membership_id,permission_code,created_at,updated_at)
            VALUES(?,?,?,clock_timestamp(),clock_timestamp())");
        $grant->execute(['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',self::MEMBER,'services.view']);
        $active=$this->active();
        $preflight=new PostgresPortalPermissionPreflight($this->runtime,'portal_browser_lab');
        self::assertTrue($preflight->hasCandidateGrant($active,self::ORG,'services.view'));

        // Kill ONLY this test's dedicated disposable PG18 runtime connection,
        // never a production service. PG18's timeout variant waits for exit.
        $pid=(int)$this->runtime->query('SELECT pg_backend_pid()')->fetchColumn();
        $kill=$this->admin->prepare('SELECT pg_terminate_backend(CAST(? AS integer),1000)');
        $kill->execute([$pid]);
        self::assertTrue(in_array($kill->fetchColumn(),[true,1,'1','t'],true));

        try {
            $preflight->hasCandidateGrant($active,self::ORG,'services.view');
            self::fail('A terminated connection must never return stale grant=true.');
        } catch(RuntimeException $error) {
            self::assertSame('Portal permission preflight unavailable.',$error->getMessage());
            self::assertStringNotContainsString('portal_browser_runtime',$error->getMessage());
            self::assertStringNotContainsString('pg_terminate_backend',$error->getMessage());
        }
    }


    public function test_f309_service_candidate_owner_is_immutable_and_never_a_runtime_grant():void
    {
        // The durable ledger is a candidate only: it cannot authorize a
        // browser/CRM/Sites request and it has no operational read policies.
        $flags=$this->admin->query("SELECT relrowsecurity,relforcerowsecurity
            FROM pg_class WHERE oid='public.portal_service_resource_claims'::regclass")->fetch(PDO::FETCH_ASSOC);
        self::assertTrue(in_array($flags['relrowsecurity'],[true,'t',1,'1'],true));
        self::assertTrue(in_array($flags['relforcerowsecurity'],[true,'t',1,'1'],true));
        $this->deniedSql('SELECT * FROM public.portal_service_resource_claims');
        $this->deniedSql("INSERT INTO public.portal_service_resource_claims
            (id,organization_id,source_system,source_resource_ref,product_code)
            VALUES('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','".self::ORG."',
            'imagine_sites','synthetic-unauthorized','sites_onepage')");

        $claim='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $insert=$this->admin->prepare("INSERT INTO public.portal_service_resource_claims
            (id,organization_id,source_system,source_resource_ref,product_code)
            VALUES(?,?,?,?,?)");
        $insert->execute([$claim,self::ORG,'imagine_sites','candidate-onepage','sites_onepage']);
        self::assertSame(1,(int)$this->admin->query("SELECT security_revision
            FROM public.portal_service_resource_claims WHERE source_resource_ref='candidate-onepage'")->fetchColumn());

        // A second organization may not claim the same upstream resource.
        $this->admin->exec("INSERT INTO public.portal_organizations
            (id,reference,display_name,status,security_revision) VALUES
            ('44444444-4444-4444-8444-444444444444',
             'second-service-tenant','Second synthetic service tenant','active',1)");
        try {
            $insert->execute(['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                '44444444-4444-4444-8444-444444444444',
                'imagine_sites','candidate-onepage','sites_onepage']);
            self::fail('A source resource could be claimed by two organizations.');
        } catch(PDOException $e) {
            self::assertSame('23505',(string)$e->getCode());
        }

        foreach([
            "UPDATE public.portal_service_resource_claims SET organization_id='44444444-4444-4444-8444-444444444444'",
            "UPDATE public.portal_service_resource_claims SET source_resource_ref='adopted-site'",
            "UPDATE public.portal_service_resource_claims SET source_system='imagine_performance'",
            "UPDATE public.portal_service_resource_claims SET product_code='performance_ai'",
            "UPDATE public.portal_service_resource_claims SET security_revision=9",
        ] as $tamper) {
            try {
                $this->admin->exec($tamper);
                self::fail('Claim mutation unexpectedly accepted.');
            } catch(PDOException $e) {
                self::assertSame('42501',(string)$e->getCode());
            }
        }

        $this->admin->exec("UPDATE public.portal_service_resource_claims SET state='retired'");
        self::assertSame(2,(int)$this->admin->query(
            'SELECT security_revision FROM public.portal_service_resource_claims')->fetchColumn());
        try {
            $this->admin->exec("UPDATE public.portal_service_resource_claims SET state='candidate'");
            self::fail('A retired service could be silently reactivated.');
        } catch(PDOException $e) {
            self::assertSame('42501',(string)$e->getCode());
        }
        try {
            $this->admin->exec("UPDATE public.portal_service_resource_claims SET state='verified'");
            self::fail('A reviewer-only claim must never become verified.');
        } catch(PDOException $e) {
            self::assertContains((string)$e->getCode(),['23514','42501']);
        }
        self::assertSame(0,(int)$this->admin->query(
            "SELECT count(*) FROM public.portal_service_resource_claims WHERE state='candidate'")->fetchColumn());
    }


    public function test_f310_concurrent_org_claims_cannot_adopt_the_same_source_resource():void
    {
        $secondOrg='44444444-4444-4444-8444-444444444444';
        $this->admin->exec("INSERT INTO public.portal_organizations
            (id,reference,display_name,status,security_revision)
            VALUES('".$secondOrg."','racing-tenant','Other organization','active',1)");
        $insert="INSERT INTO public.portal_service_resource_claims
            (id,organization_id,source_system,source_resource_ref,product_code) VALUES
            (?,?, 'imagine_sites','same-origin-concurrent-service','sites_onepage')";
        $other=self::connect('postgres',(string)getenv('PGPASSWORD'));
        $this->admin->beginTransaction();
        try {
            $a=$this->admin->prepare($insert);
            $a->execute(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',self::ORG]);

            // B cannot slip a second ownership claim past A's uncommitted
            // UNIQUE(source_system,source_resource_ref) index entry.
            $other->beginTransaction();
            try {
                $other->exec("SET LOCAL lock_timeout = '250ms'");
                $b=$other->prepare($insert);
                try {
                    $b->execute(['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$secondOrg]);
                    self::fail('Second tenant bypassed uncommitted unique claim.');
                } catch(PDOException $error) {
                    self::assertSame('55P03',(string)$error->getCode(),
                        'Concurrent claim must wait for the first transaction.');
                }
            } finally {
                if ($other->inTransaction()) $other->rollBack();
            }
            $this->admin->commit();

            // Once A commits, B is refused permanently by the unique index.
            try {
                $other->prepare($insert)->execute([
                    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$secondOrg
                ]);
                self::fail('Committed source binding was reassigned to B.');
            } catch(PDOException $error) {
                self::assertSame('23505',(string)$error->getCode());
            }
            $q=$other->prepare("SELECT organization_id FROM public.portal_service_resource_claims
                WHERE source_resource_ref='same-origin-concurrent-service'");
            $q->execute();
            self::assertSame(self::ORG,$q->fetchColumn());
        } finally {
            if ($this->admin->inTransaction()) $this->admin->rollBack();
        }
    }

    public function test_f310_rollback_refuses_to_drop_populated_resource_claim_evidence():void
    {
        $insert=$this->admin->prepare("INSERT INTO public.portal_service_resource_claims
            (id,organization_id,source_system,source_resource_ref,product_code)
            VALUES(?,?,'imagine_performance','performance-candidate','performance_ai')");
        $insert->execute(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',self::ORG]);

        $migration=require __DIR__.'/../../database/migrations/2026_11_12_000017_create_portal_service_resource_claims.php';
        try {
            $migration->down();
            self::fail('An automated rollback dropped existing ownership evidence.');
        } catch(RuntimeException $e) {
            self::assertSame(
                'F309 rollback denied: service ownership evidence requires audited cleanup.',
                $e->getMessage()
            );
        }
        self::assertSame(1,(int)$this->admin->query(
            'SELECT count(*) FROM public.portal_service_resource_claims')->fetchColumn());
        $this->deniedSql('SELECT * FROM public.portal_service_resource_claims');
    }

    private function expectIdentityFenceRefusal(string $sql):void
    {
        try {
            $this->admin->exec($sql);
        } catch(PDOException $error) {
            self::assertSame('42501',(string)$error->getCode());
            return;
        }
        self::fail('Identity security mutation unexpectedly accepted.');
    }

    public function test_password_replacement_automatically_invalidates_active_session():void
    {
        $active=$this->active();
        $q=$this->admin->prepare('UPDATE public.portal_identities SET password_hash=?');
        $q->execute([PortalPasswordCredential::makeHash('replacement-password-only')]);
        self::assertSame(2,(int)$this->admin->query('SELECT security_revision FROM public.portal_identities')->fetchColumn());
        self::assertFalse($this->adapter->isActiveBusinessSession($active));
    }

    public function test_password_replacement_invalidates_pending_mfa_evidence():void
    {
        $pending=$this->begin();
        $q=$this->admin->prepare('UPDATE public.portal_identities SET password_hash=?');
        $q->execute([PortalPasswordCredential::makeHash('replacement-password-only')]);
        self::assertNull($this->adapter->completeMfa($pending,$this->otp(),'192.0.2.1'));
        self::assertSame('pending_mfa',$this->admin->query('SELECT status FROM public.portal_auth_sessions')->fetchColumn());
    }

    public function test_email_change_requires_reverification_and_invalidates_session():void
    {
        $active=$this->active();
        $this->expectIdentityFenceRefusal("UPDATE public.portal_identities SET email_normalized='changed@example.invalid'");
        $this->admin->exec("UPDATE public.portal_identities SET email_normalized='changed@example.invalid',email_verified_at=NULL");
        self::assertSame(2,(int)$this->admin->query('SELECT security_revision FROM public.portal_identities')->fetchColumn());
        self::assertFalse($this->adapter->isActiveBusinessSession($active));
    }

    public function test_counter_advance_preserves_session_but_backward_counter_and_revision_are_refused():void
    {
        $active=$this->active();
        $this->admin->exec('UPDATE public.portal_identities SET mfa_last_used_counter=mfa_last_used_counter+1');
        self::assertSame(1,(int)$this->admin->query('SELECT security_revision FROM public.portal_identities')->fetchColumn());
        self::assertTrue($this->adapter->isActiveBusinessSession($active));
        $this->expectIdentityFenceRefusal('UPDATE public.portal_identities SET mfa_last_used_counter=-1');
        $this->admin->exec('UPDATE public.portal_identities SET security_revision=3');
        $this->expectIdentityFenceRefusal('UPDATE public.portal_identities SET security_revision=1');
    }

    public function test_suspension_and_revocation_cannot_silently_restore_old_sessions():void
    {
        $active=$this->active();
        $this->admin->exec("UPDATE public.portal_identities SET status='suspended'");
        self::assertSame(2,(int)$this->admin->query('SELECT security_revision FROM public.portal_identities')->fetchColumn());
        self::assertFalse($this->adapter->isActiveBusinessSession($active));
        $this->expectIdentityFenceRefusal("UPDATE public.portal_identities SET status='active'");
        $this->admin->exec("UPDATE public.portal_identities SET status='revoked'");
        $this->expectIdentityFenceRefusal("UPDATE public.portal_identities SET status='invited'");
    }

    public function test_initial_invited_enrollment_preserves_challenge_revision_until_activation():void
    {
        $this->admin->exec('TRUNCATE public.portal_auth_sessions,public.portal_membership_grants,public.portal_memberships,
            public.portal_identities,public.portal_organizations CASCADE');
        $q=$this->admin->prepare("INSERT INTO public.portal_identities(id,email_normalized,status,security_revision)
            VALUES(?,'enrollment@example.invalid','invited',1)");
        $q->execute([self::ACTOR]);
        $this->admin->exec('UPDATE public.portal_identities SET email_verified_at=clock_timestamp()');
        $q=$this->admin->prepare('UPDATE public.portal_identities SET password_hash=?,mfa_secret_ciphertext=?');
        $q->execute([PortalPasswordCredential::makeHash('synthetic-password-only'),
            PortalTotpSecretCipher::seal(self::SECRET,$this->key)]);
        self::assertSame(1,(int)$this->admin->query('SELECT security_revision FROM public.portal_identities')->fetchColumn(),
            'Initial enrollment must retain the consumed email challenge revision.');
        $this->admin->exec("UPDATE public.portal_identities SET status='active',mfa_confirmed_at=clock_timestamp(),security_revision=security_revision+1");
        self::assertSame(2,(int)$this->admin->query('SELECT security_revision FROM public.portal_identities')->fetchColumn(),
            'Explicit activation increment is not incremented twice by the trigger.');
    }

    public function test_identity_binding_is_immutable_and_rollback_refuses_populated_database():void
    {
        $this->expectIdentityFenceRefusal("UPDATE public.portal_identities SET id='99999999-9999-4999-8999-999999999999'");
        $original=\Illuminate\Support\Facades\DB::getDefaultConnection();
        config(['database.connections.identity_fence_probe'=>[
            'driver'=>'pgsql','host'=>'127.0.0.1','port'=>'5432','database'=>'portal_browser_lab',
            'username'=>'postgres','password'=>(string)getenv('PGPASSWORD'),'charset'=>'utf8','prefix'=>'','search_path'=>'public',
        ]]);
        \Illuminate\Support\Facades\DB::setDefaultConnection('identity_fence_probe');
        try {
            $migration=require __DIR__.'/../../database/migrations/2026_11_12_000016_fence_portal_identity_revision.php';
            try {
                $migration->down();
                self::fail('Security fence removed while identity rows remain.');
            } catch(RuntimeException $error) {
                self::assertSame('Identity fence rollback denied: identity/session evidence requires audited cleanup.',$error->getMessage());
            }
        } finally {
            \Illuminate\Support\Facades\DB::disconnect('identity_fence_probe');
            \Illuminate\Support\Facades\DB::setDefaultConnection($original);
        }
    }

}
