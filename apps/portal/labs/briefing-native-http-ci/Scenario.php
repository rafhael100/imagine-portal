<?php
declare(strict_types=1);
namespace NativeHttpBriefing;
use PDO;
use RuntimeException;
use PHPUnit\Framework\Assert as A;
use NativeBriefingFixture\{Fixture,Trace};
use PrivateBriefingHook\Caller;
use App\Infrastructure\Commerce\{PostgresOnepageOrders,PostgresOnepageEntryPayments};
use App\Domain\SitesIntegration\OnepageEntryPaymentTerms;
use App\Domain\Identity\Security\{PortalPasswordCredential,PortalTotpSecretCipher};
use App\Providers\{PortalDevelopmentAuthServiceProvider,OnepageCommerceServiceProvider};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Encryption\Encrypter;
require_once __DIR__.'/Client.php';require_once __DIR__.'/Pipe.php';
final class Scenario
{
    public static function run($app,PDO $admin,array $cluster,int $port,string $proof,string $key,string $pepper,\Closure $connect):void
    {
        // Called only after the unmodified #947 Laravel+admin gates and identity migrations.
        A::assertSame('portal_native_orders_ci',$cluster['current_database']);
        $scratch=dirname((string)getenv('PORTAL_NATIVE_ORDER_TEST_DATA')).'/native-http';
        A::assertDirectoryDoesNotExist($scratch);A::assertTrue(mkdir($scratch,0700));
        $files=[];$producer=null;
        try {
            $admin->exec(file_get_contents(database_path('security/portal_browser_envelopes.sql')));
            $admin->exec('ALTER ROLE portal_browser_envelope_runtime LOGIN'); // existing #939 session role, no new grant
            $company=$admin->query('SELECT gen_random_uuid()')->fetchColumn();
            $admin->prepare("INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision) VALUES(?,'native-order-company','Synthetic kernel briefing','active',1)")->execute([$company]);
            $id=$admin->query('SELECT gen_random_uuid()')->fetchColumn();$password='native-http-fixture-password';
            $admin->prepare("INSERT INTO public.portal_identities(id,email_normalized,password_hash,status,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision) VALUES(?,'buyer@example.invalid',?,'active',?,clock_timestamp(),clock_timestamp(),1)")
                ->execute([$id,PortalPasswordCredential::makeHash($password),PortalTotpSecretCipher::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$key)]);
            $admin->prepare("INSERT INTO public.portal_memberships(id,identity_id,organization_id,role,status,membership_revision) VALUES(gen_random_uuid(),?,?,'member','active',1)")->execute([$id,$company]);
            $origin='https://portal-dev.imaginecomunica.com.br';
            $identity=['origin'=>$origin,'host'=>'127.0.0.1','port'=>$port,'database'=>'portal_native_orders_ci',
                'identity_password'=>bin2hex(random_bytes(32)),'envelope_password'=>bin2hex(random_bytes(32)),
                'proof_key'=>$proof,'totp_key'=>base64_encode($key),'scope_pepper'=>$pepper];
            $commerce=['origin'=>$origin,'host'=>'127.0.0.1','port'=>$port,'database'=>'portal_native_orders_ci','password'=>bin2hex(random_bytes(32))];
            foreach(['identity.json'=>$identity,'commerce.json'=>$commerce] as $name=>$config){
                $path=$scratch.'/'.$name;$mask=umask(0077);try{$f=fopen($path,'x');}finally{umask($mask);}
                A::assertIsResource($f);$files[]=$path;try{$raw=json_encode($config,JSON_THROW_ON_ERROR);A::assertSame(strlen($raw),fwrite($f,$raw));}finally{fclose($f);}
            }
            unset($identity,$commerce,$raw,$config);
            $app['config']->set(['app.env'=>'staging','app.debug'=>false,'app.url'=>$origin,
                'portal_development_auth.enabled'=>true,'portal_development_auth.organization'=>$company,
                'portal_development_auth.secret_file'=>$scratch.'/identity.json','portal_onepage.enabled'=>true,
                'portal_onepage.connection_file'=>$scratch.'/commerce.json','session.driver'=>'portal_private_pg',
                'session.cookie'=>'__Host-imagine-portal-session','session.secure'=>true,'session.http_only'=>true,
                'session.domain'=>null,'session.path'=>'/','session.same_site'=>'strict','session.serialization'=>'json','session.encrypt'=>false]);
            $app->instance('encrypter',new Encrypter(random_bytes(32),'AES-256-CBC'));
            foreach([PortalDevelopmentAuthServiceProvider::class,OnepageCommerceServiceProvider::class] as $class){$provider=new $class($app);$provider->register();$provider->boot();}
            $app['router']->getRoutes()->refreshNameLookups();$app['router']->getRoutes()->refreshActionLookups();
            $kernel=$app->make(Kernel::class);$buyer=new Client($app,$kernel);$pending=new Client($app,$kernel);
            $login=['email'=>'buyer@example.invalid','password'=>$password,'organization'=>$company];
            A::assertSame(202,$buyer->post('/_development/auth/password',$login)[0]);
            A::assertSame(419,$buyer->call('POST','/_development/auth/mfa',['code'=>'000000'],'invalid-csrf')[0]);
            $n=(int)$admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
            $h=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),'12345678901234567890',true);$offset=ord($h[19])&15;
            $code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
            A::assertSame(200,$buyer->post('/_development/auth/mfa',['code'=>$code])[0]);
            A::assertSame(200,$buyer->call('GET','/_development/auth/session')[0]);
            A::assertSame(202,$pending->post('/_development/auth/password',$login)[0]);
            A::assertInstanceOf(\App\Infrastructure\Identity\EncryptedPostgresPortalSessionHandler::class,$app['session']->driver()->getHandler());
            A::assertGreaterThan(0,(int)$admin->query('SELECT count(*) FROM public.portal_browser_envelopes')->fetchColumn());
            $selection=['site'=>'onepage','channels'=>[],'tier'=>'minimum'];
            [$status,$draft]=$buyer->post('/_development/commerce/onepage/quotes',['request_key'=>'http-A','selection'=>$selection]);A::assertSame(200,$status);
            $offer=(new \App\Homologation\Commerce\ApprovedOfferCatalog())->quote('onepage');
            $terms=['scope'=>'onepage','payment_policy'=>'onepage-d0-d90-v1','onepage_payment_policy'=>[
                'version'=>'onepage-d0-d90-v1','currency'=>'BRL','setup_total_cents'=>$offer['setup_total_cents'],
                'setup_charges'=>$offer['setup_charges'],'monthly'=>$offer['monthly'],'entry_installment_key'=>'setup-1',
                'briefing_after_entry_confirmation'=>true,'publication_requires_separate_acceptance'=>true]];
            $publisher=$connect('portal_onepage_publisher');$q=$publisher->prepare('SELECT portal_onepage.publish_contract(?,?,?,?::jsonb,?::jsonb,?)');
            $q->execute(['http-onepage-v1','onepage-agreement',1,json_encode($draft['quote']),json_encode($terms),'Synthetic native HTTP fixture only.']);A::assertTrue($q->fetchColumn());
            $accept=static function(array $d,string $key)use($buyer):array{
                [$s,$review]=$buyer->call('GET','/_development/commerce/onepage/quotes/'.$d['id'].'/review');A::assertSame(200,$s);
                $yes=['accepted'=>true]+array_intersect_key($review['review'],array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
                [$s,$saved]=$buyer->post('/_development/commerce/onepage/quotes/'.$d['id'].'/accept',['request_key'=>$key,'acceptance'=>$yes]);A::assertSame(200,$s);A::assertSame('pending_payment',$saved['status']);return $saved;
            };
            $saved=$accept($draft,'http-accept-A');
            [$status,$draftB]=$buyer->post('/_development/commerce/onepage/quotes',['request_key'=>'http-B','selection'=>$selection]);A::assertSame(200,$status);$savedB=$accept($draftB,'http-accept-B');
            A::assertNotSame($saved['id'],$savedB['id']);
            [$status,,$native]=$buyer->call('GET','/_development/commerce/onepage/orders/'.$saved['id']);A::assertSame(200,$status);
            // Observe existing server-side state, never seed or export it. IDs are exclusively native responses.
            $state=$native->session()->get('portal.browser_auth');A::assertSame('business',$state['phase']);
            $prior=$connect('portal_onepage_runtime');$store=new PostgresOnepageOrders($prior,'portal_native_orders_ci');
            $a=$store->findOwnedAcceptedOrder($state['reference'],$saved['id']);A::assertNotNull($a);A::assertSame($draft['id'],$a->quoteId);unset($state,$native);
            $admin->exec(file_get_contents(database_path('security/portal_onepage_entry_payments.sql')));$admin->exec('ALTER ROLE portal_onepage_psp_registrar LOGIN');
            $account='native-entry-sandbox';$customer='cus_LABBriefing941';
            $admin->prepare("INSERT INTO portal_onepage.payment_accounts VALUES(?,'sandbox',true)")->execute([$account]);
            $writer=new PostgresOnepageEntryPayments($connect('portal_onepage_psp_registrar'),'portal_native_orders_ci',$account);
            $schedule=OnepageEntryPaymentTerms::schedule($a);A::assertNotNull($schedule);
            $today=$admin->query("SELECT (clock_timestamp() AT TIME ZONE 'America/Sao_Paulo')::date")->fetchColumn();A::assertGreaterThan($today,$schedule[1]['due_date']);A::assertGreaterThan($today,$schedule[2]['due_date']);
            A::assertSame('recorded',$writer->registerCustomer($a->id,$customer));A::assertSame('recorded',$writer->registerEntry($a->id,'pay_LABBriefing941',$customer,OnepageEntryPaymentTerms::reference($a,$account,$customer),33000,$schedule[0]['due_date'],'CREDIT_CARD'));
            [$status,,$native]=$buyer->call('GET','/_development/commerce/onepage/orders/'.$a->id);A::assertSame(200,$status);
            $state=$native->session()->get('portal.browser_auth');
            $q=$prior->prepare('SELECT portal_onepage.read_entry_payment(?,?::uuid,?)');$q->execute([hash('sha256',$state['reference']),$a->id,$account]);
            $binding=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);unset($state,$native);
            [$anchor,$ddl]=Fixture::render($binding,$schedule[1]['due_date']);$admin->exec($ddl);$producer=$connect('native_briefing_producer');
            Trace::pass('PASS BRIEFING_FIXTURES_READY');Trace::enter('CASES');
            $observe=static function(Client $client,array $d,array $s)use($connect,$prior,$cluster,$kernel,$a):array{
                $fresh=$connect('portal_onepage_runtime'); // new after each transaction boundary, caller-owned
                $request=$client->request('GET','/_development/commerce/onepage/orders/'.$s['id']);
                return Pipe::frame($d,$s,static function($channel)use($fresh,$prior,$cluster,$kernel,$request,$client,$a):array{
                    $result=Caller::observe($fresh,$prior,$cluster,$kernel,$request,$channel,$a->id);
                    $client->collect($result['response']);return $result;
                });
            };
            $closed=static function(array $r):void{A::assertTrue($r['projection_consulted']);A::assertNull($r['projection']);};
            $closed($observe($buyer,$draft,$saved));
            $admit=static function()use($producer,$anchor,$a):string{$q=$producer->prepare('SELECT native_briefing_fixture.admit(?::uuid,?,?)');$q->execute([$a->id,$anchor['event'],$anchor['digest']]);return $q->fetchColumn();};
            $producer->beginTransaction();A::assertSame('recorded',$admit());$closed($observe($buyer,$draft,$saved));$producer->rollBack();$closed($observe($buyer,$draft,$saved));
            $producer->beginTransaction();A::assertSame('recorded',$admit());$producer->commit();
            $r=$observe($buyer,$draft,$saved);A::assertTrue($r['projection_consulted']);A::assertSame(['next_step'=>'briefing'],$r['projection']);
            foreach([[$buyer,$draftB,$savedB],[$pending,$draft,$saved]] as [$client,$d,$s]){
                try{$observe($client,$d,$s);A::fail('Expected refusal before projection.');}catch(RuntimeException $e){A::assertSame('HOOK_BEFORE_PROJECTION_REFUSED',$e->getMessage());}
            }
            A::assertSame('replay',$admit());A::assertSame(['next_step'=>'briefing'],$observe($buyer,$draft,$saved)['projection']);
            $rows=$connect('portal_native_test_bootstrap')->query('SELECT payload FROM native_briefing_fixture.evidence')->fetchAll(PDO::FETCH_COLUMN);A::assertCount(1,$rows);A::assertEquals($anchor,json_decode($rows[0],true));
            A::assertSame('recorded',$writer->revokeEntry($a->id,'LAB941-evidence-revoked'));$closed($observe($buyer,$draft,$saved));
            A::assertSame('replay',$admit());$closed($observe($buyer,$draft,$saved));
            A::assertSame(['pending_payment','pending_payment'],$admin->query('SELECT status FROM portal_onepage.pending_orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            A::assertSame(204,$buyer->post('/_development/auth/logout',[])[0]);A::assertSame(401,$buyer->call('GET','/_development/auth/session')[0]);
        } finally {
            $clean=true;try{if($producer instanceof PDO&&$producer->inTransaction())$producer->rollBack();}catch(\Throwable){$clean=false;}
            foreach($files as $path){$s=@lstat($path);if(!$s||is_link($path)||$s['uid']!==posix_geteuid()){$clean=false;continue;}$clean=unlink($path)&&$clean;}
            $clean=rmdir($scratch)&&$clean;if(!$clean)throw new RuntimeException('HTTP_FIXTURE_CLEANUP_REFUSED');
        }
    }
}
