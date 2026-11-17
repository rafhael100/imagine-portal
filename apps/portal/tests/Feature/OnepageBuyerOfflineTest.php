<?php
declare(strict_types=1);
namespace Tests\Feature;
require_once __DIR__.'/../../labs/onepage-buyer/BuyerQuoteAdapter.php';
require_once __DIR__.'/../../labs/onepage-buyer/Guard.php';
use App\Labs\OnepageBuyer\BuyerQuoteAdapter;
use App\Labs\OnepageBuyer\Guard;
use Illuminate\Http\Request;
use PDO;
use PDOStatement;
use RuntimeException;
use Tests\TestCase;

/** PDO spy verifies control flow only; never claims PostgreSQL persistence. */
final class OnepageBuyerOfflineTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); putenv('ONEPAGE_BUYER_PG_LAB=DISPOSABLE_ONLY'); }
    protected function tearDown(): void { putenv('ONEPAGE_BUYER_PG_LAB'); parent::tearDown(); }
    private function request(?array $state): Request {
        $r=Request::create('/lab-not-registered','POST');
        $session=$this->app['session']->driver(); $session->flush();
        if($state!==null)$session->put('portal.browser_auth',$state);
        $r->setLaravelSession($session); return $r;
    }
    private function active(): Request { return $this->request(['phase'=>'business','reference'=>str_repeat('a',64)]); }
    private function selection(): array { return ['site'=>'onepage','channels'=>[],'tier'=>'minimum']; }
    public function test_offline_requirements_fail_closed():void {
        $good=['os'=>'Linux','proc'=>true,'locale'=>'C','locale_env'=>'C','sodium'=>true,'process'=>true,
            'drivers'=>['pgsql'],'password_algorithms'=>['argon2id']];
        Guard::requirements($good);
        foreach (['os'=>'Darwin','proc'=>false,'locale'=>'C.UTF-8','locale_env'=>false,'sodium'=>false,
            'process'=>false,'drivers'=>['sqlite'],'password_algorithms'=>['bcrypt']] as $key=>$value) {
            try { Guard::requirements(array_replace($good,[$key=>$value]));self::fail('Expected refusal'); }
            catch(RuntimeException $e){self::assertSame('OFFLINE_REQUIREMENTS_REQUIRED',$e->getMessage());}
        }
    }
    public function test_fixture_material_is_verified_before_preparation():void {
        $key=str_repeat('k',32);$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';$plain='synthetic-password-only';
        $hash=\App\Domain\Identity\Security\PortalPasswordCredential::makeHash($plain);
        $sealed=\App\Domain\Identity\Security\PortalTotpSecretCipher::seal($secret,$key);
        Guard::fixtures($hash,$sealed,$key,$secret,$plain);
        self::assertIsString($hash);self::assertIsString($sealed);
        foreach ([[null,$sealed,$key,$secret,$plain],[$hash,null,$key,$secret,$plain],
            [$hash,$sealed,'short',$secret,$plain],[$hash,$sealed,str_repeat('z',32),$secret,$plain],
            [$hash,$sealed,$key,$secret,'incorrect-password-only']] as $args) {
            try { Guard::fixtures(...$args);self::fail('Expected refusal'); }
            catch(RuntimeException $e){self::assertSame('FIXTURE_MATERIAL_INVALID',$e->getMessage());}
        }
    }
    public function test_connection_evidence_requires_same_backend_and_synthetic_identity():void {
        // Pure evidence validator, not a claim of a PostgreSQL connection or persistence.
        $role='onepage_buyer_runtime';$nonce='synthetic-attestation';
        $cluster=['db'=>'portal_onepage_buyer_ci','started'=>'1000'];
        $row=['db'=>$cluster['db'],'actor'=>$role,'login'=>$role,'host'=>'127.0.0.1','port'=>55439,
            'started'=>'1000','unsafe'=>false,'pid'=>123,'client_port'=>40001,'backend_start'=>'synthetic-start'];
        $observed=['pid'=>123,'usename'=>$role,'datname'=>$cluster['db'],'client_addr'=>'127.0.0.1',
            'client_port'=>40001,'backend_start'=>'synthetic-start','application_name'=>$nonce];
        Guard::runtimeEvidence($row,$observed,$cluster,$role,$nonce);
        foreach ([['host'=>'127.0.0.2'],['host'=>'127.0.0.1/32'],['port'=>5432],['started'=>'1001'],
            ['db'=>'other'],['login'=>'onepage_lab_bootstrap'],['unsafe'=>true],['pid'=>0]] as $change) {
            try { Guard::runtimeEvidence(array_replace($row,$change),$observed,$cluster,$role,$nonce);self::fail('Expected refusal'); }
            catch(RuntimeException $e){self::assertSame('RUNTIME_CLUSTER_MISMATCH',$e->getMessage());}
        }
        foreach (['pid'=>124,'usename'=>'other','datname'=>'other','client_addr'=>'127.0.0.2',
            'client_port'=>40002,'backend_start'=>'different-start','application_name'=>'different-nonce'] as $key=>$value) {
            try { Guard::runtimeEvidence($row,array_replace($observed,[$key=>$value]),$cluster,$role,$nonce);self::fail('Expected refusal'); }
            catch(RuntimeException $e){self::assertSame('RUNTIME_CLUSTER_MISMATCH',$e->getMessage());}
        }
    }
    public function test_reordered_fields_are_canonicalized_before_server_calculation():void {
        $db=new QuotePdoSpy; $adapter=new BuyerQuoteAdapter($db);
        $adapter->create($this->active(),'key-1',['tier'=>'minimum','site'=>'onepage','channels'=>[]]);
        self::assertSame($this->selection(),json_decode($db->statement->args[4],true));
        self::assertSame(99000,json_decode($db->statement->args[5],true)['setup_activation_total_cents']);
    }
    public function test_maintenance_database_requires_explicit_mode_and_testing_environment():void {
        $config=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>55439,'database'=>'postgres','username'=>'onepage_lab_bootstrap'];
        Guard::configuration('testing','pgsql',$config,'postgres');
        foreach ([['testing','pgsql','portal_onepage_buyer_ci'],['production','pgsql','postgres'],['testing','sqlite','postgres'],['testing','pgsql','other']] as [$env,$connection,$database]) {
            try { Guard::configuration($env,$connection,$config,$database); self::fail('Expected refusal'); }
            catch(RuntimeException $e){self::assertSame('DISPOSABLE_CONFIG_REQUIRED',$e->getMessage());}
        }
    }
    public function test_missing_and_pending_session_deny_before_sql():void {
        $db=new QuotePdoSpy; $adapter=new BuyerQuoteAdapter($db);
        foreach([null,['phase'=>'pending_mfa','reference'=>str_repeat('a',64)]] as $state)
            self::assertSame(['status'=>'denied'],$adapter->create($this->request($state),'key-1',$this->selection()));
        self::assertSame(0,$db->calls);
    }
    public function test_server_envelope_is_only_identity_input_and_server_recomputes_quote():void {
        $db=new QuotePdoSpy; $adapter=new BuyerQuoteAdapter($db); $r=$this->active();
        $r->request->add(['organization'=>'other-company','identity'=>'operator']);
        self::assertSame(['status'=>'denied'],$adapter->create($r,'key-1',$this->selection()));
        self::assertSame(hash('sha256',str_repeat('a',64)),$db->statement->args[0]);
        self::assertSame(hash('sha256','key-1'),$db->statement->args[2]);
        self::assertStringNotContainsString('other-company',json_encode($db->statement->args));
        $quote=json_decode($db->statement->args[5],true);
        self::assertSame(99000,$quote['setup_activation_total_cents']);
        self::assertSame([33000,33000,33000],$quote['illustrative_three_parts_cents']);
        self::assertFalse($quote['real_charge_enabled']);
    }
    public function test_every_operation_rechecks_profile_and_delegates_native_denial():void {
        $db=new QuotePdoSpy; $adapter=new BuyerQuoteAdapter($db); $r=$this->active();
        $adapter->create($r,'key-1',$this->selection());
        self::assertSame(['status'=>'denied'],$adapter->resume($r,'11111111-1111-4111-8111-111111111111'));
        self::assertSame(2,$db->calls);
        self::assertSame('resume',$db->statement->args[1]);
        self::assertNull($db->statement->args[4]);
        self::assertNull($db->statement->args[5]);
    }
    public function test_admin_connection_cannot_be_used_as_runtime():void {
        $db=new QuotePdoSpy; $db->actor='onepage_lab_bootstrap';
        self::assertSame(['status'=>'unavailable'],(new BuyerQuoteAdapter($db))->create($this->active(),'key-1',$this->selection()));
        self::assertNull($db->statement);
    }
    public function test_ads_and_caller_supplied_price_are_not_accepted():void {
        $db=new QuotePdoSpy; $adapter=new BuyerQuoteAdapter($db);
        foreach([array_replace($this->selection(),['channels'=>['google_ads']]),$this->selection()+['price'=>1]] as $selection)
            self::assertSame(['status'=>'invalid_selection'],$adapter->create($this->active(),'key-1',$selection));
        self::assertSame(0,$db->calls);
    }
    public function test_configuration_mismatches_fail_before_a_connection_can_be_opened():void {
        $good=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>55439,'database'=>'portal_onepage_buyer_ci','username'=>'onepage_lab_bootstrap'];
        Guard::configuration('testing','pgsql',$good);
        $cases=[['host'=>'localhost'],['host'=>'192.0.2.1'],['port'=>5432],['database'=>'production'],['username'=>'postgres'],['url'=>'postgresql://example.invalid'],['read'=>['host'=>'127.0.0.1']],['options'=>[1=>true]]];
        foreach($cases as $change){
            try{Guard::configuration('testing','pgsql',array_replace($good,$change));self::fail('Expected refusal');}
            catch(RuntimeException $e){self::assertSame('DISPOSABLE_CONFIG_REQUIRED',$e->getMessage());}
        }
    }
}
final class QuotePdoSpy extends PDO {
    public int $calls=0; public string $actor='onepage_buyer_runtime'; public ?QuoteStatementSpy $statement=null;
    public function __construct(){}
    public function getAttribute(int $attribute):mixed{return 'pgsql';}
    public function inTransaction():bool{return false;}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        $this->calls++;return new QuoteStatementSpy(['db'=>'portal_onepage_buyer_ci','actor'=>$this->actor,'login'=>$this->actor,'host'=>'127.0.0.1','port'=>55439,'unsafe'=>false]);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false{return $this->statement=new QuoteStatementSpy;}
}
final class QuoteStatementSpy extends PDOStatement {
    public array $args=[];
    public function __construct(private array $row=[]){}
    public function execute(?array $params=null):bool{$this->args=$params;return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->row;}
    public function fetchColumn(int $column=0):mixed{return '{"status":"denied"}';}
}
