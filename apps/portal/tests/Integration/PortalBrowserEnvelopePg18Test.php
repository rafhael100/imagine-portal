<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Http\Controllers\PortalBrowserAuthController;
use App\Http\Middleware\PortalBrowserAuthGuard;
use App\Infrastructure\Identity\EncryptedPostgresPortalSessionHandler;
use App\Infrastructure\Identity\EncryptedPostgresPortalSessionFactory;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Illuminate\Encryption\Encrypter;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

final class PortalBrowserEnvelopePg18Test extends TestCase
{
    private PDO $admin;
    private PDO $runtime;
    private Encrypter $cipher;
    private string $key;
    private const SID = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function setUp(): void
    {
        parent::setUp();
        if(getenv('APP_ENV')!=='testing'||getenv('PORTAL_BROWSER_PG_LAB')!=='DISPOSABLE_PG18'
            ||getenv('PGHOST')!=='127.0.0.1'||getenv('PGPORT')!=='5432'||getenv('PGDATABASE')!=='portal_browser_lab'){
            self::fail('Explicit disposable PG18 envelope laboratory required.');
        }
        $this->admin=self::connect('postgres',(string)getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab',$this->admin->query('SELECT current_database()')->fetchColumn());
        self::assertSame(18,(int)$this->admin->query("SELECT current_setting('server_version_num')::integer/10000")->fetchColumn());
        $this->admin->exec('TRUNCATE public.portal_browser_envelopes');
        $this->runtime=self::connect('portal_browser_envelope_runtime',(string)getenv('PORTAL_BROWSER_ENVELOPE_PASSWORD'));
        $this->key=random_bytes(32);
        $this->cipher=new Encrypter($this->key,'AES-256-CBC');
    }

    private static function connect(string $role,string $password):PDO
    {
        return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',$role,$password,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    }

    private function handler(?PDO $pdo=null,int $wait=2000):EncryptedPostgresPortalSessionHandler
    {
        return new EncryptedPostgresPortalSessionHandler($pdo??$this->runtime,'portal_browser_lab',$this->cipher,$wait);
    }

    private function other():PDO
    {
        return self::connect('portal_browser_envelope_runtime',(string)getenv('PORTAL_BROWSER_ENVELOPE_PASSWORD'));
    }

    public function test_f319_session_handler_export_never_embeds_the_encrypter_key():void
    {
        $handler=$this->handler();
        $dump=var_export($handler,true);
        self::assertIsString($dump);
        self::assertStringNotContainsString('Encrypter::__set_state',$dump);
        self::assertStringNotContainsString('privateCiphers',$dump);
        self::assertStringNotContainsString(base64_encode($this->key),$dump);
        self::assertStringNotContainsString(bin2hex($this->key),$dump);
        $printed=print_r($handler,true);
        self::assertStringNotContainsString(base64_encode($this->key),$printed);
        self::assertStringNotContainsString('cipher',$printed);

        self::assertTrue($handler->write(self::SID,'f319-sensitive-session'));
        self::assertSame('f319-sensitive-session',$handler->read(self::SID));
        self::assertTrue($handler->close());

        $class=EncryptedPostgresPortalSessionHandler::class;
        $wire='O:'.strlen($class).':"'.$class.'":0:{}';
        try {
            unserialize($wire,['allowed_classes'=>[$class]]);
            self::fail('A crafted handler ignored the runtime-encryption loader.');
        } catch(RuntimeException $error) {
            self::assertSame('Session handlers cannot be unserialized.',$error->getMessage());
        }
    }

    public function test_payload_is_encrypted_id_is_hashed_and_writes_are_committed():void
    {
        $handler=$this->handler();
        $plain=json_encode(['reference'=>str_repeat('a',64),'csrf'=>'synthetic-csrf-only'],JSON_THROW_ON_ERROR);
        self::assertTrue($handler->write(self::SID,$plain));
        self::assertFalse($this->runtime->inTransaction());
        $row=$this->admin->query('SELECT * FROM public.portal_browser_envelopes')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(hash('sha256',self::SID),$row['id_sha256']);
        self::assertStringNotContainsString(self::SID,json_encode($row));
        self::assertStringNotContainsString(str_repeat('a',64),$row['payload']);
        self::assertStringNotContainsString('synthetic-csrf-only',$row['payload']);
        $reader=$this->handler($this->other());
        self::assertSame($plain,$reader->read(self::SID));
        $reader->close();
        $before=$row['payload'];
        self::assertTrue($handler->write(self::SID,$plain));
        self::assertNotSame($before,$this->admin->query('SELECT payload FROM public.portal_browser_envelopes')->fetchColumn());
    }

    public function test_factory_refuses_reusing_the_same_pdo_for_two_handlers():void
    {
        $connection=$this->other();
        $creator=EncryptedPostgresPortalSessionFactory::creator(
            fn():PDO=>$connection,'portal_browser_lab',$this->cipher
        );

        $first=$creator();
        try {
            $creator();
            self::fail('A dedicated PDO was reused for multiple session handlers.');
        } catch (RuntimeException $error) {
            self::assertSame('Fresh Portal session PDO required.',$error->getMessage());
        }

        self::assertFalse($connection->inTransaction(),'A failed second handler cannot disturb the first.');
        self::assertTrue($first->write(self::SID,'original-private-session'));
        self::assertSame('original-private-session',$first->read(self::SID));
        $first->close();
        self::assertFalse($connection->inTransaction());

        // The normal HTTP creator must return a new connection each time.
        $fresh=EncryptedPostgresPortalSessionFactory::creator(
            fn():PDO=>$this->other(),'portal_browser_lab',$this->cipher
        );
        $reader=$fresh();
        self::assertSame('original-private-session',$reader->read(self::SID));
        $reader->close();
    }

    public function test_handler_cannot_be_cloned_or_serialized_during_a_live_transaction():void
    {
        $handler=$this->handler();
        self::assertTrue($handler->write(self::SID,'original'));
        self::assertSame('original',$handler->read(self::SID));
        self::assertTrue($this->runtime->inTransaction());

        // A shallow clone would duplicate owned=true and share one PDO; its
        // destructor could undo the live transaction of the original handler.
        try {
            $copy=clone $handler;
            self::fail('A live Portal session handler was cloneable.');
        } catch (\Error $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertTrue($this->runtime->inTransaction(),'Denied clone must not roll back owner.');

        try {
            serialize($handler);
            self::fail('Portal session handler was serializable.');
        } catch (RuntimeException $error) {
            self::assertSame('Session handlers cannot be serialized.',$error->getMessage());
        }
        self::assertTrue($this->runtime->inTransaction(),'Denied serialization must not release lock.');
        self::assertTrue($handler->write(self::SID,'after-denied-copy'));
        self::assertFalse($this->runtime->inTransaction());
        self::assertSame('after-denied-copy',$handler->read(self::SID));
        $handler->close();
    }

    public function test_ciphertext_cannot_be_transplanted_to_a_different_session_id():void
    {
        $handler=$this->handler();
        $otherId=str_repeat('B',40);
        $handler->write(self::SID,'source-private-session');
        $handler->write($otherId,'target-private-session');
        $q=$this->admin->prepare('UPDATE public.portal_browser_envelopes SET payload=
            (SELECT payload FROM public.portal_browser_envelopes WHERE id_sha256=?)
            WHERE id_sha256=?');
        $q->execute([hash('sha256',self::SID),hash('sha256',$otherId)]);
        try {
            $handler->read($otherId);
            self::fail('Ciphertext transplanted between session identifiers was accepted.');
        } catch(RuntimeException $error) {
            self::assertSame('Portal session storage unavailable.',$error->getMessage());
        }
        self::assertFalse($this->runtime->inTransaction());
        self::assertSame('source-private-session',$handler->read(self::SID));
        $handler->close();
    }

    public function test_unbound_legacy_ciphertext_is_rejected_without_fallback():void
    {
        $legacy=$this->cipher->encrypt('unbound-private-session',false);
        $q=$this->admin->prepare('INSERT INTO public.portal_browser_envelopes(id_sha256,payload,expires_at)
            VALUES(?,?,clock_timestamp()+interval \'2 hours\')');
        $q->execute([hash('sha256',self::SID),$legacy]);
        $handler=$this->handler();
        try {
            $handler->read(self::SID);
            self::fail('Legacy ciphertext without session binding was accepted.');
        } catch(RuntimeException $error) {
            self::assertSame('Portal session storage unavailable.',$error->getMessage());
        }
        self::assertFalse($this->runtime->inTransaction());
    }

    public function test_corrupt_ciphertext_fails_closed_and_releases_transaction():void
    {
        $handler=$this->handler();
        $handler->write(self::SID,'synthetic payload');
        $this->admin->exec("UPDATE public.portal_browser_envelopes SET payload='corrupt-ciphertext'");
        try{
            $handler->read(self::SID);
            self::fail('Corrupt ciphertext accepted.');
        }catch(RuntimeException $e){
            self::assertSame('Portal session storage unavailable.',$e->getMessage());
        }
        self::assertFalse($this->runtime->inTransaction());
    }

    public function test_rotation_deletes_old_id_and_writes_new_payload_atomically():void
    {
        $handler=$this->handler();
        $new=str_repeat('B',40);
        $handler->write(self::SID,'before');
        self::assertSame('before',$handler->read(self::SID));
        self::assertTrue($handler->destroy(self::SID));
        self::assertSame(1,(int)$this->admin->query('SELECT count(*) FROM public.portal_browser_envelopes')->fetchColumn());
        self::assertTrue($handler->write($new,'after'));
        self::assertSame(1,(int)$this->admin->query('SELECT count(*) FROM public.portal_browser_envelopes')->fetchColumn());
        self::assertSame(hash('sha256',$new),$this->admin->query('SELECT id_sha256 FROM public.portal_browser_envelopes')->fetchColumn());
        self::assertSame('',$handler->read(self::SID));
        $handler->close();
    }

    public function test_close_rolls_back_unfinished_request_and_deletion():void
    {
        $handler=$this->handler();
        $handler->write(self::SID,'before');
        self::assertSame('before',$handler->read(self::SID));
        $handler->destroy(self::SID);
        $handler->close();
        self::assertFalse($this->runtime->inTransaction());
        self::assertSame('before',$handler->read(self::SID));
        $handler->close();
    }

    public function test_session_lock_timeout_is_bounded_and_connection_is_reusable():void
    {
        $holder=$this->handler();
        $holder->write(self::SID,'before');
        self::assertSame('before',$holder->read(self::SID));
        $other=$this->other();
        $waiter=$this->handler($other,100);
        try{
            try{
                $waiter->read(self::SID);
                self::fail('Competing request bypassed the session lock.');
            }catch(RuntimeException $e){
                self::assertSame('Portal session storage unavailable.',$e->getMessage());
            }
            self::assertFalse($other->inTransaction());
        }finally{
            $holder->close();
        }
        self::assertSame('before',$waiter->read(self::SID));
        $waiter->close();
    }

    public function test_two_process_updates_serialize_without_lost_state():void
    {
        $handler=$this->handler();
        $handler->write(self::SID,json_encode(['counter'=>0],JSON_THROW_ON_ERROR));
        $input=json_encode(['id'=>self::SID,'key'=>base64_encode($this->key)],JSON_THROW_ON_ERROR);
        $workers=[];
        for($i=0;$i<2;$i++){
            $process=proc_open([PHP_BINARY,__DIR__.'/../Support/BrowserEnvelopeRaceWorker.php'],
                [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            self::assertIsResource($process);
            fwrite($pipes[0],$input);fclose($pipes[0]);
            $workers[]=[$process,$pipes];
        }
        foreach($workers as[$process,$pipes]){
            $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
            $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
            self::assertSame(0,proc_close($process),$err);
            self::assertTrue(json_decode($out,true,512,JSON_THROW_ON_ERROR)['done']);
        }
        self::assertSame(2,json_decode($handler->read(self::SID),true,512,JSON_THROW_ON_ERROR)['counter']);
        $handler->close();
    }

    public function test_expired_payload_is_not_read_and_gc_removes_expired_rows():void
    {
        $handler=$this->handler();
        $handler->write(self::SID,'before');
        $this->admin->exec("UPDATE public.portal_browser_envelopes SET expires_at=clock_timestamp()-interval '1 second'");
        self::assertSame('',$handler->read(self::SID));
        $handler->close();
        self::assertSame(1,$handler->gc(7200));
        self::assertSame(0,(int)$this->admin->query('SELECT count(*) FROM public.portal_browser_envelopes')->fetchColumn());
    }

    private function deny(PDO $pdo,string $sql):void
    {
        try{$pdo->query($sql);}
        catch(PDOException $e){self::assertSame('42501',(string)$e->getCode());return;}
        self::fail('Cross-role access was granted.');
    }

    public function test_identity_and_envelope_roles_cannot_cross_boundaries():void
    {
        $identity=self::connect('portal_browser_runtime',(string)getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        $this->deny($identity,'SELECT * FROM public.portal_browser_envelopes');
        $this->deny($identity,"SELECT public.portal_browser_envelope_read('".str_repeat('a',64)."')");
        $this->deny($this->runtime,'SELECT * FROM public.portal_browser_envelopes');
        $this->deny($this->runtime,'SELECT * FROM public.portal_identities');
        $this->deny($this->runtime,"SELECT * FROM public.portal_browser_material('x@example.invalid','22222222-2222-4222-8222-222222222222')");
        try{
            new EncryptedPostgresPortalSessionHandler($this->admin,'portal_browser_lab',$this->cipher);
            self::fail('Admin session role accepted.');
        }catch(RuntimeException $e){self::assertSame('Dedicated Portal envelope role required.',$e->getMessage());}
    }

    public function test_http_password_mfa_logout_use_real_sql_identity_and_sql_envelopes():void
    {
        fwrite(STDERR,"HTTP integration: preparing fixture\n");
        $actor='11111111-1111-4111-8111-111111111111';
        $org='22222222-2222-4222-8222-222222222222';
        $this->admin->exec('TRUNCATE public.portal_auth_sessions,public.portal_membership_grants,public.portal_memberships,
            public.portal_identities,public.portal_organizations,public.portal_auth_attempt_buckets CASCADE');
        $proof=bin2hex(random_bytes(32));$key=random_bytes(32);
        $this->admin->exec('DELETE FROM portal_browser_private.proof_key');
        $q=$this->admin->prepare('INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)');$q->execute([$proof]);
        $q=$this->admin->prepare("INSERT INTO public.portal_identities
         (id,email_normalized,status,password_hash,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision)
         VALUES(?,'synthetic@example.invalid','active',?,?,clock_timestamp()-interval '1 minute',clock_timestamp()-interval '1 minute',1)");
        $q->execute([$actor,PortalPasswordCredential::makeHash('synthetic-password-only'),
            PortalTotpSecretCipher::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$key)]);
        $q=$this->admin->prepare("INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision)
            VALUES(?,'envelope-fixture','Synthetic envelope only','active',1)");$q->execute([$org]);
        // F292: a second active organization exists, but has NO membership
        // for this synthetic identity. Its ID cannot authorize password login.
        $foreignOrg='44444444-4444-4444-8444-444444444444';
        $q=$this->admin->prepare("INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision)
            VALUES(?,'foreign-envelope-fixture','Unrelated synthetic company','active',1)");
        $q->execute([$foreignOrg]);
        $q=$this->admin->prepare("INSERT INTO public.portal_memberships(id,identity_id,organization_id,role,status,membership_revision)
            VALUES('33333333-3333-4333-8333-333333333333',?,?,'member','active',1)");$q->execute([$actor,$org]);
        $identity=self::connect('portal_browser_runtime',(string)getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        $adapter=new PostgresPortalBrowserAuthentication($identity,'portal_browser_lab',$proof,$key,bin2hex(random_bytes(32)));
        fwrite(STDERR,"HTTP integration: identity adapter ready\n");
        $this->app->instance(PortalBrowserAuthentication::class,$adapter);
        config(['app.url'=>'https://portal.example.test','session.driver'=>'portal_pg_encrypted','session.encrypt'=>false,'session.lottery'=>[0,100]]);
        // The tested factory creates a fresh dedicated PostgreSQL connection
        // on each request and avoids Manager::extend() rebind recursion.
        $createConnection=fn():PDO=>$this->other();
        $this->app['session']->extend('portal_pg_encrypted',
            EncryptedPostgresPortalSessionFactory::creator(
                $createConnection,'portal_browser_lab',$this->app['encrypter']
            )
        );
        Route::middleware(['web',PortalBrowserAuthGuard::class])->group(function():void{
            Route::get('/__pg-envelope/csrf',[PortalBrowserAuthController::class,'csrf']);
            Route::post('/__pg-envelope/password',[PortalBrowserAuthController::class,'password']);
            Route::post('/__pg-envelope/mfa',[PortalBrowserAuthController::class,'mfa']);
            Route::get('/__pg-envelope/session',[PortalBrowserAuthController::class,'current']);
            Route::post('/__pg-envelope/logout',[PortalBrowserAuthController::class,'logout']);
        });
        fwrite(STDERR,"HTTP integration: session driver and routes ready\n");
        $cookie=null;
        $request=function(string $method,string $path,array $body=[],?string $token=null,
            string $origin='https://portal.example.test',?string $fetchSite=null,
            string $contentType='application/json')use(&$cookie){
            fwrite(STDERR,"HTTP integration: request ".$method." ".$path."\n");
            $this->app['session']->forgetDrivers();$this->app->forgetInstance('session.store');
            $server=['CONTENT_TYPE'=>$contentType,'HTTP_ACCEPT'=>'application/json','HTTP_ORIGIN'=>$origin,'REMOTE_ADDR'=>'192.0.2.10'];
            if($token!==null)$server['HTTP_X_CSRF_TOKEN']=$token;
            if($fetchSite!==null)$server['HTTP_SEC_FETCH_SITE']=$fetchSite;
            $response=$this->call($method,'https://portal.example.test/__pg-envelope/'.$path,[],
                $cookie===null?[]:[config('session.cookie')=>$cookie],[],$server,json_encode($body,JSON_THROW_ON_ERROR));
            fwrite(STDERR,"HTTP integration: response ".$response->getStatusCode()."\n");
            // F300: all auth responses (also 400/401/419/503 paths) must
            // prevent caches and referrer leakage, even with real PG18
            // session persistence and the web middleware stack enabled.
            self::assertStringContainsString('no-store',(string)$response->headers->get('Cache-Control'));
            self::assertSame('no-cache',$response->headers->get('Pragma'));
            self::assertSame('nosniff',$response->headers->get('X-Content-Type-Options'));
            self::assertSame('no-referrer',$response->headers->get('Referrer-Policy'));
            // F299: prove the actual PostgreSQL-backed browser response
            // preserves the Portal-only host cookie security contract,
            // including cookie refreshes, rotations and session logout.
            self::assertSame('__Host-imagine-portal-session',config('session.cookie'));
            foreach($response->headers->getCookies()as$c) {
                if($c->getName()!==config('session.cookie'))continue;
                self::assertTrue($c->isSecure(),'Host-only session must be HTTPS.');
                self::assertTrue($c->isHttpOnly(),'Session cookie must be inaccessible to JavaScript.');
                self::assertEmpty($c->getDomain(),'No Domain attribute for Portal session.');
                self::assertSame('/',$c->getPath(),'__Host- cookie must be path /.');
                self::assertSame('lax',strtolower((string)$c->getSameSite()));
                $cookie=$c->getValue();
            }
            return $response;
        };
        try{
            $token=$request('GET','csrf')->assertOk()->json('csrf_token');
            $passwordBody=['email'=>'synthetic@example.invalid','password'=>'synthetic-password-only','organization'=>$org];
            // F291: the real PG-backed HTTP transport must reject invalid
            // CSRF and cross-site Origin *before* touching the identity DB.
            $request('POST','password',$passwordBody,'invalid-csrf-F291')->assertStatus(419);
            $request('POST','password',$passwordBody,$token,'https://attacker.example.invalid')->assertStatus(419);
            // F297: a sibling subdomain may be same-site, but it is not
            // same-origin. Even a valid CSRF and Origin cannot override the
            // Fetch Metadata signal from a cross-origin initiating page.
            $request('POST','password',$passwordBody,$token,
                'https://portal.example.test','same-site')->assertStatus(419);
            $request('POST','password',$passwordBody,$token,
                'https://portal.example.test','cross-site')->assertStatus(419);
            // F298: same-origin cookies and correct CSRF do not override the
            // JSON-only, bounded-body HTTP contract.
            $request('POST','password',$passwordBody,$token,
                'https://portal.example.test',null,'text/plain')->assertStatus(400);
            $oversizedPassword=$passwordBody;
            $oversizedPassword['padding']=str_repeat('x',4096);
            $request('POST','password',$oversizedPassword,$token)->assertStatus(400);
            self::assertSame(0,(int)$this->admin->query('SELECT count(*) FROM public.portal_auth_sessions')->fetchColumn());
            // F292: a valid identity + password cannot authenticate in a
            // different active organization without a server-side membership.
            $foreignPassword=$passwordBody;
            $foreignPassword['organization']=$foreignOrg;
            $request('POST','password',$foreignPassword,$token)->assertUnauthorized();
            self::assertSame(0,(int)$this->admin->query('SELECT count(*) FROM public.portal_auth_sessions')->fetchColumn());
            // A denied login rotates the anonymous session and CSRF token;
            // use its new token for the legitimate organization's login.
            $token=$request('GET','csrf')->assertOk()->json('csrf_token');
            // F293: a legitimate password creates only a pending MFA session.
            // Revoking then restoring its membership MUST NOT revive it: the
            // revision changed while the user had not yet completed MFA.
            $request('POST','password',$passwordBody,$token)->assertStatus(202);
            $q=$this->admin->prepare("UPDATE public.portal_memberships
                SET status='revoked',membership_revision=membership_revision+1
                WHERE identity_id=? AND organization_id=?");
            $q->execute([$actor,$org]);
            self::assertSame(1,$q->rowCount());
            $q=$this->admin->prepare("UPDATE public.portal_memberships SET status='active'
                WHERE identity_id=? AND organization_id=?");
            $q->execute([$actor,$org]);
            self::assertSame(1,$q->rowCount());
            $token=$request('GET','csrf')->assertOk()->json('csrf_token');
            $counter=(int)$this->admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
            $hmac=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),'12345678901234567890',true);
            $offset=ord($hmac[19])&15;$n=unpack('N',substr($hmac,$offset,4))[1]&0x7fffffff;
            $otp=str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);
            $counterBefore=(int)$this->admin->query('SELECT mfa_last_used_counter
                FROM public.portal_identities')->fetchColumn();
            $request('POST','mfa',['code'=>$otp],$token)->assertUnauthorized();
            self::assertSame($counterBefore,(int)$this->admin->query('SELECT mfa_last_used_counter
                FROM public.portal_identities')->fetchColumn(),'Denied pending MFA must not consume TOTP.');
            self::assertSame(0,(int)$this->admin->query("SELECT count(*) FROM public.portal_auth_sessions
                WHERE status='active'")->fetchColumn());
            // The new password request revokes the stale pending reference,
            // starts a fresh one at the current membership revision, and
            // rotates the anonymous session/cookie.
            $request('POST','password',$passwordBody,$token)->assertStatus(202);
            $token=$request('GET','csrf')->assertOk()->json('csrf_token');
            $counter=(int)$this->admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
            $hmac=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),'12345678901234567890',true);
            $offset=ord($hmac[19])&15;$n=unpack('N',substr($hmac,$offset,4))[1]&0x7fffffff;
            $otp=str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);
            $request('POST','mfa',['code'=>$otp],'invalid-mfa-csrf-F291')->assertStatus(419);
            $request('POST','mfa',['code'=>$otp],$token,'https://attacker.example.invalid')->assertStatus(419);
            $request('POST','mfa',['code'=>$otp],$token,
                'https://portal.example.test','same-site')->assertStatus(419);
            $request('POST','mfa',['code'=>$otp],$token,
                'https://portal.example.test','cross-site')->assertStatus(419);
            $request('POST','mfa',['code'=>$otp],$token,
                'https://portal.example.test',null,'text/plain')->assertStatus(400);
            $request('POST','mfa',['code'=>$otp,'padding'=>str_repeat('x',4096)],$token)->assertStatus(400);
            self::assertSame(0,(int)$this->admin->query("SELECT count(*) FROM public.portal_auth_sessions WHERE status='active'")->fetchColumn());
            $request('POST','mfa',['code'=>$otp],$token)->assertOk();
            $request('GET','session')->assertOk()->assertExactJson(['authenticated'=>true]);
            $payloads=json_encode($this->admin->query('SELECT payload FROM public.portal_browser_envelopes')->fetchAll(PDO::FETCH_COLUMN));
            self::assertStringNotContainsString('portal.browser_auth',$payloads);
            self::assertStringNotContainsString('synthetic-password-only',$payloads);
            $oldCookie=$cookie;
            $token=$request('GET','csrf')->assertOk()->json('csrf_token');
            $request('POST','logout',[],$token)->assertNoContent();
            $cookie=$oldCookie;
            $request('GET','session')->assertUnauthorized();
            self::assertSame(0,(int)$this->admin->query("SELECT count(*) FROM public.portal_auth_sessions WHERE status='active'")->fetchColumn());

            // F295: independently test durable revocation when the server-side
            // membership revision changes AFTER an authenticated session exists.
            // The ordinary password -> TOTP -> logout path above remains intact.
            // This synthetic seed isolates the current() cleanup behavior and
            // must never be used as a way to issue a real browser identity.
            $request('GET','csrf')->assertOk();
            $staleReference=bin2hex(random_bytes(32));
            $sql="INSERT INTO public.portal_auth_sessions
                (id,session_sha256,identity_id,organization_id,status,session_purpose,
                 identity_revision,membership_revision,organization_revision,
                 issued_at,expires_at,mfa_verified_at,created_at,updated_at)
                SELECT gen_random_uuid(),?,i.id,m.organization_id,'active','business',
                 i.security_revision,m.membership_revision,o.security_revision,
                 clock_timestamp()-interval '10 seconds',clock_timestamp()+interval '1 hour',
                 clock_timestamp(),clock_timestamp(),clock_timestamp()
                FROM public.portal_identities i
                JOIN public.portal_memberships m ON m.identity_id=i.id
                JOIN public.portal_organizations o ON o.id=m.organization_id
                WHERE i.id=? AND o.id=? AND m.status='active' AND o.status='active'";
            $seed=$this->admin->prepare($sql);
            $seed->execute([hash('sha256',$staleReference),$actor,$org]);
            self::assertSame(1,$seed->rowCount());
            $session=$this->app['session']->driver();
            $session->put('portal.browser_auth',['phase'=>'business','reference'=>$staleReference]);
            $session->save();
            $request('GET','session')->assertOk()->assertExactJson(['authenticated'=>true]);
            $replayCookie=$cookie;
            $update=$this->admin->prepare("UPDATE public.portal_memberships
                SET membership_revision=membership_revision+1
                WHERE identity_id=? AND organization_id=?");
            $update->execute([$actor,$org]);
            self::assertSame(1,$update->rowCount());
            $request('GET','session')->assertUnauthorized();
            self::assertSame(0,(int)$this->admin->query("SELECT count(*) FROM public.portal_auth_sessions
                WHERE status='active'")->fetchColumn(),'F295: invalid reference must be durably revoked.');
            $staleStatus=$this->admin->prepare('SELECT status FROM public.portal_auth_sessions WHERE session_sha256=?');
            $staleStatus->execute([hash('sha256',$staleReference)]);
            self::assertSame('revoked',$staleStatus->fetchColumn());
            $cookie=$replayCookie;
            $request('GET','session')->assertUnauthorized();

            // F296: identity revision invalidation is tested first, while
            // the account and organization remain active. Organization
            // suspension is tested last: PostgreSQL deliberately refuses
            // silently reactivating a suspended organization (F156 guard).
            foreach ([
                ['identity','public.portal_identities',$actor],
                ['organization','public.portal_organizations',$org],
            ] as [$kind,$table,$id]) {
                $request('GET','csrf')->assertOk();
                $reference=bin2hex(random_bytes(32));
                $seed->execute([hash('sha256',$reference),$actor,$org]);
                self::assertSame(1,$seed->rowCount(),$kind);
                $session=$this->app['session']->driver();
                $session->put('portal.browser_auth',['phase'=>'business','reference'=>$reference]);
                $session->save();
                $request('GET','session')->assertOk();
                $oldEnvelopeCookie=$cookie;

                // These table identifiers are hard-coded test fixtures.
                // Do not bypass F156's protection against org reactivation.
                $sql="UPDATE ".$table." SET security_revision=security_revision+1";
                if ($kind==='organization') {
                    $sql.=",status='suspended'";
                }
                $change=$this->admin->prepare($sql." WHERE id=?");
                $change->execute([$id]);
                self::assertSame(1,$change->rowCount(),$kind);
                $request('GET','session')->assertUnauthorized();
                $staleStatus->execute([hash('sha256',$reference)]);
                self::assertSame('revoked',$staleStatus->fetchColumn(),$kind);
                self::assertSame(0,(int)$this->admin->query("SELECT count(*) FROM public.portal_auth_sessions
                    WHERE status='active'")->fetchColumn(),$kind);

                $cookie=$oldEnvelopeCookie;
                $request('GET','session')->assertUnauthorized();
            }
        }finally{
            $this->app['session']->driver()->getHandler()->close();
        }
    }
}
