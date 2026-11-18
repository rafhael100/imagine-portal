<?php
declare(strict_types=1);
namespace NativeHttpBriefing;
use PDO;
use RuntimeException;
use PHPUnit\Framework\Assert as A;
use NativeBriefingFixture\Trace;
use App\Domain\Commerce\D0\FirstBriefingRelease;
use App\Infrastructure\Commerce\PostgresD0EntryStore;
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,AsaasTrustedPaymentBinding,AsaasTrustedPaymentBindingSource,AsaasVerifiedSettlementSource};
use App\Infrastructure\Commerce\{PostgresOnepageOrders,PostgresOnepageEntryPayments};
use App\Domain\SitesIntegration\OnepageEntryPaymentTerms;
use App\Domain\Identity\Security\{PortalPasswordCredential,PortalTotpSecretCipher};
use App\Providers\{PortalDevelopmentAuthServiceProvider,OnepageCommerceServiceProvider};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Encryption\Encrypter;
require_once __DIR__.'/Client.php';require_once __DIR__.'/RouteRebinder.php';require_once __DIR__.'/TrackedReader.php';
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
            $neighborId=$admin->query('SELECT gen_random_uuid()')->fetchColumn();
            $admin->prepare("INSERT INTO public.portal_identities(id,email_normalized,password_hash,status,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision) VALUES(?,'neighbor@example.invalid',?,'active',?,clock_timestamp(),clock_timestamp(),1)")
                ->execute([$neighborId,PortalPasswordCredential::makeHash($password),PortalTotpSecretCipher::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$key)]);
            $admin->prepare("INSERT INTO public.portal_memberships(id,identity_id,organization_id,role,status,membership_revision) VALUES(gen_random_uuid(),?,?,'member','active',1)")->execute([$neighborId,$company]);
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
            $kernel=$app->make(Kernel::class);$buyer=new Client($app,$kernel);$pending=new Client($app,$kernel);$neighbor=new Client($app,$kernel);
            $login=['email'=>'buyer@example.invalid','password'=>$password,'organization'=>$company];
            A::assertSame(202,$buyer->post('/_development/auth/password',$login)[0]);
            A::assertSame(419,$buyer->call('POST','/_development/auth/mfa',['code'=>'000000'],'invalid-csrf')[0]);
            $n=(int)$admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
            $h=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),'12345678901234567890',true);$offset=ord($h[19])&15;
            $code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
            A::assertSame(200,$buyer->post('/_development/auth/mfa',['code'=>$code])[0]);
            A::assertSame(200,$buyer->call('GET','/_development/auth/session')[0]);
            A::assertSame(202,$neighbor->post('/_development/auth/password',array_replace($login,['email'=>'neighbor@example.invalid']))[0]);
            $n=(int)$admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
            $h=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),'12345678901234567890',true);$offset=ord($h[19])&15;
            $code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
            A::assertSame(200,$neighbor->post('/_development/auth/mfa',['code'=>$code])[0]);
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
            // Same accepted order A, same native binding: install only the candidate D0 ledger.
            $admin->exec(file_get_contents(database_path('security/portal_onepage_d0_candidate.sql')));
            $admin->exec("CREATE OR REPLACE FUNCTION portal_onepage.d0_account_gate() RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT 'native-entry-sandbox'::text $$");
            $admin->exec('ALTER ROLE portal_onepage_d0_verifier LOGIN');
            $producer=$connect('portal_onepage_d0_verifier');
            $app['config']->set('portal_onepage_d0.enabled',true);
            $provider=new \App\Providers\OnepageD0CandidateServiceProvider($app);$provider->register();$provider->boot();
            $fake=new class($binding) implements AsaasOfficialPaymentReader {
                public int $calls=0;public bool $fail=false;
                public function __construct(private array $binding){}
                public function fetchPayment(string $id):?array {
                    $this->calls++;if($this->fail)throw new RuntimeException('SYNTHETIC_PROVIDER_FAILURE');
                    if($id!==$this->binding['payment_id'])return null;
                    return ['id'=>$id,'customer'=>$this->binding['customer_id'],'externalReference'=>$this->binding['external_reference'],
                        'billingType'=>$this->binding['billing_type'],'value'=>'330.00','status'=>'RECEIVED'];
                }
            };
            $tracked=null;$liveReaders=[];
            $wire=static function()use($app,$producer,$account,$fake,$port,$cluster,&$tracked,&$liveReaders):PostgresD0EntryStore {
                $next=new TrackedReader($port,$cluster);
                foreach($liveReaders as $old)A::assertNotSame($old->backend,$next->backend);
                $liveReaders[]=$next;$tracked=$next; // keep predecessors alive, so PID reuse cannot satisfy the proof
                $store=new PostgresD0EntryStore($next,$producer,'portal_native_orders_ci',$account);
                RouteRebinder::replace($app,new FirstBriefingRelease($store,$fake));return $store;
            };
            $entry=$wire();$app['router']->getRoutes()->refreshNameLookups();$app['router']->getRoutes()->refreshActionLookups();
            $command='/_development/commerce/onepage/orders/'.$a->id.'/first-briefing-release';
            $read='/_development/commerce/onepage/orders/'.$a->id.'/briefing-state';
            Trace::pass('PASS BRIEFING_HTTP_FIXTURES_READY');Trace::enter('CASES');
            A::assertSame('unavailable',$buyer->call('GET',$read)[1]['next_step']);A::assertSame(0,$fake->calls);
            A::assertSame(419,$buyer->call('POST',$command,(object)[],'invalid-csrf')[0]);
            A::assertSame(401,$pending->command($command)[0]);
            A::assertSame(503,$neighbor->command($command)[0]);
            A::assertSame(422,$buyer->post($command,['paid'=>true])[0]);A::assertSame(0,$fake->calls);
            A::assertSame(503,$buyer->command('/_development/commerce/onepage/orders/'.$savedB['id'].'/first-briefing-release')[0]);A::assertSame(0,$fake->calls);
            // Exercise rollback on A with restricted SQL, without treating the digest as real PSP evidence.
            [$status,,$native]=$buyer->call('GET','/_development/commerce/onepage/orders/'.$a->id);A::assertSame(200,$status);
            $reference=$native->session()->get('portal.browser_auth')['reference'];unset($native);
            $snapshot=$entry->inspect($reference,$a->id);
            $rollbackSource=new class($binding) implements AsaasTrustedPaymentBindingSource {
                public function __construct(private array $b){}
                public function findInstallationPayment(string $order):?AsaasTrustedPaymentBinding {
                    return new AsaasTrustedPaymentBinding($this->b['tenant_ref'],$order,$this->b['contract_ref'],$this->b['contract_revision'],'onepage',
                        $this->b['payment_id'],$this->b['customer_id'],$this->b['external_reference'],$this->b['amount_cents'],$this->b['billing_type']);
                }
            };
            $rollbackProof=(new AsaasVerifiedSettlementSource($rollbackSource,clone $fake))->findCurrentSettledOrder($a->id);A::assertNotNull($rollbackProof);
            $producer->beginTransaction();
            $q=$producer->prepare('SELECT portal_onepage.d0_admit(?,?::uuid,?,?::jsonb,?,?,clock_timestamp())');
            $q->execute([hash('sha256',$reference),$a->id,$account,json_encode($snapshot,JSON_THROW_ON_ERROR),$rollbackProof->settlementRef,$rollbackProof->settlementSha256]);A::assertSame('recorded',$q->fetchColumn());
            // A normal reader waits on the binding; the independent auditor proves invisibility, not a fabricated HTTP response.
            $audit=$connect('portal_native_test_bootstrap');A::assertSame(0,(int)$audit->query('SELECT count(*) FROM portal_onepage.entry_confirmations')->fetchColumn());
            $producer->rollBack();unset($reference,$snapshot,$rollbackProof);$wire();
            A::assertSame('unavailable',$buyer->call('GET',$read)[1]['next_step']);A::assertSame(0,$fake->calls);
            // Explicit failed attempt, then only a new explicit attempt may query again.
            $fake->fail=true;A::assertSame(503,$buyer->command($command)[0]);A::assertSame(1,$fake->calls);
            $wire();A::assertSame('unavailable',$buyer->call('GET',$read)[1]['next_step']);A::assertSame(1,$fake->calls);
            $fake->fail=false;A::assertSame('briefing_available',$buyer->command($command)[1]['next_step']);A::assertSame(2,$fake->calls);
            A::assertSame(1,(int)$audit->query('SELECT count(*) FROM portal_onepage.entry_confirmations')->fetchColumn());
            $previous=$tracked;$previousCount=$previous->inspects;$wire();
            A::assertSame(0,$tracked->inspects);A::assertSame('briefing_available',$buyer->call('GET',$read)[1]['next_step']);
            A::assertSame(1,$tracked->inspects);A::assertSame($previousCount,$previous->inspects);
            A::assertSame('briefing_available',$buyer->command($command)[1]['next_step']);A::assertSame(2,$fake->calls);
            A::assertSame('unavailable',$neighbor->call('GET',$read)[1]['next_step']);A::assertSame(2,$fake->calls);
            A::assertSame('recorded',$writer->revokeEntry($a->id,'synthetic-D0-revoked'));$wire();
            A::assertSame('unavailable',$buyer->call('GET',$read)[1]['next_step']);A::assertSame(503,$buyer->command($command)[0]);A::assertSame(2,$fake->calls);
            A::assertSame(['pending_payment','pending_payment'],$admin->query('SELECT status FROM portal_onepage.pending_orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            A::assertSame(204,$buyer->post('/_development/auth/logout',[])[0]);A::assertSame(401,$buyer->call('GET','/_development/auth/session')[0]);
        } finally {
            $clean=true;try{if($producer instanceof PDO&&$producer->inTransaction())$producer->rollBack();}catch(\Throwable){$clean=false;}
            foreach($files as $path){$s=@lstat($path);if(!$s||is_link($path)||$s['uid']!==posix_geteuid()){$clean=false;continue;}$clean=unlink($path)&&$clean;}
            $clean=rmdir($scratch)&&$clean;if(!$clean)throw new RuntimeException('HTTP_FIXTURE_CLEANUP_REFUSED');
        }
    }
}
