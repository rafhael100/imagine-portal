<?php
declare(strict_types=1);
namespace Tests\Feature;
require_once __DIR__.'/../../labs/onepage-pending-order/Adapter.php';
require_once __DIR__.'/../../labs/onepage-pending-order/ProofChecks.php';
use App\Labs\OnepagePendingOrder\ProofChecks;
use App\Labs\OnepagePendingOrder\Adapter;
use Illuminate\Http\Request;
use PDO;
use PDOStatement;
use Tests\TestCase;

/** Spies prove adapter control flow only; never persistence or native SQL authorization. */
final class OnepagePendingOrderOfflineTest extends TestCase {
    protected function setUp():void {parent::setUp();putenv('APP_ENV=testing');putenv('ONEPAGE_PENDING_ORDER_LAB=SYNTHETIC_ONLY');}
    protected function tearDown():void {putenv('ONEPAGE_PENDING_ORDER_LAB');parent::tearDown();}
    private function request(?string $phase='business'):Request {
        $r=Request::create('/not-registered','POST');$s=$this->app['session']->driver();$s->flush();
        if($phase!==null)$s->put('portal.browser_auth',['phase'=>$phase,'reference'=>str_repeat('a',64)]);
        $r->setLaravelSession($s);return $r;
    }
    private function acceptance(array $r):array {return ['accepted'=>true]+array_intersect_key($r,array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));}
    public function test_explicit_exact_acceptance_and_no_identity_input():void {
        $db=new PendingOrderPdo;$a=new Adapter($db);$r=$this->request();$r->request->add(['company'=>'forged','buyer'=>'other']);
        self::assertSame('pending_payment',$a->accept($r,'key-a',$db->review['quote_id'],$this->acceptance($db->review))['status']);
        self::assertSame(1,$db->writes);self::assertSame(hash('sha256',str_repeat('a',64)),$db->args[0]);
        self::assertStringNotContainsString('forged',json_encode($db->args));
    }
    public function test_all_acceptance_mismatches_stop_before_writer():void {
        $db=new PendingOrderPdo;$a=new Adapter($db);$good=$this->acceptance($db->review);
        $cases=[[],array_replace($good,['accepted'=>false]),array_replace($good,['accepted'=>'true']),$good+['company'=>'other']];
        foreach(['quote_id','offer_digest','terms_digest','contract_ref','contract_digest'] as $key)$cases[]=array_replace($good,[$key=>'different']);
        $cases[]=array_replace($good,['contract_revision'=>2]);$cases[]=array_replace($good,['contract_revision'=>'1']);
        foreach($cases as $case)self::assertSame(['status'=>'invalid_acceptance'],$a->accept($this->request(),'key-a',$db->review['quote_id'],$case));
        self::assertSame(0,$db->writes);
    }
    public function test_session_absent_or_pending_mfa_never_reaches_sql():void {
        $db=new PendingOrderPdo;$a=new Adapter($db);
        foreach([null,'pending_mfa'] as $phase)self::assertSame(['status'=>'denied'],$a->accept($this->request($phase),'key-a',$db->review['quote_id'],[]));
        self::assertSame(0,$db->profiles);self::assertSame(0,$db->writes);
    }
    public function test_native_review_denial_never_reaches_writer():void {
        $db=new PendingOrderPdo;$id=$db->review['quote_id'];$db->review=null;
        self::assertSame(['status'=>'denied'],(new Adapter($db))->accept($this->request(),'key-a',$id,[]));
        self::assertSame(0,$db->writes);
    }
    public function test_every_replay_rechecks_and_returns_sql_conflict_or_denial():void {
        $db=new PendingOrderPdo;$a=new Adapter($db);$id=$db->review['quote_id'];$yes=$this->acceptance($db->review);
        self::assertSame('pending_payment',$a->accept($this->request(),'key-a',$id,$yes)['status']);
        $db->result=['status'=>'conflict'];self::assertSame(['status'=>'conflict'],$a->accept($this->request(),'key-a',$id,$yes));
        $db->review=null;self::assertSame(['status'=>'denied'],$a->accept($this->request(),'key-a',$id,$yes));
        self::assertSame(3,$db->profiles);self::assertSame(2,$db->writes);
    }
    public function test_immutability_proof_rejects_unrelated_errors_and_changed_snapshots():void {
        $info=['P0001',7,"ERROR:  LAB_ORDER_AND_TERMS_IMMUTABLE\nCONTEXT: synthetic trigger"];
        self::assertTrue(ProofChecks::immutableRefusal($info,'same','same'));
        foreach([[],['42501',7,'permission denied'],['P0001',7,'different failure'],['P0001',7,'LAB_ORDER_AND_TERMS_IMMUTABLE_EXTRA']] as $bad)
            self::assertFalse(ProofChecks::immutableRefusal($bad,'same','same'));
        self::assertFalse(ProofChecks::immutableRefusal($info,'before','after'));
    }
    public function test_revocation_requires_actual_active_to_revoked_transition():void {
        $active=['status'=>'active','revoked_at'=>null];$revoked=['status'=>'revoked','revoked_at'=>'2026-11-17 00:00:00+00'];
        self::assertTrue(ProofChecks::revokedTransition($active,$revoked));
        self::assertFalse(ProofChecks::revokedTransition($active,$active));
        self::assertFalse(ProofChecks::revokedTransition($revoked,$revoked));
        self::assertFalse(ProofChecks::revokedTransition($active,['status'=>'revoked','revoked_at'=>null]));
    }
    public function test_admin_runtime_refused():void {
        $db=new PendingOrderPdo;$db->actor='onepage_lab_bootstrap';
        self::assertSame(['status'=>'unavailable'],(new Adapter($db))->review($this->request(),$db->review['quote_id']));self::assertSame(0,$db->writes);
    }
}
final class PendingOrderPdo extends PDO {
    public int $profiles=0;public int $writes=0;public array $args=[];public string $actor='onepage_buyer_runtime';
    public ?array $review=['quote_id'=>'11111111-1111-4111-8111-111111111111','company'=>'lab-a','buyer'=>'lab-buyer-a','offer_digest'=>'offer','terms_digest'=>'terms','contract_ref'=>'lab-contract','contract_revision'=>1,'contract_digest'=>'contract'];
    public array $result=['status'=>'pending_payment','id'=>'lab-order'];
    public function __construct(){}
    public function getAttribute(int $a):mixed{return 'pgsql';}public function inTransaction():bool{return false;}
    public function query(string $q,?int $f=null,mixed ...$a):PDOStatement|false {$this->profiles++;return new PendingOrderStatement($this,'profile');}
    public function prepare(string $q,array $options=[]):PDOStatement|false{return new PendingOrderStatement($this,str_contains($q,'accept_order')?'write':'read');}
}
final class PendingOrderStatement extends PDOStatement {
    public function __construct(private PendingOrderPdo $db,private string $mode){}
    public function execute(?array $params=null):bool{if($this->mode==='write'){$this->db->writes++;$this->db->args=$params;}return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return ['db'=>'portal_onepage_buyer_ci','actor'=>$this->db->actor,'login'=>$this->db->actor,'host'=>'127.0.0.1','port'=>55439,'unsafe'=>false];}
    public function fetchColumn(int $column=0):mixed{return json_encode($this->mode==='write'?$this->db->result:$this->db->review);}
}
