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


/** Product integration against a fresh, explicitly requested local PG18 cluster. */
class ServiceReceiptPgTest extends TestCase
{
    private readonly PDO $admin;
    private \PrivateServiceReceipt\ServiceGate $serviceGate;
    private \Closure $financeGate;
    private \Closure $inboxCases;
    private int $port;
    private string $proof;
    private string $key;
    private string $pepper;

    private function connect(string $role):PDO
    {
        return $this->serviceGate->connect($role,'portal_native_orders_ci');
    }
    private function prepareDatabase():void
    {
        require_once __DIR__.'/ServiceGate.php';
        require_once __DIR__.'/ServiceHarness.php';
        $this->serviceGate=\PrivateServiceReceipt\ServiceGate::boot(
          getenv('PORTAL_TEST_PG_WITNESS'),getenv('PORTAL_TEST_PG_WITNESS_SHA'));
        $this->port=5432;$this->admin=$this->serviceGate->native();
        fwrite(STDOUT,"PASS SERVICE_LARAVEL_SQL_BEFORE_DDL\n");
        $this->financeGate=fn()=> $this->serviceGate->beforeDdl($this->admin);
        $this->inboxCases=function(\Closure $prepare,\Closure $cases):void{
          $fixture=$prepare($this->admin,$this->financeGate);
          $inbox=\PrivateServiceReceipt\ServiceHarness::prepareInbox($this->serviceGate);
          $cases($this->admin,$inbox,$this->financeGate,$fixture,[]);
        };
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
            $this->serviceGate->beforeDdl($this->admin);
            self::assertSame(0,Artisan::call('migrate',['--path'=>'database/migrations/'.$migration,'--force'=>true,'--no-interaction'=>true]));
        }
        
