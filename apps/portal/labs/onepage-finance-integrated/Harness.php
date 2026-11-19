<?php
declare(strict_types=1);
namespace PrivateD0IntegratedLab;
use PDO;use Closure;use RuntimeException;use Throwable;
use PHPUnit\Framework\Assert as A;
use PrivateD0Lab\{Reconcile,SyntheticPaymentReader};
use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\Commerce\D0\FirstBriefingRelease;
use App\Infrastructure\Commerce\PostgresD0EntryStore;
final class Harness {
 private static function q(PDO $db,string $sql,array $args=[]):mixed{$q=$db->prepare($sql);$q->execute($args);return $q->fetchColumn();}
 public static function run(PDO $admin,Closure $connect,OnepageAcceptedOrder $order,string $oldSession,
  PostgresD0EntryStore $entry,Closure $revoke,Closure $login,Closure $bootstrapGate):string {
  // This callback was captured only after unchanged #960 beforeWrites/admin gates.
  // It must succeed again before each additional fixture DDL or privileged write.
  $bootstrapGate();
  foreach(['Financial.php','PdoFinancialPort.php'] as $file)require_once __DIR__.'/'.$file;
  $bootstrapGate();$admin->exec(file_get_contents(__DIR__.'/finance.sql'));
  $bootstrapGate();$admin->exec(file_get_contents(__DIR__.'/lease.sql'));
  $bootstrapGate();$admin->exec('ALTER ROLE onepage_finance_lab_verifier LOGIN');
  $port=(int)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
  $cfg=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>$port,'database'=>'portal_native_orders_ci',
   'username'=>'onepage_finance_lab_verifier','password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable'];
  config(['database.connections.finance_lab'=>$cfg]);app('db')->purge('finance_lab');
  $laravel=app('db')->connection('finance_lab');$financialDb=$laravel->getPdo();
  $runtimeGate=static function(PDO $db)use($bootstrapGate,$laravel,$financialDb,$cfg):bool {
   $bootstrapGate();A::assertSame($financialDb,$db);A::assertSame($financialDb,$laravel->getPdo());
   A::assertSame($cfg,config('database.connections.finance_lab'));
   foreach(['driver','host','port','database','username','password','url'] as $k)A::assertSame($cfg[$k]??null,$laravel->getConfig($k)??null);
   return true;
  };
  $financial=new PdoFinancialPort($financialDb,$runtimeGate,['database'=>'portal_native_orders_ci','port'=>$port]);
  $observer=$connect('portal_native_test_bootstrap');
  A::assertSame(self::q($admin,'SELECT system_identifier::text FROM pg_control_system()'),self::q($observer,'SELECT system_identifier::text FROM pg_control_system()'));
  A::assertNotSame(self::q($admin,'SELECT pg_backend_pid()'),self::q($observer,'SELECT pg_backend_pid()'));
  $before=self::q($observer,'SELECT coalesce(jsonb_agg(to_jsonb(c)),\'[]\'::jsonb)::text FROM portal_onepage.entry_confirmations c');
  A::assertSame('[]',$before);
  $snapshot=$financial->inspect_binding('native-entry-sandbox','sandbox','pay_ConfigFixture1');A::assertNotNull($snapshot);
  A::assertSame($order->id,$snapshot->binding->orderRef);A::assertSame($order->companyReference,$snapshot->binding->tenantRef);
  A::assertNull($financial->inspect_binding('other-account','sandbox','pay_ConfigFixture1'));
  A::assertNull($financial->inspect_binding('native-entry-sandbox','sandbox','pay_UnboundFixture'));
  A::assertNull($financial->inspect_binding('native-entry-sandbox','production','pay_ConfigFixture1'));
  $raw=self::q($financialDb,'SELECT onepage_finance_lab.inspect_binding(?,?,?)::text',['native-entry-sandbox','sandbox','pay_ConfigFixture1']);
  $parsed=json_decode($raw,true,32,JSON_THROW_ON_ERROR);A::assertArrayHasKey('due_date',$parsed['binding']);
  $receipt=($parsed['binding']);unset($receipt['due_date']);$receipt+=['currency'=>'BRL','status'=>'RECEIVED'];
  A::assertSame('denied',self::q($financialDb,'SELECT onepage_finance_lab.record_observation(?::uuid,?,?,?::uuid,?::jsonb,?::jsonb,?,clock_timestamp())',
   ['22222222-2222-4222-8222-222222222222','native-entry-sandbox','sandbox','33333333-3333-4333-8333-333333333333',$raw,json_encode($receipt,JSON_THROW_ON_ERROR),str_repeat('a',64)]));
  A::assertTrue($revoke($oldSession));A::assertNull($entry->inspect($oldSession,$order->id));
  $reader=new class($snapshot) implements SyntheticPaymentReader {
   public array $response;public int $calls=0;public ?Closure $during=null;
   public function __construct($s){$b=$s->binding;$this->response=['id'=>$b->paymentId,'customer'=>$b->customerId,'externalReference'=>$b->externalReference,'billingType'=>$b->billingType,'status'=>'RECEIVED','value'=>'330.00'];}
   public function fetchPayment(string $id):?array{$this->calls++;if($this->during)($this->during)();return $this->response;}
  };
  $service=new Reconcile($financial,$reader,'native-entry-sandbox','sandbox');
  $bootstrapGate();$admin->exec("INSERT INTO onepage_finance_lab.fixture_lease VALUES('evt_finance_fixture','native-entry-sandbox','pay_ConfigFixture1','pending',NULL,NULL)");
  $ackDb=$connect('onepage_finance_lab_ack');A::assertSame('onepage_finance_lab_ack',self::q($ackDb,'SELECT session_user'));
  $claim=static function()use($ackDb):array {
   A::assertFalse($ackDb->inTransaction());$ackDb->beginTransaction();
   try{$q=$ackDb->query("UPDATE onepage_finance_lab.fixture_lease SET state='claimed',token=gen_random_uuid(),expires=clock_timestamp()+interval '30 seconds' WHERE event_id='evt_finance_fixture' AND (state='pending' OR (state='claimed' AND expires<clock_timestamp())) RETURNING event_id,payment_id,token::text AS lease_token");
    $v=$q->fetch(PDO::FETCH_ASSOC);A::assertIsArray($v);A::assertTrue($ackDb->commit());return $v+['event_type'=>'PAYMENT_RECEIVED'];
   }catch(Throwable $e){if($ackDb->inTransaction())$ackDb->rollBack();throw $e;}
  };
  $backend=static function(array $l,string $state)use($ackDb,$observer):bool {
   // Independent connection sees posting only if financial COMMIT already occurred.
   A::assertSame(1,(int)self::q($observer,'SELECT count(*) FROM onepage_finance_lab.observations'));
   $ackDb->beginTransaction();
   try{$q=$ackDb->prepare("UPDATE onepage_finance_lab.fixture_lease SET state='acked' WHERE event_id=? AND payment_id=? AND token=?::uuid AND state='claimed' AND expires>clock_timestamp()");$q->execute([$l['event_id'],$l['payment_id'],$l['lease_token']]);
    if($q->rowCount()!==1){$ackDb->rollBack();return false;}
    A::assertTrue($ackDb->commit());return true;
   }catch(Throwable $e){if($ackDb->inTransaction())$ackDb->rollBack();throw $e;}
  };
  $lease=$claim();$ack=RequireConfirmedAck::wrap($backend);
  // Epoch during independent synthetic GET rejects before any posting or ACK.
  $reader->during=static function()use($admin,$bootstrapGate){$bootstrapGate();$admin->exec("UPDATE portal_onepage.payment_accounts SET active=false WHERE account_ref='native-entry-sandbox'");$admin->exec("UPDATE portal_onepage.payment_accounts SET active=true WHERE account_ref='native-entry-sandbox'");};
  $request='11111111-1111-4111-8111-111111111111';
  A::assertSame('retry_wait',$service->run($request,$lease,$ack));A::assertSame(0,(int)self::q($observer,'SELECT count(*) FROM onepage_finance_lab.observations'));
  $reader->during=null;
  // Simulate lost ACK response before ACK write; financial append is real.
  A::assertSame('retry_wait',$service->run($request,$lease,static function(){throw new RuntimeException('SYNTHETIC_LOST_ACK');}));
  A::assertSame(1,(int)self::q($observer,'SELECT count(*) FROM onepage_finance_lab.observations'));
  $first=self::q($observer,'SELECT observed_at::text FROM onepage_finance_lab.observations');
  $bootstrapGate();$admin->exec("UPDATE onepage_finance_lab.fixture_lease SET expires=clock_timestamp()-interval '1 second' WHERE event_id='evt_finance_fixture'");
  A::assertSame('retry_wait',$service->run($request,$lease,$ack)); // false ACK cannot become positive
  $newLease=$claim();usleep(2000);
  A::assertSame('replay',$service->run($request,$newLease,$ack));
  A::assertSame('acked',self::q($observer,'SELECT state FROM onepage_finance_lab.fixture_lease'));
  A::assertSame($first,self::q($observer,'SELECT observed_at::text FROM onepage_finance_lab.observations'));
  A::assertSame(1,(int)self::q($observer,'SELECT count(*) FROM onepage_finance_lab.requests'));
  $reader->response['status']='REFUNDED';$ackCalled=false;
  A::assertSame('retry_wait',$service->run($request,$newLease,static function()use(&$ackCalled){$ackCalled=true;}));A::assertFalse($ackCalled);
  foreach(['SELECT * FROM portal_onepage.entry_payment_bindings','SELECT * FROM onepage_finance_lab.observations','DELETE FROM onepage_finance_lab.requests'] as $sql){
   try{$financialDb->query($sql);A::fail('ROW_GRANT_REFUSAL_REQUIRED');}catch(\PDOException $e){A::assertSame('42501',$e->getCode());}
  }
  A::assertSame($before,self::q($observer,'SELECT coalesce(jsonb_agg(to_jsonb(c)),\'[]\'::jsonb)::text FROM portal_onepage.entry_confirmations c'));
  A::assertSame('unavailable',(new FirstBriefingRelease($entry))->attempt($oldSession,$order->id));
  // Real password + new TOTP via original authentication, no session reuse.
  $newSession=$login(static function(string $pending)use($entry,$order){A::assertSame('unavailable',(new FirstBriefingRelease($entry))->attempt($pending,$order->id));});
  A::assertNotSame($oldSession,$newSession);A::assertSame('ready',$entry->inspect($newSession,$order->id)['state']);
  A::assertSame(0,(int)self::q($observer,'SELECT count(*) FROM portal_onepage.entry_confirmations'));
  // Original #960 PgCases resumes: only its explicit native attempt may confirm.
  return $newSession;
 }
}
