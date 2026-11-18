<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\Commerce\D0\FirstBriefingRelease;
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,OnepageEntryPaymentTerms};
use App\Infrastructure\Commerce\{PostgresD0EntryStore,PostgresOnepageOrders,PostgresOnepageEntryPayments};
use PDO;use PDOException;use Closure;use PHPUnit\Framework\Assert as A;
final class AccountEpochPgCases
{
 private const ACCOUNT='native-entry-sandbox';
 private const APPEND='SELECT portal_onepage.d0_admit(?,?::uuid,?,?::jsonb,?,?,?::timestamptz)';
 private static function q(PDO $db,string $sql,array $args=[]):mixed{$q=$db->prepare($sql);$q->execute($args);return $q->fetchColumn();}
 private static function wait(PDO $db,int $pid):void{
  $end=hrtime(true)+3000000000;do{
   if(self::q($db,"SELECT EXISTS(SELECT FROM pg_locks w JOIN pg_locks h ON w.locktype=h.locktype AND w.database=h.database AND w.classid=h.classid AND w.objid=h.objid AND w.objsubid=h.objsubid WHERE w.pid=? AND w.locktype='advisory' AND NOT w.granted AND h.pid=pg_backend_pid() AND h.granted AND pg_backend_pid()=ANY(pg_blocking_pids(?)))",[$pid,$pid])===true)return;
   usleep(1000);
  }while(hrtime(true)<$end);A::fail('EPOCH_LOCK_BARRIER_REQUIRED');
 }
 public static function run(PDO $admin,PDO $runtime,Closure $connect,PostgresOnepageOrders $orders,OnepageAcceptedOrder $a,string $session,array $wrongSessions):void{
  // Only AccountEpochPgTest after the unchanged Laravel/admin/topology gates. No standalone runner.
  require_once __DIR__.'/../onepage-d0-admission/Peer.php';require_once __DIR__.'/Peer.php';
  foreach(['portal_onepage_entry_payments.sql','portal_onepage_d0_candidate.sql','portal_onepage_d0_account_epoch.sql'] as $sql)$admin->exec(file_get_contents(dirname(__DIR__,2).'/database/security/'.$sql));
  A::assertNull(self::q($admin,'SELECT portal_onepage.d0_account_gate()'));
  $admin->exec("CREATE OR REPLACE FUNCTION portal_onepage.d0_account_gate() RETURNS text LANGUAGE sql IMMUTABLE AS \$\$ SELECT 'native-entry-sandbox'::text \$\$");
  foreach(['portal_onepage_psp_registrar','portal_onepage_d0_verifier'] as $role)$admin->exec('ALTER ROLE '.$role.' LOGIN');
  $registrar=$connect('portal_onepage_psp_registrar');$writer=$connect('portal_onepage_d0_verifier');$operator=$connect('portal_native_test_bootstrap');
  $started=self::q($admin,'SELECT extract(epoch FROM pg_postmaster_start_time())');
  A::assertSame($started,self::q($operator,'SELECT extract(epoch FROM pg_postmaster_start_time())'));
  A::assertSame('portal_native_test_bootstrap',self::q($operator,'SELECT session_user'));
  A::assertNotSame(self::q($admin,'SELECT pg_backend_pid()'),self::q($operator,'SELECT pg_backend_pid()'));
  $operator->exec("SET statement_timeout='3s'; SET lock_timeout='2s'");
  $admin->exec("INSERT INTO portal_onepage.payment_accounts(account_ref,environment,active) VALUES('native-entry-sandbox','sandbox',true)");
  $reg=new PostgresOnepageEntryPayments($registrar,'portal_native_orders_ci',self::ACCOUNT);
  A::assertSame('recorded',$reg->registerCustomer($a->id,'cus_EpochSynthetic01'));
  $all=[$a];for($i=1;$i<4;$i++){
   $d=$orders->createDraft($session,'epoch-draft-'.$i,['site'=>'onepage','channels'=>[],'tier'=>'minimum']);$r=$orders->review($session,$d['id']);
   $yes=['accepted'=>true]+array_intersect_key($r,array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
   $v=$orders->accept($session,'epoch-accept-'.$i,$d['id'],$yes);$all[]=$orders->findOwnedAcceptedOrder($session,$v['id']);
  }
  foreach($all as $i=>$o)A::assertSame('recorded',$reg->registerEntry($o->id,'pay_EpochSynthetic0'.$i,'cus_EpochSynthetic01',OnepageEntryPaymentTerms::reference($o,self::ACCOUNT,'cus_EpochSynthetic01'),33000,OnepageEntryPaymentTerms::schedule($o)[0]['due_date'],'PIX'));
  $store=new PostgresD0EntryStore($runtime,$writer,'portal_native_orders_ci',self::ACCOUNT);
  A::assertNull($store->inspect($session,$a->id)); // No configuration association: default deny.
  $rotate=static fn()=> $operator->exec("UPDATE portal_onepage.payment_accounts SET configuration_ref='cfg_original_001' WHERE account_ref='native-entry-sandbox'");$rotate();
  // Reader/verifier/registrar cannot set epoch/configuration or advance/reset its sequence.
  foreach(['portal_onepage_runtime','portal_onepage_d0_verifier','portal_onepage_psp_registrar','portal_onepage_payment_owner'] as $role){
   A::assertFalse(self::q($admin,"SELECT has_table_privilege(?, 'portal_onepage.payment_accounts','UPDATE')",[$role]));
   foreach(['USAGE','UPDATE'] as $priv)A::assertFalse(self::q($admin,"SELECT has_sequence_privilege(?, 'portal_onepage.d0_account_generation_seq',?)",[$role,$priv]));
  }
  foreach([$runtime,$writer,$registrar] as $db)foreach(["UPDATE portal_onepage.payment_accounts SET configuration_ref='cfg_denied_00001'","SELECT nextval('portal_onepage.d0_account_generation_seq')"] as $sql){
   try{$db->exec($sql);A::fail('EPOCH_PRIVILEGE_REFUSAL_REQUIRED');}catch(PDOException $e){A::assertSame('42501',$e->getCode());}
  }
  $snapshot=$store->inspect($session,$a->id);$gen=$snapshot['account_authorization']['generation'];
  $admin->exec("UPDATE portal_onepage.payment_accounts SET authorization_generation=1 WHERE account_ref='native-entry-sandbox'");
  A::assertGreaterThan((int)$gen,(int)$store->inspect($session,$a->id)['account_authorization']['generation']);
  foreach(['', 'malformed config'] as $badConfig){
   try{self::q($operator,"UPDATE portal_onepage.payment_accounts SET configuration_ref=? WHERE account_ref='native-entry-sandbox'",[$badConfig]);A::fail('EPOCH_CONFIG_REFUSAL_REQUIRED');}
   catch(PDOException $e){A::assertSame('23514',$e->getCode());}
  }
  $operator->exec("UPDATE portal_onepage.payment_accounts SET configuration_ref=NULL WHERE account_ref='native-entry-sandbox'");
  A::assertNull($store->inspect($session,$a->id));$rotate();
  // Remove/recreate an UNBOUND account: foreign keys correctly prohibit deleting this bound account.
  $admin->exec("INSERT INTO portal_onepage.payment_accounts(account_ref,environment,active,configuration_ref) VALUES('epoch-unused','sandbox',true,'cfg_original_001')");
  $before=self::q($admin,"SELECT authorization_generation FROM portal_onepage.payment_accounts WHERE account_ref='epoch-unused'");
  $admin->exec("DELETE FROM portal_onepage.payment_accounts WHERE account_ref='epoch-unused'");
  $admin->exec("INSERT INTO portal_onepage.payment_accounts(account_ref,environment,active,configuration_ref) VALUES('epoch-unused','sandbox',true,'cfg_original_001')");
  A::assertGreaterThan((int)$before,(int)self::q($admin,"SELECT authorization_generation FROM portal_onepage.payment_accounts WHERE account_ref='epoch-unused'"));
  // Real service/verifier, entirely fake PSP. Mutation completes while GET callback is in progress.
  $fake=new class implements AsaasOfficialPaymentReader {
   public array $s=[];public ?Closure $during=null;public int $calls=0;public bool $completed=false;
   public function fetchPayment(string $id):?array{$this->calls++;$this->completed=false;if($this->during)($this->during)();$this->completed=true;$b=$this->s['binding'];return ['id'=>$id,'customer'=>$b['customer_id'],'externalReference'=>$b['external_reference'],'billingType'=>$b['billing_type'],'status'=>'RECEIVED','value'=>'330.00'];}
  };
  $service=new FirstBriefingRelease($store,$fake);
  foreach(['deactivate','reactivate','configuration'] as $mode){
   $admin->exec("UPDATE portal_onepage.payment_accounts SET active=true,configuration_ref='cfg_original_001' WHERE account_ref='native-entry-sandbox'");$fake->s=$store->inspect($session,$a->id);
   $fake->during=static function()use($operator,$runtime,$writer,$mode){
    A::assertFalse($runtime->inTransaction());A::assertFalse($writer->inTransaction());
    if($mode==='configuration'){
     $operator->exec("UPDATE portal_onepage.payment_accounts SET configuration_ref='cfg_alternate_01' WHERE account_ref='native-entry-sandbox'");
     $operator->exec("UPDATE portal_onepage.payment_accounts SET configuration_ref='cfg_original_001' WHERE account_ref='native-entry-sandbox'");
    }else{
     $operator->exec("UPDATE portal_onepage.payment_accounts SET active=false WHERE account_ref='native-entry-sandbox'");
     if($mode==='reactivate')$operator->exec("UPDATE portal_onepage.payment_accounts SET active=true WHERE account_ref='native-entry-sandbox'");
    }
   };
   A::assertSame('unavailable',$service->attempt($session,$a->id));A::assertTrue($fake->completed);
   A::assertSame(0,(int)self::q($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations'));
  }
  $admin->exec("UPDATE portal_onepage.payment_accounts SET active=true WHERE account_ref='native-entry-sandbox'");
  $fake->during=null;$fake->s=$store->inspect($session,$a->id);
  A::assertSame('briefing_available',$service->attempt($session,$a->id));$calls=$fake->calls;
  $rotate();A::assertSame('briefing_available',$service->attempt($session,$a->id));A::assertSame($calls,$fake->calls);
  $fresh=new PostgresD0EntryStore($connect('portal_onepage_runtime'),$connect('portal_onepage_d0_verifier'),'portal_native_orders_ci',self::ACCOUNT);
  A::assertSame('confirmed',$fresh->inspect($session,$a->id)['state']);
  $args=static function($o)use($store,$session,$admin){$s=$store->inspect($session,$o->id);return [hash('sha256',$session),$o->id,self::ACCOUNT,json_encode($s,JSON_THROW_ON_ERROR),$s['binding']['payment_id'],hash('sha256','synthetic-epoch-'.$o->id),self::q($admin,'SELECT clock_timestamp()::text')];};
  // The append, not only the PHP re-read, rejects an old/missing generation.
  $v=$args($all[1]);$rotate();A::assertSame('denied',self::q($writer,self::APPEND,$v));
  $v=$args($all[1]);$bad=$v;$s=json_decode($bad[3],true);unset($s['account_authorization']);$bad[3]=json_encode($s);A::assertSame('denied',self::q($writer,self::APPEND,$bad));
  // Account mutation wins: observed advisory-lock barrier, then commit or rollback.
  foreach(['commit','rollback'] as $mode){$v=$args($all[1]);$peer=null;try{
   $admin->beginTransaction();$admin->exec("UPDATE portal_onepage.payment_accounts SET configuration_ref='cfg_alternate_01' WHERE account_ref='native-entry-sandbox'");
   $peer=new \TxBoundaryFixture\Peer(['role'=>'portal_onepage_d0_verifier','started'=>$started,'args'=>$v]);$peer->start('execute');self::wait($admin,$peer->backend);
   if($mode==='commit')$admin->commit();else $admin->rollBack();A::assertSame($mode==='commit'?'denied':'recorded',$peer->finish());
  }finally{if($admin->inTransaction())$admin->rollBack();if($peer)$peer->close();}}
  // Append wins: config mutation waits; after append commit it cannot erase the record.
  $v=$args($all[2]);$peer=null;try{
   $writer->beginTransaction();A::assertSame('recorded',self::q($writer,self::APPEND,$v));
   A::assertSame(0,(int)self::q($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations WHERE order_id=?::uuid',[$all[2]->id]));
   $peer=new \AccountEpochFixture\Peer(['role'=>'portal_native_test_bootstrap','started'=>$started,'args'=>[]]);$peer->start('execute');self::wait($writer,$peer->backend);
   $writer->commit();A::assertSame('rotated',$peer->finish());
  }finally{if($writer->inTransaction())$writer->rollBack();if($peer)$peer->close();}
  A::assertSame('confirmed',$fresh->inspect($session,$all[2]->id)['state']);
  A::assertSame('replay',self::q($writer,self::APPEND,$v)); // Historical success is not expired by generation.
  // Append rollback leaves no durable mark, and another order is still independently ready.
  $v=$args($all[3]);$writer->beginTransaction();try{A::assertSame('recorded',self::q($writer,self::APPEND,$v));}finally{$writer->rollBack();}
  A::assertSame('ready',$fresh->inspect($session,$all[3]->id)['state']);
  A::assertSame(0,(int)self::q($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations WHERE order_id=?::uuid',[$all[3]->id]));
 }
}
