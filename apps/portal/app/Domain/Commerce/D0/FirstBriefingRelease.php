<?php
declare(strict_types=1);
namespace App\Domain\Commerce\D0;
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,AsaasDenyingPaymentReader,AsaasTrustedPaymentBinding,AsaasTrustedPaymentBindingSource,AsaasVerifiedSettlementSource};
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
/** Explicit command only. No PSP transport is installed by the feature provider. */
final readonly class FirstBriefingRelease
{
 public function __construct(private EntryStore $store=new UnavailableEntryStore(),private AsaasOfficialPaymentReader $payments=new AsaasDenyingPaymentReader()){}
 public function state(string $session,string $order):string
 {
  try{return ($this->store->inspect($session,$order)['state']??null)==='confirmed'?'briefing_available':'unavailable';}catch(Throwable){return 'unavailable';}
 }
 public function attempt(string $session,string $order):string
 {
  try{
   $snapshot=$this->store->inspect($session,$order);
   if(($snapshot['state']??null)==='confirmed')return 'briefing_available';
   if(($snapshot['state']??null)!=='ready')return 'unavailable';
   $source=new class($this->store,$session,$order,$snapshot) implements AsaasTrustedPaymentBindingSource {
    public function __construct(private EntryStore $store,private string $session,private string $order,private array $expected){}
    public function findInstallationPayment(string $orderRef):?AsaasTrustedPaymentBinding {
     if($orderRef!==$this->order || $this->store->inspect($this->session,$this->order)!==$this->expected)return null;
     $s=$this->expected;$c=$s['context'];$b=$s['binding'];
     return new AsaasTrustedPaymentBinding($c['tenant_ref'],$orderRef,$c['contract_ref'],$c['contract_revision'],'onepage',$b['payment_id'],$b['customer_id'],$b['external_reference'],$b['amount_cents'],$b['billing_type']);
    }
   };
   // No database transaction spans the independent provider read.
   $proof=(new AsaasVerifiedSettlementSource($source,$this->payments))->findCurrentSettledOrder($order);
   if($proof===null)return 'unavailable';
   $observed=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
   return in_array($this->store->admit($session,$order,$snapshot,$proof,$observed),['recorded','replay'],true)?'briefing_available':'unavailable';
  }catch(Throwable){return 'unavailable';}
 }
}
