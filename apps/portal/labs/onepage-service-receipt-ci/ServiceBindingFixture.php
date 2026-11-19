<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\Commerce\D0\{EntryStore,FirstBriefingRelease};
use App\Domain\SitesIntegration\{OnepageEntryPaymentTerms,PortalSettlementEvidence,D0CurlFixture as Wire};
use App\Infrastructure\Commerce\{PostgresD0EntryStore,PostgresOnepageOrders,PostgresOnepageEntryPayments,ConfiguredSandboxD0Reader as Reader};
use PDO;use Closure;use PHPUnit\Framework\Assert as A;
final class ServiceBindingFixture
{
 private static function q(PDO $db,string $sql,array $args=[]):mixed{$q=$db->prepare($sql);$q->execute($args);return $q->fetchColumn();}
 public static function prepare(PDO $admin,PDO $runtime,Closure $connect,PostgresOnepageOrders $orders,OnepageAcceptedOrder $a,string $session,Closure $before):array{
  $before();
  // Called only by ConfiguredReaderPgTest after the original Laravel/admin/topology gates.
  require_once __DIR__.'/../onepage-d0-configured-reader/CurlFixture.php';
  A::assertTrue(extension_loaded('curl'));A::assertFalse(function_exists('curl_exec'));
  foreach(['portal_onepage_entry_payments.sql','portal_onepage_d0_candidate.sql','portal_onepage_d0_account_epoch.sql'] as $sql){$before();$admin->exec(file_get_contents(dirname(__DIR__,2).'/database/security/'.$sql));}
  A::assertNull(self::q($admin,'SELECT portal_onepage.d0_account_gate()'));
  // Exactly the existing synthetic #958 fixture setup. No new role/grant/policy.
  $before();$admin->exec("CREATE OR REPLACE FUNCTION portal_onepage.d0_account_gate() RETURNS text LANGUAGE sql IMMUTABLE AS \$\$ SELECT 'native-entry-sandbox'::text \$\$");
  foreach(['portal_onepage_psp_registrar','portal_onepage_d0_verifier'] as $role){$before();$admin->exec('ALTER ROLE '.$role.' LOGIN');}
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
  return ['entry'=>$store,'snapshot'=>$snapshot,'registrar'=>$registrar,'writer'=>$writer,'operator'=>$operator];
 }
}
