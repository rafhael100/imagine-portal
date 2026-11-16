<?php
if(getenv('CI')!=='true'||getenv('SITES_RELEASE_TERMS_PG_CI')!=='1')exit(1);
$root=__DIR__.'/../apps/portal/app/Domain/SitesIntegration/';
spl_autoload_register(function($c)use($root){$p='App\\Domain\\SitesIntegration\\';if(str_starts_with($c,$p))require $root.substr($c,strlen($p)).'.php';});
use App\Domain\SitesIntegration\PgSandboxInstallationBindingSource as B;
use App\Domain\SitesIntegration\FullInstallationBindingSource as G;
use App\Domain\SitesIntegration\AsaasVerifiedSettlementSource as S;
$dsn='pgsql:host=127.0.0.1;dbname=portal_release_terms_ci';$o=new PDO($dsn,'postgres',getenv('PGPASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['sites-sandbox-bindings-candidate.sql','sites-installation-release-terms.sql'] as $file)$o->exec(file_get_contents(__DIR__.'/../apps/portal/ops/psp/'.$file));
$pw=bin2hex(random_bytes(32));$o->exec("CREATE ROLE release_reader LOGIN PASSWORD '$pw' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE; GRANT USAGE ON SCHEMA portal_sites_sandbox TO release_reader; GRANT SELECT ON portal_sites_sandbox.installation_bindings,portal_sites_sandbox.installation_release_terms TO release_reader; GRANT EXECUTE ON FUNCTION portal_sites_sandbox.can_read_binding(text,text) TO release_reader; INSERT INTO portal_sites_sandbox.principal_grants VALUES('release_reader','tenant-alfa',true,now()+interval '1 hour',NULL)");
foreach(['first'=>33000,'full'=>99000,'pending'=>99000] as $name=>$amount){$o->exec("INSERT INTO portal_sites_sandbox.installation_bindings VALUES('order-$name','tenant-alfa','contract-$name',1,'onepage',repeat('a',64),'sandbox-account','sandbox','pay_{$name}000001','cus_test000001','reference-$name',$amount,'PIX',now()+interval '1 hour')");}
$db=new PDO($dsn,'release_reader',$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$g=new G($db,new B($db,'sandbox-account'));$n=0;$ok=function($v)use(&$n){if(!$v)throw new RuntimeException('Release terms assertion '.($n+1));$n++;};
$ok($g->findInstallationPayment('order-full')===null);
foreach(['first'=>'full_installation_settled','full'=>'full_installation_settled','pending'=>'unapproved'] as $name=>$policy)$o->exec("INSERT INTO portal_sites_sandbox.installation_release_terms VALUES('order-$name','tenant-alfa','contract-$name',1,'onepage',repeat('a',64),repeat('b',64),'$policy',99000,3,33000,now()+interval '1 hour')");
$ok($g->findInstallationPayment('order-first')===null);$ok($g->findInstallationPayment('order-pending')===null);$ok($g->findInstallationPayment('order-full')?->amountCents===99000);
$reader=new class implements App\Domain\SitesIntegration\AsaasOfficialPaymentReader {public int $calls=0;public function fetchPayment(string $id):?array{$this->calls++;return ['id'=>$id,'customer'=>'cus_test000001','externalReference'=>'reference-full','billingType'=>'PIX','status'=>'RECEIVED','value'=>'990.00'];}};
$ok((new S($g,$reader))->findCurrentSettledOrder('order-first')===null&&$reader->calls===0);
$ok((new S($g,$reader))->findCurrentSettledOrder('order-full')!==null&&$reader->calls===1);
$ok((new G($o,new B($o,'sandbox-account')))->findInstallationPayment('order-full')===null);
$o->exec("INSERT INTO portal_sites_sandbox.binding_revocations(order_ref,tenant_ref) VALUES('order-full','tenant-alfa')");$ok($g->findInstallationPayment('order-full')===null);
try{$db->exec("UPDATE portal_sites_sandbox.installation_release_terms SET policy='full_installation_settled'");throw new LogicException('Runtime altered terms');}catch(PDOException){$ok(true);}
echo "OK: $n native immutable release-term assertions; PSP response synthetic, zero external requests or commercial policy approvals\n";
