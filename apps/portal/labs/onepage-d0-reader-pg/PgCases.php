<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\Commerce\D0\{EntryStore,FirstBriefingRelease};
use App\Domain\SitesIntegration\{OnepageEntryPaymentTerms,PortalSettlementEvidence,D0CurlFixture as Wire};
use App\Infrastructure\Commerce\{PostgresD0EntryStore,PostgresOnepageOrders,PostgresOnepageEntryPayments,ConfiguredSandboxD0Reader as Reader};
use PDO;use Closure;use PHPUnit\Framework\Assert as A;
final class ConfiguredReaderPgCases
{
 private static function q(PDO $db,string $sql,array $args=[]):mixed{$q=$db->prepare($sql);$q->execute($args);return $q->fetchColumn();}
 public static function run(PDO $admin,PDO $runtime,Closure $connect,PostgresOnepageOrders $orders,OnepageAcceptedOrder $a,string $session,array $wrongSessions,Closure $revoke,Closure $login,Closure $financeGate):void{
  // Called only by ConfiguredReaderPgTest after the original Laravel/admin/topology gates.
  require_once __DIR__.'/../onepage-d0-configured-reader/CurlFixture.php';
  A::assertTrue(extension_loaded('curl'));A::assertFalse(function_exists('curl_exec'));
  foreach(['portal_onepage_entry_payments.sql','portal_onepage_d0_candidate.sql','portal_onepage_d0_account_epoch.sql'] as $sql)$admin->exec(file_get_contents(dirname(__DIR__,2).'/database/security/'.$sql));
  A::assertNull(self::q($admin,'SELECT portal_onepage.d0_account_gate()'));
  // Exactly the existing synthetic #958 fixture setup. No new role/grant/policy.
  $admin->exec("CREATE OR REPLACE FUNCTION portal_onepage.d0_account_gate() RETURNS text LANGUAGE sql IMMUTABLE AS \$\$ SELECT 'native-entry-sandbox'::text \$\$");
  foreach(['portal_onepage_psp_registrar','portal_onepage_d0_verifier'] as $role)$admin->exec('ALTER ROLE '.$role.' LOGIN');
  $registrar=$connect('portal_onepage_psp_registrar');$writer=$connect('portal_onepage_d0_verifier');$operator=$connect('portal_native_test_bootstrap');
  $id=self::q($admin,'SELECT extract(epoch FROM pg_postmaster_start_time())');A::assertSame($id,self::q($operator,'SELECT extract(epoch FROM pg_postmaster_start_time())'));
  A::assertSame('portal_native_test_bootstrap',self::q($operator,'SELECT session_user'));
  A::assertNotSame(self::q($admin,'SELECT pg_backend_pid()'),self::q($operator,'SELECT pg_backend_pid()'));
  $operator->exec("SET statement_timeout='3s'; SET lock_timeout='2s'");
  $admin->exec("INSERT INTO portal_onepage.payment_accounts(account_ref,environment,active,configuration_ref) VALUES('native-entry-sandbox','sandbox',true,'cfg_reader_00001')");
  $reg=new PostgresOnepageEntryPayments($registrar,'portal_native_orders_ci','native-entry-sandbox');
  A::assertSame('recorded',$reg->registerCustomer($a->id,'cus_ConfigFixture1'));
  A::assertSame('recorded',$reg->registerEntry($a->id,'pay_ConfigFixture1','cus_ConfigFixture1',OnepageEntryPaymentTerms::reference($a,'native-entry-sandbox','cus_ConfigFixture1'),33000,OnepageEntryPaymentTerms::schedule($a)[0]['due_date'],'PIX'));
  $store=new PostgresD0EntryStore($runtime,$writer,'portal_native_orders_ci','native-entry-sandbox');
  $snapshot=$store->inspect($session,$a->id);A::assertSame('ready',$snapshot['state']);
  require_once __DIR__.'/BuyerSignalCorrelation.php';
  Wire::reset();
  $signal=['payment_id'=>'pay_ConfigFixture1','external_reference'=>OnepageEntryPaymentTerms::reference($a,'native-entry-sandbox','cus_ConfigFixture1')];
  $correlate=static fn(string $s,array $v):bool=>\PrivateLab\BuyerSignalCorrelation::matches($store,$s,$a->id,'native-entry-sandbox','sandbox',$v);
  A::assertSame($snapshot['binding']['payment_id'],$signal['payment_id']);
  A::assertSame($snapshot['binding']['external_reference'],$signal['external_reference']);
  A::assertTrue($correlate($session,$signal));
  A::assertTrue($correlate($session,$signal)); // duplicate is diagnostic only
  foreach($wrongSessions as $bad)A::assertFalse($correlate($bad,$signal));
  foreach(['payment_id','external_reference'] as $field)A::assertFalse($correlate($session,array_replace($signal,[$field=>'mismatch'])));
  A::assertFalse(\PrivateLab\BuyerSignalCorrelation::matches($store,$session,$a->id,'other-account','sandbox',$signal));
  A::assertFalse(\PrivateLab\BuyerSignalCorrelation::matches($store,$session,$a->id,'native-entry-sandbox','production',$signal));
  A::assertSame($snapshot,$store->inspect($session,$a->id));
  A::assertSame(0,Wire::$calls);
  A::assertSame(0,(int)self::q($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations'));
  require_once __DIR__.'/../onepage-finance-integrated/Harness.php';
  $session=\PrivateD0IntegratedLab\Harness::run($admin,$connect,$a,$session,$store,$revoke,$login,$financeGate);
  Wire::reset();Wire::$response=['id'=>$snapshot['binding']['payment_id'],'customer'=>$snapshot['binding']['customer_id'],'externalReference'=>$snapshot['binding']['external_reference'],'billingType'=>$snapshot['binding']['billing_type'],'status'=>'RECEIVED','value'=>'330.00'];
  $configuration=static function()use($store,$session,$a){$s=$store->inspect($session,$a->id);return ['account'=>$s['account'],'environment'=>$s['environment'],'configuration'=>$s['account_authorization']['configuration'],'generation'=>$s['account_authorization']['generation']];};
  $leaseCalls=0;$lease=static function(array $c)use(&$leaseCalls){$leaseCalls++;return $c+['token'=>'synthetic-fixture-token-not-credential'];};
  $cfg=$configuration();$reader=Reader::forAttempt($store,$session,$a->id,$cfg,$lease);
  A::assertNull($reader->fetchPayment('pay_ConfigFixture1'));A::assertSame(0,$leaseCalls);A::assertSame(0,Wire::$calls);
  foreach($wrongSessions as $bad){$reader=Reader::forAttempt($store,$bad,$a->id,$cfg,$lease,true);A::assertNull($reader->fetchPayment('pay_ConfigFixture1'));}
  A::assertSame(0,$leaseCalls);A::assertSame(0,Wire::$calls);
  $mutationDone=false;$rotate=static function()use($operator,$runtime,$writer,&$mutationDone){
   A::assertFalse($runtime->inTransaction());A::assertFalse($writer->inTransaction());
   $operator->exec("UPDATE portal_onepage.payment_accounts SET active=false WHERE account_ref='native-entry-sandbox'");
   $operator->exec("UPDATE portal_onepage.payment_accounts SET active=true WHERE account_ref='native-entry-sandbox'");$mutationDone=true;
  };
  // Genuine generation changes in a separate SQL connection while canonical client is inside the fake GET.
  Wire::$during=$rotate;$reader=Reader::forAttempt($store,$session,$a->id,$cfg,$lease,true);
  A::assertSame('unavailable',(new FirstBriefingRelease($store,$reader))->attempt($session,$a->id));
  A::assertTrue($mutationDone);A::assertSame(1,Wire::$calls);A::assertSame(0,(int)self::q($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations'));
  Wire::$during=null;
  // Real EntryStore remains the writer. Decorator only schedules a committed change after verification, before admit.
  $boundary=new class($store,$rotate) implements EntryStore {
   public function __construct(private EntryStore $inner,private Closure $before){}
   public function inspect(string $session,string $order):?array{return $this->inner->inspect($session,$order);}
   public function admit(string $session,string $order,array $expected,PortalSettlementEvidence $p,string $at):string{($this->before)();return $this->inner->admit($session,$order,$expected,$p,$at);}
  };
  $mutationDone=false;$reader=Reader::forAttempt($boundary,$session,$a->id,$configuration(),$lease,true);
  A::assertSame('unavailable',(new FirstBriefingRelease($boundary,$reader))->attempt($session,$a->id));A::assertTrue($mutationDone);
  A::assertSame(2,Wire::$calls);A::assertSame(0,(int)self::q($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations'));
  // Fresh explicit attempt with current generation commits exactly one row through real PostgresD0EntryStore.
  $reader=Reader::forAttempt($store,$session,$a->id,$configuration(),$lease,true);$service=new FirstBriefingRelease($store,$reader);
  A::assertSame('briefing_available',$service->attempt($session,$a->id));A::assertSame(3,Wire::$calls);
  $saved=self::q($admin,'SELECT row_to_json(c)::text FROM portal_onepage.entry_confirmations c WHERE order_id=?::uuid',[$a->id]);A::assertIsString($saved);
  A::assertSame(1,(int)self::q($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations'));
  $readback=$connect('portal_onepage_runtime');A::assertNotSame(self::q($runtime,'SELECT pg_backend_pid()'),self::q($readback,'SELECT pg_backend_pid()'));
  $fresh=new PostgresD0EntryStore($readback,$connect('portal_onepage_d0_verifier'),'portal_native_orders_ci','native-entry-sandbox');A::assertSame('confirmed',$fresh->inspect($session,$a->id)['state']);
  $calls=$leaseCalls;$rotate();
  $replay=new FirstBriefingRelease($fresh,Reader::forAttempt($fresh,$session,$a->id,$cfg,$lease,true));
  A::assertSame('briefing_available',$replay->attempt($session,$a->id));A::assertSame('briefing_available',$replay->state($session,$a->id));
  A::assertSame($calls,$leaseCalls);A::assertSame(3,Wire::$calls);
  A::assertSame($saved,self::q($admin,'SELECT row_to_json(c)::text FROM portal_onepage.entry_confirmations c WHERE order_id=?::uuid',[$a->id]));
 }
}
