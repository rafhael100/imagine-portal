<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\Commerce\D0\FirstBriefingRelease;
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,OnepageEntryPaymentTerms};
use App\Infrastructure\Commerce\{PostgresD0EntryStore,PostgresOnepageOrders,PostgresOnepageEntryPayments};
use PDO;use PDOException;use Closure;use PHPUnit\Framework\Assert as A;
final class D0PgCases
{
 private const ACCOUNT='native-entry-sandbox';
 private const APPEND='SELECT portal_onepage.d0_admit(?,?::uuid,?,?::jsonb,?,?,?::timestamptz)';
 private static function scalar(PDO $db,string $sql,array $args=[]):mixed{$q=$db->prepare($sql);$q->execute($args);return $q->fetchColumn();}
 private static function denied(PDO $db,string $sql,array $args=[]):void{try{self::scalar($db,$sql,$args);A::fail('SQL privilege refusal required');}catch(PDOException $e){A::assertSame('42501',$e->getCode());}}
 private static function wait(PDO $db,int $pid):void{
  $end=hrtime(true)+3000000000;do{
   if(self::scalar($db,"SELECT EXISTS(SELECT FROM pg_locks w JOIN pg_locks h ON w.locktype=h.locktype AND w.database=h.database AND w.classid=h.classid AND w.objid=h.objid AND w.objsubid=h.objsubid WHERE w.pid=? AND w.locktype='advisory' AND NOT w.granted AND h.pid=pg_backend_pid() AND h.granted AND pg_backend_pid()=ANY(pg_blocking_pids(?)))",[$pid,$pid])===true)return;
   usleep(1000);
  }while(hrtime(true)<$end);A::fail('Observable lock barrier required');
 }
 public static function run(PDO $admin,PDO $runtime,Closure $connect,PostgresOnepageOrders $orders,OnepageAcceptedOrder $a,string $session,array $wrongSessions):void{
  // Caller already passed the full unchanged CiGate on Laravel/admin in an empty isolated cluster.
  require_once __DIR__.'/Peer.php';
  $admin->exec(file_get_contents(dirname(__DIR__,2).'/database/security/portal_onepage_entry_payments.sql'));
  $admin->exec(file_get_contents(dirname(__DIR__,2).'/database/security/portal_onepage_d0_candidate.sql'));
  A::assertNull(self::scalar($admin,'SELECT portal_onepage.d0_account_gate()'));
  $admin->exec("CREATE OR REPLACE FUNCTION portal_onepage.d0_account_gate() RETURNS text LANGUAGE sql IMMUTABLE AS \$\$ SELECT 'native-entry-sandbox'::text \$\$");
  foreach(['portal_onepage_psp_registrar','portal_onepage_d0_verifier'] as $role)$admin->exec('ALTER ROLE '.$role.' LOGIN');
  $registrar=$connect('portal_onepage_psp_registrar');$writer=$connect('portal_onepage_d0_verifier');
  $admin->exec("INSERT INTO portal_onepage.payment_accounts(account_ref,environment,active) VALUES('native-entry-sandbox','sandbox',true)");
  $reg=new PostgresOnepageEntryPayments($registrar,'portal_native_orders_ci',self::ACCOUNT);
  A::assertSame('recorded',$reg->registerCustomer($a->id,'cus_D0Synthetic01'));
  $all=[$a];for($i=1;$i<5;$i++){
   $draft=$orders->createDraft($session,'d0-neighbor-'.$i,['site'=>'onepage','channels'=>[],'tier'=>'minimum']);$review=$orders->review($session,$draft['id']);
   $accept=['accepted'=>true]+array_intersect_key($review,array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
   $result=$orders->accept($session,'d0-accept-'.$i,$draft['id'],$accept);$all[]=$orders->findOwnedAcceptedOrder($session,$result['id']);
  }
  foreach($all as $i=>$order)A::assertSame('recorded',$reg->registerEntry($order->id,'pay_D0Synthetic0'.$i,'cus_D0Synthetic01',OnepageEntryPaymentTerms::reference($order,self::ACCOUNT,'cus_D0Synthetic01'),33000,OnepageEntryPaymentTerms::schedule($order)[0]['due_date'],'PIX'));
  $store=new PostgresD0EntryStore($runtime,$writer,'portal_native_orders_ci',self::ACCOUNT);
  foreach($wrongSessions as $bad)A::assertNull($store->inspect($bad,$a->id));
  A::assertNull((new PostgresD0EntryStore($runtime,$writer,'portal_native_orders_ci','neighbor-account'))->inspect($session,$a->id));
  foreach([$runtime,$registrar,$writer] as $role){self::denied($role,'SELECT * FROM portal_onepage.entry_confirmations');self::denied($role,'DELETE FROM portal_onepage.entry_confirmations');}
  self::denied($runtime,self::APPEND,[hash('sha256',$session),$a->id,self::ACCOUNT,'{}','pay_D0Synthetic00',str_repeat('a',64),date('c')]);
  self::denied($registrar,self::APPEND,[hash('sha256',$session),$a->id,self::ACCOUNT,'{}','pay_D0Synthetic00',str_repeat('a',64),date('c')]);
  self::denied($writer,'SELECT portal_onepage.revoke_entry_payment(?::uuid,?)',[$a->id,'forbidden']);
  A::assertFalse(self::scalar($admin,"SELECT has_table_privilege('portal_onepage_payment_owner','portal_onepage.payment_accounts','UPDATE')"));
  $args=static function($order)use($store,$session,$admin){$s=$store->inspect($session,$order->id);return [hash('sha256',$session),$order->id,self::ACCOUNT,json_encode($s,JSON_THROW_ON_ERROR),$s['binding']['payment_id'],hash('sha256','synthetic-verified-'.$order->id),self::scalar($admin,'SELECT clock_timestamp()::text')];};
  $first=$args($a);$bad=$first;$bad[1]=$all[1]->id;A::assertSame('denied',self::scalar($writer,self::APPEND,$bad));$bad=$first;$snap=json_decode($bad[3],true);$snap['context']['contract_revision']++;$bad[3]=json_encode($snap);A::assertSame('denied',self::scalar($writer,self::APPEND,$bad));
  foreach([0,2,3,4,5,6] as $index){$bad=$first;$bad[$index]=null;A::assertSame('denied',self::scalar($writer,self::APPEND,$bad));}
  // Real verifier composition, no provider transport: one explicit command, then reads/replay without GET.
  $snapshot=$store->inspect($session,$a->id);$fake=new class($snapshot) implements AsaasOfficialPaymentReader {
   public int $calls=0;public function __construct(private array $s){}public function fetchPayment(string $id):?array{$this->calls++;$b=$this->s['binding'];return ['id'=>$id,'customer'=>$b['customer_id'],'externalReference'=>$b['external_reference'],'billingType'=>$b['billing_type'],'value'=>'330.00','status'=>'RECEIVED'];}
  };
  $service=new FirstBriefingRelease($store,$fake);A::assertSame('unavailable',$service->state($session,$a->id));A::assertSame(0,$fake->calls);
  A::assertSame('briefing_available',$service->attempt($session,$a->id));A::assertSame('briefing_available',$service->attempt($session,$a->id));A::assertSame(1,$fake->calls);
  $fresh=new PostgresD0EntryStore($connect('portal_onepage_runtime'),$connect('portal_onepage_d0_verifier'),'portal_native_orders_ci',self::ACCOUNT);A::assertSame('confirmed',$fresh->inspect($session,$a->id)['state']);A::assertSame('ready',$fresh->inspect($session,$all[1]->id)['state']);
  $started=self::scalar($admin,'SELECT extract(epoch FROM pg_postmaster_start_time())');
  // Prior revoke, prior confirm, and concurrent replay. Child uses real candidate SQL on separate restricted connection.
  foreach(['revoke_first','confirm_first','replay'] as $i=>$mode){$order=$all[$i+1];$values=$args($order);$parent=$mode==='revoke_first'?$registrar:$writer;$peer=null;
   try{
    $parent->beginTransaction();
    if($mode==='revoke_first')A::assertSame('recorded',self::scalar($parent,'SELECT portal_onepage.revoke_entry_payment(?::uuid,?)',[$order->id,'synthetic-revoke']));else A::assertSame('recorded',self::scalar($parent,self::APPEND,$values));
    $childRole=$mode==='confirm_first'?'portal_onepage_psp_registrar':'portal_onepage_d0_verifier';
    $peer=new \TxBoundaryFixture\Peer(['role'=>$childRole,'started'=>$started,'args'=>$mode==='confirm_first'?[$order->id,'synthetic-revoke']:$values]);
    $peer->start('execute');self::wait($parent,$peer->backend);$parent->commit();A::assertSame($mode==='revoke_first'?'denied':($mode==='replay'?'replay':'recorded'),$peer->finish());
   }finally{if($parent->inTransaction())$parent->rollBack();if($peer)$peer->close();}
   A::assertSame($mode==='replay'?'confirmed':null,$store->inspect($session,$order->id)['state']??null);
  }
  $last=$all[4];$values=$args($last);$writer->beginTransaction();A::assertSame('recorded',self::scalar($writer,self::APPEND,$values));
  // Independent auditor sees no uncommitted row; reader would correctly wait on the binding lock.
  A::assertSame(0,(int)self::scalar($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations WHERE order_id=?::uuid',[$last->id]));$writer->rollBack();
  A::assertSame('ready',$fresh->inspect($session,$last->id)['state']);A::assertSame(3,(int)self::scalar($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations'));
  A::assertSame(0,(int)self::scalar($admin,"SELECT count(*) FROM portal_onepage.pending_orders WHERE status<>'pending_payment'"));
  // Account deactivation also serializes admission without granting the owner UPDATE.
  $peer=null;try{
   $admin->beginTransaction();$admin->exec("UPDATE portal_onepage.payment_accounts SET active=false WHERE account_ref='native-entry-sandbox'");
   $peer=new \TxBoundaryFixture\Peer(['role'=>'portal_onepage_d0_verifier','started'=>$started,'args'=>$values]);
   $peer->start('execute');self::wait($admin,$peer->backend);$admin->commit();A::assertSame('denied',$peer->finish());
  }finally{if($admin->inTransaction())$admin->rollBack();if($peer)$peer->close();}
  A::assertNull($store->inspect($session,$a->id));
  A::assertSame(0,(int)self::scalar($admin,'SELECT count(*) FROM portal_onepage.entry_confirmations WHERE order_id=?::uuid',[$last->id]));
 }
}
