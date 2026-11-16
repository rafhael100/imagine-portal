<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;
use PDO;
use Throwable;
use InvalidArgumentException;
/** Candidate expected-payment ledger; NEVER creates/reconciles a charge or grants an order.
 * Source rows must come from the homologated authenticated Portal order/offer producer,
 * which is not implemented or enabled by this class. Native DB login scopes the tenant.
 */
final readonly class PgSandboxInstallationBindingSource implements AsaasTrustedPaymentBindingSource
{
 public function __construct(private PDO $db,private string $sandboxAccountRef)
 {
  if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D',$sandboxAccountRef))throw new InvalidArgumentException('Sandbox binding source unavailable');
 }
 public function findInstallationPayment(string $orderRef):?AsaasTrustedPaymentBinding
 {
  if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$orderRef))return null;
  try {
   // A stale/repeatable snapshot or owner role must not bypass live revocation/RLS.
   if($this->db->inTransaction()||$this->db->query('SHOW transaction_isolation')->fetchColumn()!=='read committed')return null;
   $role=$this->db->query('SELECT rolsuper,rolbypassrls,rolcreaterole,rolcreatedb,current_user=session_user AS native FROM pg_roles WHERE rolname=session_user')->fetch(PDO::FETCH_ASSOC);
   if(!$role||$role['rolsuper']||$role['rolbypassrls']||$role['rolcreaterole']||$role['rolcreatedb']||!$role['native'])return null;
   $q=$this->db->prepare("SELECT tenant_ref,order_ref,contract_ref,contract_revision,product_code,payment_id,customer_id,external_reference,amount_cents,billing_type FROM portal_sites_sandbox.installation_bindings WHERE order_ref=? AND sandbox_account_ref=? AND provider_environment='sandbox'");
   $q->execute([$orderRef,$this->sandboxAccountRef]);$r=$q->fetch(PDO::FETCH_ASSOC);
   if(!$r)return null;
   return new AsaasTrustedPaymentBinding($r['tenant_ref'],$r['order_ref'],$r['contract_ref'],(int)$r['contract_revision'],$r['product_code'],$r['payment_id'],$r['customer_id'],$r['external_reference'],(int)$r['amount_cents'],$r['billing_type']);
  }catch(Throwable){return null;}
 }
}
