<?php
declare(strict_types=1);
namespace PrivateD0Lab;
use App\Domain\SitesIntegration\{AsaasTrustedPaymentBinding,AsaasTrustedPaymentBindingSource,AsaasOfficialPaymentReader,AsaasVerifiedSettlementSource,PortalSettlementEvidence};
use Closure;
use Throwable;
use InvalidArgumentException;

interface SyntheticPaymentReader extends AsaasOfficialPaymentReader {}
final readonly class Snapshot {
 public function __construct(public string $account,public string $environment,
  public string $configuration,public string $generation,public string $line,
  public array $nativeContext,public AsaasTrustedPaymentBinding $binding) {
  if($account===''||$environment!=='sandbox'||$configuration===''||
   preg_match('/\A[1-9][0-9]*\z/D',$generation)!==1||$line!=='setup-1'||$nativeContext===[]||
   $binding->productCode!=='onepage'||$binding->purpose!=='installation'||
   preg_match('/\Aimagine:onepage:setup-1:[a-f0-9]{64}\z/D',$binding->externalReference)!==1)
   throw new InvalidArgumentException('SNAPSHOT_REFUSED');
 }
 public function stamp():array {
  return [$this->account,$this->environment,$this->configuration,$this->generation,
   $this->line,$this->nativeContext,get_object_vars($this->binding)];
 }
}
interface FinancialPort {
 // Trusted unique persisted binding only; absent/ambiguous/revoked => null.
 // No buyer session. Account/configuration originate outside the signal.
 public function inspect_binding(string $account,string $environment,string $payment):?Snapshot;
 // MUST atomically revalidate expected under account/binding/revocation locks.
 // No lock spans GET. Return recorded/replay only AFTER own COMMIT.
 // Financial ledger only; never entry_confirmations, order status or Sites.
 public function appendCommitted(string $request,Snapshot $expected,array $receipt,string $digest,string $observedAt):string;
}
final readonly class Reconcile {
 public function __construct(private FinancialPort $port,private SyntheticPaymentReader $reader,
  private string $account,private string $environment) {}
 // $lease is supplied by the trusted inbox claim adapter, not HTTP/browser.
 // $ack must validate the current lease, as in #768 finishWithLease.
 public function run(string $request,array $lease,Closure $ack):string {
  try {
   if(preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$request)!==1||
    $request==='00000000-0000-0000-0000-000000000000'||$this->environment!=='sandbox'||$this->account===''||
    !is_string($lease['payment_id']??null)||!is_string($lease['event_id']??null)||
    !is_string($lease['lease_token']??null)||$lease['event_id']===''||$lease['lease_token']==='')
    return 'manual_review';
   if(($lease['event_type']??null)!=='PAYMENT_RECEIVED')return 'manual_review';
   $s=$this->port->inspect_binding($this->account,$this->environment,$lease['payment_id']);
   if($s===null||$s->account!==$this->account||$s->environment!==$this->environment||
    $s->binding->paymentId!==$lease['payment_id'])return 'retry_wait';
   $source=new class($this->port,$s) implements AsaasTrustedPaymentBindingSource {
    public function __construct(private FinancialPort $port,private Snapshot $expected){}
    public function findInstallationPayment(string $order):?AsaasTrustedPaymentBinding {
     $e=$this->expected;if($order!==$e->binding->orderRef)return null;
     $now=$this->port->inspect_binding($e->account,$e->environment,$e->binding->paymentId);
     return $now!==null&&$now->stamp()===$e->stamp()?$now->binding:null;
    }
   };
   $proof=(new AsaasVerifiedSettlementSource($source,$this->reader))->findCurrentSettledOrder($s->binding->orderRef);
   if($proof===null)return 'retry_wait';
   $now=$this->port->inspect_binding($this->account,$this->environment,$lease['payment_id']);
   if($now===null||$now->stamp()!==$s->stamp())return 'retry_wait';
   // Values below are matched by the canonical verifier, not copied from the signal.
   // Digest is PortalSettlementEvidence's canonical digest, not hash(receipt JSON).
   $b=$s->binding;
   $receipt=['payment_id'=>$b->paymentId,'customer_id'=>$b->customerId,
    'external_reference'=>$b->externalReference,'amount_cents'=>$b->amountCents,
    'billing_type'=>$b->billingType,'currency'=>'BRL','status'=>'RECEIVED'];
   $observed=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z');
   $outcome=$this->port->appendCommitted($request,$s,$receipt,$proof->settlementSha256,$observed);
   if($outcome==='conflict')return 'manual_review';
   if(!in_array($outcome,['recorded','replay'],true))return 'retry_wait';
   $ack($lease,'observed_psp_received');
   return $outcome;
  } catch(Throwable) {return 'retry_wait';}
 }
}