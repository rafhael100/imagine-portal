<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Illuminate\Support\Facades\Artisan;
use PDO;
use PDOException;
use Tests\TestCase;
use NativeBriefingFixture\Trace;

/** Product integration against a fresh, explicitly requested local PG18 cluster. */
class NativeBriefingPgTest extends TestCase
{
    private readonly PDO $admin;
    private int $port;
    private string $proof;
    private string $key;
    private string $pepper;

    private function connect(string $role):PDO
    {
        return new PDO('pgsql:host=127.0.0.1;port='.$this->port.';dbname=portal_native_orders_ci;connect_timeout=3',
            $role,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    }

    private function expectedServerAddress():string
    {
        self::assertSame('true',getenv('GITHUB_ACTIONS'));
        self::assertSame('REVIEWED_SINGLE_OPENED_PR',getenv('BRIEFING_CI_SCOPE'));
        return '127.0.0.1';
    }

    private function prepareDatabase():void
    {
        self::assertSame('DISPOSABLE_PG18',getenv('PORTAL_NATIVE_ORDER_TEST'));
        $port=getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        self::assertIsString($port);self::assertMatchesRegularExpression('/\A[0-9]{4,5}\z/D',$port);
        $this->port=(int)$port;
        $serverAddress=$this->expectedServerAddress();
        $admin=$this->connect('portal_native_test_bootstrap');
        config(['database.default'=>'pgsql','database.connections.pgsql'=>[
            'driver'=>'pgsql','host'=>'127.0.0.1','port'=>$this->port,'database'=>'portal_native_orders_ci',
            'username'=>'portal_native_test_bootstrap','password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable',
        ]]);
        $this->app['db']->purge('pgsql');
        $attestation=\NativeBriefingFixture\CiGate::beforeWrites('portal_native_orders_ci');
        Trace::pass('PASS BRIEFING_NEW_DATABASE_GATE_PASSED');
        Trace::enter('ADMIN_GATE');
        $this->admin=\NativeBriefingFixture\CiGate::adminBeforeWrites($admin,$attestation);
        Trace::pass('PASS BRIEFING_LARAVEL_SQL_BEFORE_DDL');
        Trace::enter('MIGRATIONS');
        foreach ([
            '2026_11_09_000002_create_portal_identity_foundation.php',
            '2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php',
            '2026_11_10_000004_add_org_revision_to_portal_sessions.php',
            '2026_11_10_000005_create_portal_auth_throttle_buckets.php',
            '2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php',
            '2026_11_11_000012_fence_portal_membership_revision.php',
            '2026_11_11_000013_fence_portal_organization_revision.php',
            '2026_11_11_000014_guard_portal_session_issuance.php',
            '2026_11_12_000016_fence_portal_identity_revision.php',
        ] as $migration) {
            \NativeBriefingFixture\CiGate::sameConnection($this->app['db']->connection('pgsql')->getPdo(),$attestation['pdo']);
            self::assertSame(0,Artisan::call('migrate',['--path'=>'database/migrations/'.$migration,'--force'=>true,'--no-interaction'=>true]));
        }
        Trace::pass('PASS BRIEFING_MIGRATIONS_COMPLETE');
        Trace::enter('SECURITY_DDL');
        foreach (['portal_browser_auth.sql','portal_browser_principal_candidate.sql','portal_onepage_orders.sql'] as $file) {
            $this->admin->exec(file_get_contents(database_path('security/'.$file)));
        }
        // These logins exist only inside this empty, independently identified disposable cluster.
        foreach (['portal_browser_runtime','portal_onepage_runtime','portal_onepage_publisher'] as $role) {
            $this->admin->exec('ALTER ROLE '.$role.' LOGIN');
        }
        $this->proof=bin2hex(random_bytes(32));$this->key=random_bytes(32);$this->pepper=bin2hex(random_bytes(32));
        $this->admin->prepare('INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)')->execute([$this->proof]);
    }

    private function authenticate(string $name,string $company,string $role='member'):array
    {
        $id=$this->admin->query('SELECT gen_random_uuid()')->fetchColumn();
        $hash=PortalPasswordCredential::makeHash('native-order-fixture-password');
        $sealed=PortalTotpSecretCipher::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$this->key);
        $this->admin->prepare("INSERT INTO public.portal_identities(id,email_normalized,password_hash,status,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision)
            VALUES(?,?,?,'active',?,clock_timestamp()-interval '1 minute',clock_timestamp()-interval '1 minute',1)")
            ->execute([$id,$name.'@example.invalid',$hash,$sealed]);
        $this->admin->prepare("INSERT INTO public.portal_memberships(id,identity_id,organization_id,role,status,membership_revision) VALUES(gen_random_uuid(),?,?,?,'active',1)")
            ->execute([$id,$company,$role]);
        $auth=new PostgresPortalBrowserAuthentication($this->connect('portal_browser_runtime'),'portal_native_orders_ci',$this->proof,$this->key,$this->pepper);
        $pending=$auth->beginPassword($name.'@example.invalid','native-order-fixture-password',$company,'192.0.2.10');
        self::assertIsString($pending);
        $counter=(int)$this->admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
        $h=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),'12345678901234567890',true);
        $offset=ord($h[19])&15;$code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
        $session=$auth->completeMfa($pending,$code,'192.0.2.10');
        self::assertIsString($session);self::assertTrue($auth->isActiveBusinessSession($session));
        return [$id,$session,$pending];
    }

    protected function afterAcceptedOrder(PDO $bootstrap,PostgresOnepageOrders $orders,\App\Domain\Commerce\OnepageAcceptedOrder $order,string $reference):void {}

    private function denySql(PDO $db,string $sql,array $args=[],string $state='42501'):void
    {
        try {$q=$db->prepare($sql);$q->execute($args);self::fail('Expected SQL refusal.');}
        catch(PDOException $e){self::assertSame($state,$e->getCode());}
    }


    public function test_private_durable_briefing_only(): void
    {
        require_once __DIR__.'/Fixture.php'; require_once __DIR__.'/CiGate.php';

        Trace::pass('PASS BRIEFING_TEST_ENTERED');
        Trace::enter('NEW_DATABASE_GATE');
        $this->prepareDatabase(); // Existing helpers only; no original test body is run.
        Trace::pass('PASS BRIEFING_SCHEMA_COMPLETE');
        Trace::enter('FIXTURES');
        $company=$this->admin->query('SELECT gen_random_uuid()')->fetchColumn();
        $foreign=$this->admin->query('SELECT gen_random_uuid()')->fetchColumn();
        $q=$this->admin->prepare("INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision) VALUES(?,?,'Private briefing fixture','active',1)");
        $q->execute([$company,'native-order-company']);$q->execute([$foreign,'native-other-company']);
        [$buyer,$session]=$this->authenticate('onepage-own',$company);
        [,$same]=$this->authenticate('onepage-same-company',$company);
        [,$other]=$this->authenticate('onepage-other-company',$foreign);
        $auth=new PostgresPortalBrowserAuthentication($this->connect('portal_browser_runtime'),'portal_native_orders_ci',$this->proof,$this->key,$this->pepper);
        $noMfa=$auth->beginPassword('onepage-own@example.invalid','native-order-fixture-password',$company,'192.0.2.10');
        self::assertIsString($noMfa);
        $runtime=$this->connect('portal_onepage_runtime');
        $store=new PostgresOnepageOrders($runtime,'portal_native_orders_ci');
        $selection=['site'=>'onepage','channels'=>[],'tier'=>'minimum'];
        $draft=$store->createDraft($session,'briefing-A',$selection);
        $offer=(new ApprovedOfferCatalog())->quote('onepage');
        $terms=['scope'=>'onepage','payment_policy'=>'onepage-d0-d90-v1','onepage_payment_policy'=>[
            'version'=>'onepage-d0-d90-v1','currency'=>'BRL','setup_total_cents'=>$offer['setup_total_cents'],
            'setup_charges'=>$offer['setup_charges'],'monthly'=>$offer['monthly'],'entry_installment_key'=>'setup-1',
            'briefing_after_entry_confirmation'=>true,'publication_requires_separate_acceptance'=>true]];
        $publisher=$this->connect('portal_onepage_publisher');
        $q=$publisher->prepare('SELECT portal_onepage.publish_contract(?,?,?,?::jsonb,?::jsonb,?)');
        $q->execute(['publication-onepage-v1','onepage-agreement',1,json_encode($draft['quote'],JSON_THROW_ON_ERROR),json_encode($terms,JSON_THROW_ON_ERROR),'Private synthetic fixture only.']);
        self::assertTrue($q->fetchColumn());
        $accept=function(array $draft,string $key) use($store,$session) {
            $review=$store->review($session,$draft['id']);self::assertIsArray($review);
            $yes=['accepted'=>true]+array_intersect_key($review,array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
            $saved=$store->accept($session,$key,$draft['id'],$yes);
            self::assertSame('pending_payment',$saved['status']);
            $o=$store->findOwnedAcceptedOrder($session,$saved['id']);self::assertNotNull($o);return $o;
        };
        $a=$accept($draft,'briefing-accept-A');
        $b=$accept($store->createDraft($session,'briefing-B',$selection),'briefing-accept-B');
        self::assertSame($a->buyerId,$b->buyerId);self::assertNotSame($a->id,$b->id);
        $this->admin->exec(file_get_contents(database_path('security/portal_onepage_entry_payments.sql')));
        $this->admin->exec('ALTER ROLE portal_onepage_psp_registrar LOGIN');
        $account='native-entry-sandbox';$customer='cus_LABBriefing941';
        $this->admin->prepare("INSERT INTO portal_onepage.payment_accounts VALUES(?,'sandbox',true)")->execute([$account]);
        $registrar=$this->connect('portal_onepage_psp_registrar');
        $writer=new \App\Infrastructure\Commerce\PostgresOnepageEntryPayments($registrar,'portal_native_orders_ci',$account);
        $schedule=\App\Domain\SitesIntegration\OnepageEntryPaymentTerms::schedule($a);
        self::assertNotNull($schedule);
        $today=$this->admin->query("SELECT (clock_timestamp() AT TIME ZONE 'America/Sao_Paulo')::date")->fetchColumn();
        self::assertGreaterThan($today,$schedule[1]['due_date']);self::assertGreaterThan($today,$schedule[2]['due_date']);
        self::assertSame('recorded',$writer->registerCustomer($a->id,$customer));
        self::assertSame('recorded',$writer->registerEntry($a->id,'pay_LABBriefing941',$customer,
            \App\Domain\SitesIntegration\OnepageEntryPaymentTerms::reference($a,$account,$customer),33000,$schedule[0]['due_date'],'CREDIT_CARD'));
        $q=$runtime->prepare('SELECT portal_onepage.read_entry_payment(?,?::uuid,?)');
        $q->execute([hash('sha256',$session),$a->id,$account]);
        $binding=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        [$anchor,$sql]=\NativeBriefingFixture\Fixture::render($binding,$schedule[1]['due_date']);
        // Bootstrap fixes the anchor before producer is connected; producer supplies no source facts.
        $this->admin->exec($sql);
        $producer=$this->connect('native_briefing_producer');
        Trace::pass('PASS BRIEFING_FIXTURES_READY');
        Trace::enter('CASES');
        $fingerprint="SELECT current_database(),host(inet_server_addr()),inet_server_port(),pg_postmaster_start_time()::text";
        self::assertSame($this->admin->query($fingerprint)->fetch(PDO::FETCH_NUM),$producer->query($fingerprint)->fetch(PDO::FETCH_NUM));
        self::assertSame('native_briefing_producer',$producer->query('SELECT session_user')->fetchColumn());
        $flags=$this->admin->query("SELECT rolcanlogin,rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls,rolinherit FROM pg_roles WHERE rolname='native_briefing_producer'")->fetch(PDO::FETCH_NUM);
        self::assertSame([true,false,false,false,false,false,false],$flags);
        self::assertSame([false,false,false,false,false,false,false],$this->admin->query("SELECT rolcanlogin,rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls,rolinherit FROM pg_roles WHERE rolname='native_briefing_owner'")->fetch(PDO::FETCH_NUM));
        self::assertSame(0,(int)$this->admin->query("SELECT count(*) FROM pg_auth_members WHERE member=(SELECT oid FROM pg_roles WHERE rolname='native_briefing_producer')")->fetchColumn());
        self::assertSame(1,(int)$this->admin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='native_briefing_fixture' AND c.relname='evidence' AND c.relrowsecurity AND c.relforcerowsecurity")->fetchColumn());
        self::assertSame(1,(int)$producer->query("SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='native_briefing_fixture' AND has_function_privilege(current_user,p.oid,'EXECUTE')")->fetchColumn());
        self::assertTrue($producer->query("SELECT has_function_privilege(current_user,'native_briefing_fixture.admit(uuid,text,text)','EXECUTE')")->fetchColumn());
        foreach ([$producer,$runtime] as $db) {
            foreach (['SELECT','INSERT','UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER'] as $privilege) {
                $q=$db->prepare("SELECT has_table_privilege(current_user,'native_briefing_fixture.evidence',?)");$q->execute([$privilege]);self::assertFalse($q->fetchColumn());
            }
            $this->denySql($db,'SELECT * FROM native_briefing_fixture.evidence');
            $this->denySql($db,'INSERT INTO native_briefing_fixture.evidence VALUES(true,?::jsonb)',[json_encode($anchor)]);
            $this->denySql($db,'SET ROLE native_briefing_owner');
        }
        $this->denySql($producer,'SELECT native_briefing_fixture.next_step(?,?::uuid)',[hash('sha256',$session),$a->id]);
        $this->denySql($producer,'SELECT portal_onepage.register_entry_payment(?::uuid,?,?,?,?,?,?::date,?)',[$a->id,$account,'pay_LABBriefing941',$customer,'invalid',33000,$schedule[0]['due_date'],'CREDIT_CARD']);
        $append='SELECT native_briefing_fixture.admit(?::uuid,?,?)';$args=[$a->id,$anchor['event'],$anchor['digest']];
        $this->denySql($runtime,$append,$args);
        $admit=static function(PDO $db,array $args) use($append):string {$q=$db->prepare($append);$q->execute($args);return $q->fetchColumn();};
        foreach ([[$b->id,$anchor['event'],$anchor['digest']],[$a->id,null,$anchor['digest']],[$a->id,'other',$anchor['digest']],[$a->id,$anchor['event'],null],[$a->id,$anchor['event'],'malformed'],[null,$anchor['event'],$anchor['digest']]] as $bad) self::assertSame('denied',$admit($producer,$bad));
        $read=fn(string $s,string $id)=>\NativeBriefingFixture\Fixture::next($this->connect('portal_onepage_runtime'),$s,$id);
        self::assertNull($read($session,$a->id));
        $producer->beginTransaction();self::assertSame('recorded',$admit($producer,$args));
        self::assertNull($read($session,$a->id));$producer->rollBack();self::assertNull($read($session,$a->id));
        self::assertSame(0,(int)$this->connect('portal_native_test_bootstrap')->query('SELECT count(*) FROM native_briefing_fixture.evidence')->fetchColumn());
        $producer->beginTransaction();self::assertSame('recorded',$admit($producer,$args));$producer->commit();
        self::assertSame(['next_step'=>'briefing'],$read($session,$a->id));
        self::assertSame('replay',$admit($this->connect('native_briefing_producer'),$args));
        $rows=$this->connect('portal_native_test_bootstrap')->query('SELECT payload FROM native_briefing_fixture.evidence')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1,$rows);self::assertEquals($anchor,json_decode($rows[0],true,512,JSON_THROW_ON_ERROR));
        self::assertNull($read($session,$b->id));
        foreach ([$same,$other,$noMfa] as $notOwner) self::assertNull($read($notOwner,$a->id));
        $runtime->beginTransaction();self::assertNull(\NativeBriefingFixture\Fixture::next($runtime,$session,$a->id));$runtime->rollBack();
        $runtime->exec("SET default_transaction_isolation='repeatable read'");
        self::assertNull(\NativeBriefingFixture\Fixture::next($runtime,$session,$a->id));$runtime->exec("SET default_transaction_isolation='read committed'");
        self::assertSame('recorded',$writer->revokeEntry($a->id,'LAB941-evidence-revoked'));
        self::assertNull($read($session,$a->id));
        self::assertSame('replay',$admit($producer,$args));self::assertNull($read($session,$a->id));
        self::assertSame(['pending_payment','pending_payment'],$this->admin->query('SELECT status FROM portal_onepage.pending_orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1,(int)$this->admin->query('SELECT count(*) FROM portal_onepage.entry_payment_bindings')->fetchColumn());
        Trace::pass('PASS BRIEFING_COMMIT_OWNER_REPLAY_REVOCATION');
        Trace::enter('TEST_TEARDOWN');
    }
}
