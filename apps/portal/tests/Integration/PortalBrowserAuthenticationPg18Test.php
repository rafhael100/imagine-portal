<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
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
}
