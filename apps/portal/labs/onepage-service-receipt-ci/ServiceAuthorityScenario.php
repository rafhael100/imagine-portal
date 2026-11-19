<?php
declare(strict_types=1);
namespace PrivateServiceReceipt;
use Closure;
use PDO;
use PHPUnit\Framework\Assert as A;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use PrivateD0Lab\{FinancialPort,SyntheticPaymentReader};
use PrivateD0Consumption\{Consumer,Target};
use PrivateSharedIngress\CaptureHarness;
/** Lab-only dedicated service authority; no operational wiring or human-session write. */
final class ServiceAuthorityScenario
{
 public static function run(ServiceGate $g,array $ctx,array $ports,Closure $guard,FinancialPort $financial,
  CaptureHarness $capture,PostgresPortalBrowserAuthentication $auth,Closure $login,array $negativeSessions):void
 {
  $admin=$ctx['native'];$order=$ctx['order'];$old=$ctx['session'];
  $account='native-entry-sandbox';$payment='pay_ConfigFixture1';
  $inboxAccount='1fcc491f-96d9-4efe-8ec5-b8b09de750dd';$event='evt_buyer_continuation_0001';
  $g->assertSession($admin);
  $n=$admin->prepare('SELECT count(*) FROM onepage_finance_lab.observations WHERE account_ref=? AND order_id=?::uuid AND payment_id=?');
  $n->execute([$account,$order->id,$payment]);A::assertSame(0,(int)$n->fetchColumn());
  // Real session invalidation before BOTH consumer and service confirmation.
  A::assertTrue($auth->revoke($old));A::assertFalse($auth->isActiveBusinessSession($old));
  $g->beforeDdl($admin);$admin->exec(file_get_contents(__DIR__.'/service-authority.sql'));
  require_once __DIR__.'/ServiceAuthorityPort.php';
  $service=$g->connect('onepage_d0_service_b','portal_native_orders_ci');
  $authority=new ServiceAuthorityPort($g,$service);
  $neighbors=$admin->prepare('SELECT row_to_json(x)::text FROM portal_onepage.pending_orders x WHERE id<>?::uuid ORDER BY id');
  $neighbors->execute([$order->id]);$beforeNeighbors=$neighbors->fetchAll(PDO::FETCH_COLUMN);
  A::assertNotEmpty($beforeNeighbors);
  A::assertFalse((bool)$service->query("SELECT has_table_privilege(current_user,'portal_onepage.entry_confirmations','INSERT,UPDATE,DELETE,SELECT')")->fetchColumn());
  // Native preexisting binding is read by the verifier, not supplied by an event.
  A::assertSame(0,(int)$admin->query("SELECT count(*) FROM pg_auth_members WHERE member='onepage_d0_service_b'::regrole OR roleid='onepage_d0_service_b'::regrole")->fetchColumn());
  A::assertFalse((bool)$service->query("SELECT has_schema_privilege(current_user,'portal_onepage','CREATE')")->fetchColumn());
  A::assertFalse((bool)$admin->query("SELECT has_table_privilege('onepage_d0_service_b','onepage_finance_lab.observations'::regclass,'SELECT,INSERT,UPDATE,DELETE')")->fetchColumn());
  A::assertFalse((bool)$admin->query("SELECT has_schema_privilege('onepage_d0_service_b','onepage_finance_lab','USAGE')")->fetchColumn());
  A::assertFalse((bool)$admin->query("SELECT has_function_privilege('onepage_d0_service_b','onepage_finance_lab.service_b_observation_matches(text,text,uuid,text,jsonb,jsonb,text)'::regprocedure,'EXECUTE')")->fetchColumn());
  $exec=$service->query("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='portal_onepage' AND has_function_privilege(current_user,p.oid,'EXECUTE') ORDER BY p.proname")->fetchAll(PDO::FETCH_COLUMN);
  A::assertSame(['d0_service_b_confirm','d0_service_b_inspect'],$exec);
  $snapshot=$financial->inspect_binding($account,'sandbox',$payment);A::assertNotNull($snapshot);
  $b=$snapshot->binding;A::assertSame($order->id,$b->orderRef);A::assertSame($order->companyReference,$b->tenantRef);
  $fakeReceipt=['id'=>$b->paymentId,'customer'=>$b->customerId,'externalReference'=>$b->externalReference,
   'billingType'=>$b->billingType,'status'=>'RECEIVED',
   'value'=>intdiv($b->amountCents,100).'.'.str_pad((string)($b->amountCents%100),2,'0',STR_PAD_LEFT)];
  // This is independent synthetic provider data, not a read of financial observations.
  $reader=new class($payment,$fakeReceipt) implements SyntheticPaymentReader{
   public int $calls=0;
   public function __construct(private string $id,private array $fixture){}
   public function fetchPayment(string $id):?array{$this->calls++;return $id===$this->id?$this->fixture:null;}
  };
  A::assertSame('denied',$authority->run($account,$payment,$reader)); // valid current fixture, but Consumer observation absent
  A::assertSame(1,$reader->calls);
  A::assertSame(0,(int)$admin->query('SELECT count(*) FROM portal_onepage.entry_confirmations')->fetchColumn());
  fwrite(STDOUT,"PASS SERVICE_B_REQUIRES_PERSISTED_CONSUMER_OBSERVATION\n");
  $capture->receive($inboxAccount,$account,$event,$payment); // includes unauthenticated refusal and duplicate capture
  $worker=$ports['inbox_b'];$gate=static fn(PDO $p):bool=>$p===$worker&&$guard('inbox_b',$p)===true;
  $consumer=new Consumer($worker,$gate,new Target($inboxAccount,$account,'sandbox',$event,$payment,$order->companyReference,$order->id),$financial,$reader);
  A::assertSame('completed',$consumer->run());A::assertSame(2,$reader->calls);
  A::assertSame('not_claimable',$consumer->run());A::assertSame(2,$reader->calls);
  $n->execute([$account,$order->id,$payment]);A::assertSame(1,(int)$n->fetchColumn());
  $q=$admin->prepare('SELECT count(*) FROM portal_onepage.entry_confirmations WHERE order_id=?::uuid');$q->execute([$order->id]);A::assertSame(0,(int)$q->fetchColumn());
  A::assertFalse($auth->isActiveBusinessSession($old));
  fwrite(STDOUT,"PASS SERVICE_B_CONSUMER_OBSERVATION_ONLY\n");
  $current=new class($payment,$fakeReceipt) implements \App\Domain\SitesIntegration\AsaasOfficialPaymentReader{
   public int $calls=0;public bool $reversed=false;
   public function __construct(private string $id,private array $fixture){}
   public function fetchPayment(string $id):?array{$this->calls++;return $id===$this->id?array_replace($this->fixture,$this->reversed?['status'=>'REFUNDED']:[]):null;}
  };
  A::assertSame('denied',$authority->run($account,'pay_AbsentSynthetic',$current));A::assertSame(0,$current->calls);
  // Defensive direct calls under the actual EXECUTE-only role: malformed facts never post.
  $pre=$authority->inspect($account,$payment);A::assertNotNull($pre);
  $normalized=$pre['value']['binding'];unset($normalized['due_date']);$normalized+=['currency'=>'BRL','status'=>'RECEIVED'];
  $direct=$service->prepare('SELECT portal_onepage.d0_service_b_confirm(?,?,?,?::jsonb,?::jsonb,?,clock_timestamp())');
  foreach([array_replace($normalized,['status'=>'REFUNDED']),array_replace($normalized,['amount_cents'=>1])] as $badReceipt){
   $direct->execute([$account,'sandbox',$payment,$pre['raw'],json_encode($badReceipt,JSON_THROW_ON_ERROR),str_repeat('0',64)]);
   A::assertSame('denied',$direct->fetchColumn());
  }
  $badSnapshot=$pre['value'];$badSnapshot['account_authorization']['generation']='0';
  $direct->execute([$account,'sandbox',$payment,json_encode($badSnapshot,JSON_THROW_ON_ERROR),json_encode($normalized,JSON_THROW_ON_ERROR),str_repeat('0',64)]);
  A::assertSame('denied',$direct->fetchColumn());
  $q->execute([$order->id]);A::assertSame(0,(int)$q->fetchColumn());
  fwrite(STDOUT,"PASS SERVICE_B_EXECUTE_REJECTS_DIVERGENT_FACTS\n");
  A::assertSame('recorded',$authority->run($account,$payment,$current));A::assertSame(1,$current->calls);
  A::assertFalse($auth->isActiveBusinessSession($old));
  $q->execute([$order->id]);A::assertSame(1,(int)$q->fetchColumn());
  A::assertSame('replay',$authority->run($account,$payment,$current));A::assertSame(2,$current->calls);
  $probe=$authority->inspect($account,$payment);A::assertNotNull($probe);
  $receipt=$probe['value']['binding'];unset($receipt['due_date']);$receipt+=['currency'=>'BRL','status'=>'RECEIVED'];
  $conflict=$service->prepare('SELECT portal_onepage.d0_service_b_confirm(?,?,?,?::jsonb,?::jsonb,?,clock_timestamp())');
  $conflict->execute([$account,'sandbox',$payment,$probe['raw'],json_encode($receipt,JSON_THROW_ON_ERROR),str_repeat('0',64)]);
  A::assertSame('denied',$conflict->fetchColumn()); // digest differs from persisted Consumer observation
  // Ordinary competing verifier session: lock timeout must not create another effect.
  $holder=$g->connect('onepage_d0_service_b','portal_native_orders_ci');
  A::assertTrue($holder->beginTransaction());
  $hold=$holder->prepare('SELECT portal_onepage.d0_service_b_inspect(?,?,?)');$hold->execute([$account,'sandbox',$payment]);A::assertIsString($hold->fetchColumn());
  $service->exec("SET lock_timeout='100ms'");
  try{$authority->run($account,$payment,$current);A::fail('Expected competing binding lock refusal');}
  catch(\PDOException $e){A::assertSame('55P03',$e->getCode());}
  finally{A::assertTrue($holder->rollBack());$service->exec("SET lock_timeout='0'");}
  A::assertSame('replay',$authority->run($account,$payment,$current));
  $current->reversed=true;A::assertSame('denied',$authority->run($account,$payment,$current));
  $q->execute([$order->id]);A::assertSame(1,(int)$q->fetchColumn());
  fwrite(STDOUT,"PASS SERVICE_B_NATIVE_ENTRY_COMMITTED_WITHOUT_BUYER\n");
  // READ ONLY availability: no FirstBriefingRelease::attempt, no HTTP POST.
  $read=$g->connect('portal_onepage_runtime','portal_native_orders_ci');
  $writer=$g->connect('portal_onepage_d0_verifier','portal_native_orders_ci');
  $entry=new \App\Infrastructure\Commerce\PostgresD0EntryStore($read,$writer,'portal_native_orders_ci',$account);
  $availability=new \App\Domain\Commerce\D0\FirstBriefingRelease($entry);
  foreach([$old,...$negativeSessions] as $denied)A::assertSame('unavailable',$availability->state($denied,$order->id));
  $session=$login(static function(string $pending)use($availability,$order):void{A::assertSame('unavailable',$availability->state($pending,$order->id));});
  A::assertNotSame($old,$session);A::assertTrue($auth->isActiveBusinessSession($session));
  A::assertSame('briefing_available',$availability->state($session,$order->id));
  $neighbors->execute([$order->id]);A::assertSame($beforeNeighbors,$neighbors->fetchAll(PDO::FETCH_COLUMN));
  A::assertSame(1,(int)$admin->query('SELECT count(*) FROM portal_onepage.entry_confirmations')->fetchColumn());
  fwrite(STDOUT,"PASS SERVICE_B_MFA_READ_AVAILABILITY_ZERO_COMMANDS\n");
  // Revocation uses the existing restricted registrar, never an admin confirmation.
  $registrar=new \App\Infrastructure\Commerce\PostgresOnepageEntryPayments($ctx['fixture']['registrar'],'portal_native_orders_ci',$account);
  A::assertSame('recorded',$registrar->revokeEntry($order->id,'synthetic-service-B-revoked'));
  A::assertSame('denied',$authority->run($account,$payment,$current));
  A::assertSame('unavailable',$availability->state($session,$order->id));
  $n->execute([$account,$order->id,$payment]);A::assertSame(1,(int)$n->fetchColumn());
  A::assertSame(2,$reader->calls);
  $q->execute([$order->id]);A::assertSame(1,(int)$q->fetchColumn());
  fwrite(STDOUT,"PASS SERVICE_B_NO_DUPLICATE_EFFECT\n");
 }
}
