<?php
declare(strict_types=1);
if(getenv('CI')!=='true'||getenv('PORTAL_SITES_BINDING_CI')!=='1')throw new RuntimeException('Disposable PG CI only');
$root=__DIR__.'/../apps/portal/app/Domain/SitesIntegration/';
foreach(['AsaasTrustedPaymentBinding','AsaasTrustedPaymentBindingSource','AsaasOfficialPaymentReader','PortalSettlementEvidence','PortalCurrentSettlementSource','AsaasDenyingBindingSource','AsaasDenyingPaymentReader','AsaasVerifiedSettlementSource','AsaasReadOnlyPaymentClient','PgSandboxInstallationBindingSource','SandboxSettlementFactory'] as $f)require_once $root.$f.'.php';
use App\Domain\SitesIntegration\PgSandboxInstallationBindingSource as Source;
use App\Domain\SitesIntegration\AsaasVerifiedSettlementSource as Settlement;
use App\Domain\SitesIntegration\AsaasOfficialPaymentReader;
$connect=fn($u,$p)=>new PDO(getenv('SITES_BINDING_PG_DSN'),$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$owner=$connect('postgres','postgres');$owner->exec(file_get_contents(__DIR__.'/../apps/portal/ops/psp/sites-sandbox-bindings-candidate.sql'));
$n=0;$ok=function($v)use(&$n){if(!$v)throw new RuntimeException('Binding assertion '.($n+1));$n++;};
$deny=function($fn)use($ok){try{$fn();}catch(Throwable){$ok(true);return;}$ok(false);};
foreach(['alpha','beta'] as $tenant){
 $role='sites_binding_'.$tenant;$password=bin2hex(random_bytes(24));
 $owner->exec("CREATE ROLE $role LOGIN PASSWORD '$password' NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB NOINHERIT; GRANT USAGE ON SCHEMA portal_sites_sandbox TO $role; GRANT SELECT ON portal_sites_sandbox.installation_bindings TO $role; GRANT EXECUTE ON FUNCTION portal_sites_sandbox.can_read_binding(text,text) TO $role; INSERT INTO portal_sites_sandbox.principal_grants VALUES('$role','tenant-$tenant',true,now()+interval '1 hour',NULL)");
 $owner->exec("INSERT INTO portal_sites_sandbox.installation_bindings VALUES('order-$tenant','tenant-$tenant','contract-$tenant',1,'onepage',repeat('a',64),'sandbox-account','sandbox','pay_{$tenant}0001','cus_{$tenant}0001','ext-$tenant',99000,'PIX',now()+interval '1 hour')");
 $db[$tenant]=$connect($role,$password);$source[$tenant]=new Source($db[$tenant],'sandbox-account');
}
$ok($source['alpha']->findInstallationPayment('order-alpha')?->amountCents===99000);
$ok($source['beta']->findInstallationPayment('order-beta')?->tenantRef==='tenant-beta');
$ok($source['alpha']->findInstallationPayment('order-beta')===null);
$ok($source['beta']->findInstallationPayment('order-alpha')===null);
$ok((new Source($owner,'sandbox-account'))->findInstallationPayment('order-alpha')===null);
$ok((new Source($db['alpha'],'other-account'))->findInstallationPayment('order-alpha')===null);
$db['alpha']->exec("SET app.tenant_id='tenant-beta'");
$ok((int)$db['alpha']->query('SELECT count(*) FROM portal_sites_sandbox.installation_bindings')->fetchColumn()===1);
$deny(fn()=>$db['alpha']->query('SELECT * FROM portal_sites_sandbox.principal_grants'));
$deny(fn()=>$db['alpha']->query('SELECT * FROM portal_sites_sandbox.binding_audit'));
$deny(fn()=>$db['alpha']->exec("INSERT INTO portal_sites_sandbox.binding_revocations VALUES('order-alpha','tenant-alpha',now())"));
$deny(fn()=>$owner->exec("UPDATE portal_sites_sandbox.installation_bindings SET amount_cents=1"));
$deny(fn()=>$owner->exec("INSERT INTO portal_sites_sandbox.installation_bindings SELECT 'order-copy',tenant_ref,contract_ref,contract_revision,product_code,offer_sha256,sandbox_account_ref,provider_environment,payment_id,customer_id,external_reference,amount_cents,billing_type,expires_at FROM portal_sites_sandbox.installation_bindings WHERE order_ref='order-alpha'"));
$db['alpha']->beginTransaction();$ok($source['alpha']->findInstallationPayment('order-alpha')===null);$db['alpha']->rollBack();
$owner->exec("UPDATE portal_sites_sandbox.principal_grants SET expires_at=now()-interval '1 second' WHERE tenant_ref='tenant-beta'");
$ok($source['beta']->findInstallationPayment('order-beta')===null);
$owner->exec("INSERT INTO portal_sites_sandbox.binding_revocations(order_ref,tenant_ref) VALUES('order-alpha','tenant-alpha')");
$ok($source['alpha']->findInstallationPayment('order-alpha')===null);
$owner->exec("INSERT INTO portal_sites_sandbox.installation_bindings VALUES('order-check','tenant-alpha','contract-check',1,'landing_page',repeat('b',64),'sandbox-account','sandbox','pay_recheck0001','cus_recheck0001','ext-check',129000,'PIX',now()+interval '1 hour')");
// Synthetic PSP response ONLY; real PG revocation happens during its read.
$reader=new class($owner) implements AsaasOfficialPaymentReader {
 public function __construct(private PDO $owner){}
 public function fetchPayment(string $id):?array {
  $this->owner->exec("INSERT INTO portal_sites_sandbox.binding_revocations(order_ref,tenant_ref) VALUES('order-check','tenant-alpha')");
  return ['id'=>$id,'customer'=>'cus_recheck0001','externalReference'=>'ext-check','billingType'=>'PIX','status'=>'RECEIVED','value'=>'1290.00','currency'=>'BRL'];
 }
};
$ok((new Settlement($source['alpha'],$reader))->findCurrentSettledOrder('order-check')===null);
$ok((int)$owner->query('SELECT count(*) FROM portal_sites_sandbox.binding_audit')->fetchColumn()===8);
$deny(fn()=>$owner->exec('DELETE FROM portal_sites_sandbox.binding_audit'));
$deny(fn()=>App\Domain\SitesIntegration\SandboxSettlementFactory::fromMountedToken($db['alpha'],'sandbox-account'));
echo "OK: $n PG18 native binding/tenant/revocation/immutable-audit assertions. PSP response synthetic; zero provider requests, no operational reconciliation claimed.\n";
