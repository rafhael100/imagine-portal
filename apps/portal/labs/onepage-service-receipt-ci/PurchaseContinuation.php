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
/** Lab-only composition: no source of business authorization and no new role. */
final class PurchaseContinuation
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
  BuyerContinuation::revokeBeforeConsumer($g,$admin,$auth,$old,$order->id,$account);
  // Native preexisting binding is read by the verifier, not supplied by an event.
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
  $capture->receive($inboxAccount,$account,$event,$payment); // includes unauthenticated refusal and duplicate capture
  $worker=$ports['inbox_b'];$gate=static fn(PDO $p):bool=>$p===$worker&&$guard('inbox_b',$p)===true;
  $consumer=new Consumer($worker,$gate,new Target($inboxAccount,$account,'sandbox',$event,$payment,$order->companyReference,$order->id),$financial,$reader);
  A::assertSame('completed',$consumer->run());A::assertSame(1,$reader->calls);
  A::assertSame('not_claimable',$consumer->run());A::assertSame(1,$reader->calls);
  $n->execute([$account,$order->id,$payment]);A::assertSame(1,(int)$n->fetchColumn());
  $q=$admin->prepare('SELECT count(*) FROM portal_onepage.entry_confirmations WHERE order_id=?::uuid');$q->execute([$order->id]);A::assertSame(0,(int)$q->fetchColumn());
  A::assertFalse($auth->isActiveBusinessSession($old));
  fwrite(STDOUT,"PASS PURCHASE_SAME_ORDER_FINANCIAL_ONLY\n");
  BuyerContinuation::confirmAfterConsumer($g,$admin,$auth,$old,$order->id,$account,$payment,$fakeReceipt,$negativeSessions,$login);
  $n->execute([$account,$order->id,$payment]);A::assertSame(1,(int)$n->fetchColumn());
  A::assertSame('not_claimable',$consumer->run());A::assertSame(1,$reader->calls);
  $q->execute([$order->id]);A::assertSame(1,(int)$q->fetchColumn());
  fwrite(STDOUT,"PASS PURCHASE_SAME_ORDER_NO_DUPLICATE_EFFECT\n");
 }
}
