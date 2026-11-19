<?php
declare(strict_types=1);
namespace PrivateServiceReceipt;
use PDO;
use RuntimeException;
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,AsaasTrustedPaymentBinding,AsaasTrustedPaymentBindingSource,AsaasVerifiedSettlementSource};
/** Lab only. Every attempt performs a fresh independent synthetic provider read. */
final readonly class ServiceAuthorityPort
{
 public function __construct(private ServiceGate $gate,private PDO $db){}
 private function ready():void {
  if($this->db->inTransaction()||!$this->gate->check($this->db,'onepage_d0_service_b','portal_native_orders_ci'))throw new RuntimeException('SERVICE_AUTHORITY_REFUSED');
 }
 public function inspect(string $account,string $payment):?array {
  $this->ready();$this->db->beginTransaction();
  try {
   $q=$this->db->prepare('SELECT portal_onepage.d0_service_b_inspect(?,?,?)::text');$q->execute([$account,'sandbox',$payment]);
   $raw=$q->fetchColumn();$v=is_string($raw)?json_decode($raw,true,32,JSON_THROW_ON_ERROR):null;
   if(!$this->db->commit())throw new RuntimeException('SERVICE_COMMIT_UNCONFIRMED');
   return is_array($v)?['raw'=>$raw,'value'=>$v]:null;
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function run(string $account,string $payment,AsaasOfficialPaymentReader $reader):string {
  $saved=$this->inspect($account,$payment);if($saved===null)return 'denied';
  $s=$saved['value'];$order=$s['context']['order_ref'];
  $binding=new class($this,$account,$payment,$saved) implements AsaasTrustedPaymentBindingSource {
   public function __construct(private ServiceAuthorityPort $p,private string $a,private string $id,private array $s){}
   public function findInstallationPayment(string $order):?AsaasTrustedPaymentBinding {
    $n=$this->p->inspect($this->a,$this->id);if($n===null||$n['value']!==$this->s['value'])return null;
    $c=$n['value']['context'];$b=$n['value']['binding'];if($c['order_ref']!==$order)return null;
    return new AsaasTrustedPaymentBinding($c['tenant_ref'],$order,$c['contract_ref'],$c['contract_revision'],'onepage',$b['payment_id'],$b['customer_id'],$b['external_reference'],$b['amount_cents'],$b['billing_type']);
   }
  };
  $proof=(new AsaasVerifiedSettlementSource($binding,$reader))->findCurrentSettledOrder($order);
  if($proof===null)return 'denied';
  $b=$s['binding'];unset($b['due_date']);$receipt=$b+['currency'=>'BRL','status'=>'RECEIVED'];
  $this->ready();$this->db->beginTransaction();
  try {
   $q=$this->db->prepare('SELECT portal_onepage.d0_service_b_confirm(?,?,?,?::jsonb,?::jsonb,?,?::timestamptz)');
   $q->execute([$account,'sandbox',$payment,$saved['raw'],json_encode($receipt,JSON_THROW_ON_ERROR),$proof->settlementSha256,(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')]);
   $out=$q->fetchColumn();if(!in_array($out,['recorded','replay','denied','conflict'],true))throw new RuntimeException('SERVICE_OUTCOME_REFUSED');
   if(in_array($out,['recorded','replay'],true)){if(!$this->db->commit())throw new RuntimeException('SERVICE_COMMIT_UNCONFIRMED');}
   else $this->db->rollBack();
   return $out;
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
