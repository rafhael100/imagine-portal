<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;
use PDO;
use Throwable;
/** Fail-closed bootstrap guard: a D0 invoice is not a full-installation authorization.
 * Versioned terms must be produced by the native authenticated Portal contract/offer authority.
 * No such operational writer is enabled by this class, and no policy is chosen automatically.
 */
final readonly class FullInstallationBindingSource implements AsaasTrustedPaymentBindingSource {
 public function __construct(private PDO $db,private AsaasTrustedPaymentBindingSource $bindings){}
 public function findInstallationPayment(string $orderRef):?AsaasTrustedPaymentBinding {
  try{
   if($this->db->inTransaction()||$this->db->query('SHOW transaction_isolation')->fetchColumn()!=='read committed')return null;
   $p=$this->db->query("SELECT r.rolsuper OR r.rolbypassrls OR r.rolcreaterole OR r.rolcreatedb OR current_user<>session_user OR pg_has_role(current_user,c.relowner,'MEMBER') OR NOT(c.relrowsecurity AND c.relforcerowsecurity) OR has_table_privilege(current_user,c.oid,'INSERT,UPDATE,DELETE,TRUNCATE') AS unsafe FROM pg_roles r CROSS JOIN pg_class c WHERE r.rolname=current_user AND c.oid='portal_sites_sandbox.installation_release_terms'::regclass")->fetch(PDO::FETCH_ASSOC);
   if(!$p||$p['unsafe'])return null;
   $bind=$this->bindings->findInstallationPayment($orderRef);if(!$bind)return null;
   $s=$this->db->prepare("SELECT t.total_amount_cents FROM portal_sites_sandbox.installation_release_terms t JOIN portal_sites_sandbox.installation_bindings b USING(order_ref,tenant_ref)
    WHERE t.order_ref=? AND t.tenant_ref=? AND t.contract_ref=? AND t.contract_revision=? AND t.product_code=?
    AND t.offer_sha256=b.offer_sha256 AND t.policy='full_installation_settled' AND t.total_amount_cents=b.amount_cents");
   $s->execute([$bind->orderRef,$bind->tenantRef,$bind->contractRef,$bind->contractRevision,$bind->productCode]);$total=$s->fetchColumn();
   if($total===false||(int)$total!==$bind->amountCents)return null;
   return $bind;
  }catch(Throwable){return null;}
 }
}