        foreach (['portal_browser_auth.sql','portal_browser_principal_candidate.sql','portal_onepage_orders.sql'] as $file) {
            $this->serviceGate->beforeDdl($this->admin);
            $this->admin->exec(file_get_contents(database_path('security/'.$file)));
        }
        // These logins exist only inside this empty, independently identified disposable cluster.
        foreach (['portal_browser_runtime','portal_onepage_runtime','portal_onepage_publisher'] as $role) {
            $this->serviceGate->beforeDdl($this->admin);
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

    public function test_service_receipt_finance_only():void
    {
        
        $this->prepareDatabase();
        
        $company=$this->admin->query('SELECT gen_random_uuid()')->fetchColumn();
        $foreign=$this->admin->query('SELECT gen_random_uuid()')->fetchColumn();
        $q=$this->admin->prepare("INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision) VALUES(?,?,'Native order integration fixture','active',1)");
        $q->execute([$company,'native-order-company']);$q->execute([$foreign,'native-other-company']);
        [$buyer,$session,$pending]=$this->authenticate('onepage-own',$company);
        [,$same]=$this->authenticate('onepage-same-company',$company);
        [,$other]=$this->authenticate('onepage-other-company',$foreign);
        [,$manager]=$this->authenticate('onepage-manager',$company,'manager');
        $runtime=$this->connect('portal_onepage_runtime');$store=new PostgresOnepageOrders($runtime,'portal_native_orders_ci');
        $selection=['site'=>'onepage','channels'=>[],'tier'=>'minimum'];
        self::assertSame('denied',$store->createDraft($pending,'onepage-key',$selection)['status']);
        self::assertSame('denied',$store->createDraft($manager,'onepage-key',$selection)['status']);
        $draft=$store->createDraft($session,'onepage-key',$selection);
        self::assertSame('draft',$draft['status']);self::assertSame(99000,$draft['quote']['site_setup_cents']);
        self::assertSame($draft,$store->createDraft($session,'onepage-key',$selection));
        self::assertSame('denied',$store->createDraft($same,'onepage-key',$selection)['status']);
        foreach ([$same,$other,$pending] as $notOwner) self::assertSame('denied',$store->resumeDraft($notOwner,$draft['id'])['status']);
        self::assertSame(1,(int)$this->admin->query('SELECT count(*) FROM portal_onepage.drafts')->fetchColumn());
        self::assertNull($store->review($session,$draft['id']));
        self::assertSame('denied',$store->accept($session,'accept-key',$draft['id'],[])['status']);
        self::assertSame(0,(int)$this->admin->query('SELECT count(*) FROM portal_onepage.pending_orders')->fetchColumn());

        $offer=(new ApprovedOfferCatalog())->quote('onepage');
        $terms=['scope'=>'onepage','payment_policy'=>'onepage-d0-d90-v1','onepage_payment_policy'=>[
            'version'=>'onepage-d0-d90-v1','currency'=>'BRL','setup_total_cents'=>$offer['setup_total_cents'],
            'setup_charges'=>$offer['setup_charges'],'monthly'=>$offer['monthly'],'entry_installment_key'=>'setup-1',
            'briefing_after_entry_confirmation'=>true,'publication_requires_separate_acceptance'=>true,
        ]];
        $publishSql='SELECT portal_onepage.publish_contract(?,?,?,?::jsonb,?::jsonb,?)';
        $args=['publication-onepage-v1','onepage-agreement',1,json_encode($draft['quote'],JSON_THROW_ON_ERROR),json_encode($terms,JSON_THROW_ON_ERROR),'Native order integration fixture; not operational published terms.'];
        $this->denySql($runtime,$publishSql,$args);
        $publisher=$this->connect('portal_onepage_publisher');$publish=$publisher->prepare($publishSql);
        $invalid=$args;$invalid[1]='lab-contract';$publish->execute($invalid);self::assertFalse($publish->fetchColumn());
        $invalid=$args;$invalid[4]=json_encode(['scope'=>'synthetic_only','payment_policy'=>'undecided']);$publish->execute($invalid);self::assertFalse($publish->fetchColumn());
        foreach ([1, true, [], ['version'=>'declared']] as $invalidPolicy) {
            $invalid=$args;$invalid[4]=json_encode(['scope'=>'onepage','payment_policy'=>$invalidPolicy]);
            $publish->execute($invalid);self::assertFalse($publish->fetchColumn());
        }
        $publish->execute($args);self::assertTrue($publish->fetchColumn());
        $publish->execute($args);self::assertTrue($publish->fetchColumn());
        $ambiguous=$args;$ambiguous[0]='publication-onepage-ambiguous';$ambiguous[1]='onepage-agreement-ambiguous';
        $publish->execute($ambiguous);self::assertTrue($publish->fetchColumn());
        self::assertNull($store->review($session,$draft['id']));
        self::assertSame(0,(int)$this->admin->query('SELECT count(*) FROM portal_onepage.order_terms')->fetchColumn());
        $publisher->prepare('SELECT portal_onepage.revoke_contract(?)')->execute(['publication-onepage-ambiguous']);

        foreach (['drafts','order_terms','pending_orders','published_contracts','contract_revocations'] as $table) {
            $this->denySql($runtime,'SELECT * FROM portal_onepage.'.$table);
        }
        $this->denySql($runtime,'SET ROLE portal_onepage_owner');$this->denySql($runtime,'SET ROLE portal_onepage_publisher');
        self::assertSame(0,(int)$this->admin->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='portal_onepage' AND c.relkind='r' AND (NOT relrowsecurity OR NOT relforcerowsecurity)")->fetchColumn());

        $review=$store->review($session,$draft['id']);self::assertIsArray($review);
        self::assertSame('native-order-company',$review['company_reference']);
        self::assertSame('publication-onepage-v1',$review['publication_ref']);
        $yes=['accepted'=>true]+array_intersect_key($review,array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
        foreach ([$yes+['buyer'=>'forged'],array_replace($yes,['contract_revision'=>'1']),array_replace($yes,['offer_digest'=>str_repeat('0',64)])] as $bad) {
            self::assertSame('invalid_acceptance',$store->accept($session,'accept-key',$draft['id'],$bad)['status']);
        }
        self::assertSame(0,(int)$this->admin->query('SELECT count(*) FROM portal_onepage.pending_orders')->fetchColumn());
        $accepted=$store->accept($session,'accept-key',$draft['id'],$yes);self::assertSame('pending_payment',$accepted['status']);
        self::assertFalse($accepted['payment_requested']);self::assertFalse($accepted['site_provisioned']);
        self::assertSame($accepted,$store->accept($session,'accept-key',$draft['id'],$yes));
        self::assertSame('conflict',$store->accept($session,'different-key',$draft['id'],$yes)['status']);
        $reconnected=new PostgresOnepageOrders($this->connect('portal_onepage_runtime'),'portal_native_orders_ci');
        $order=$reconnected->findOwnedAcceptedOrder($session,$accepted['id']);self::assertNotNull($order);
        self::assertSame($company,$order->companyId);self::assertSame($buyer,$order->buyerId);
        self::assertSame('native-order-company',$order->companyReference);self::assertSame($accepted['accepted_at'],$order->acceptedAt);
        self::assertSame($review['offer_digest'],$order->offerDigest);self::assertSame($review['terms_digest'],$order->termsDigest);
        
        $gate=$this->serviceGate;
        require_once __DIR__.'/BuyerContinuation.php';
        require_once __DIR__.'/PurchaseContinuation.php';
        $auth=new PostgresPortalBrowserAuthentication($this->connect('portal_browser_runtime'),'portal_native_orders_ci',$this->proof,$this->key,$this->pepper);
        // A NEW real password+MFA session for the same identity, never reset its replay counter.
        $login=function(\Closure $onPending)use($auth,$company):string{
            $pending=$auth->beginPassword('onepage-own@example.invalid','native-order-fixture-password',$company,'192.0.2.10');
            self::assertIsString($pending);$onPending($pending);
            $counter=(int)$this->admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();
            // Previous MFA may have used this time step. Wait for next valid step, bounded.
            $deadline=microtime(true)+32;
            do{usleep(200000);$next=(int)$this->admin->query('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint')->fetchColumn();}
            while($next<=$counter&&microtime(true)<$deadline);
            self::assertGreaterThan($counter,$next);
            $h=hash_hmac('sha1',pack('N2',intdiv($next,4294967296),$next%4294967296),'12345678901234567890',true);
            $offset=ord($h[19])&15;$code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
            $fresh=$auth->completeMfa($pending,$code,'192.0.2.10');self::assertIsString($fresh);
            self::assertTrue($auth->isActiveBusinessSession($fresh));return $fresh;
        };
        $continue=function(array $ctx,array $ports,\Closure $guard,$financial,$capture)use($gate,$auth,$login,$same,$other,$pending):void{
            \PrivateServiceReceipt\PurchaseContinuation::run($gate,$ctx,$ports,$guard,$financial,$capture,$auth,$login,[$same,$other,$pending]);
        };
        $cases=static fn(array $ctx)=>\PrivateServiceReceipt\ServiceHarness::run($gate,$ctx,$continue);
        self::assertInstanceOf(\Closure::class,$cases);
        require_once __DIR__.'/ServiceBindingFixture.php';
        ($this->inboxCases)(
            function(PDO $admin,\Closure $recheck)use($runtime,$store,$order,$session):array {
                return ServiceBindingFixture::prepare($admin,$runtime,fn(string $role):PDO=>$this->connect($role),$store,$order,$session,$recheck);
            },
            function(PDO $admin,PDO $inbox,\Closure $recheck,array $fixture,array $qualification)use($cases,$runtime,$store,$order,$session):void {
                $cases(['native'=>$admin,'inbox'=>$inbox,'recheck'=>$recheck,'fixture'=>$fixture,'qualification'=>$qualification,
                    'runtime'=>$runtime,'store'=>$store,'order'=>$order,'session'=>$session]);
            }
        );
        
    }
}
