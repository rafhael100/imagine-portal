<?php
declare(strict_types=1);
namespace PrivateServiceReceipt;

use App\Domain\Commerce\D0\FirstBriefingRelease;
use App\Domain\SitesIntegration\AsaasOfficialPaymentReader;
use App\Infrastructure\Commerce\PostgresD0EntryStore;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Closure;
use PDO;
use RuntimeException;

/** In-process disposable PostgreSQL integration. No HTTP or production wiring. */
final class BuyerContinuation {
 private const DB='portal_native_orders_ci';
 private const READER='portal_onepage_runtime';
 private const WRITER='portal_onepage_d0_verifier';
 private static function need(bool $ok):void {
  if(!$ok)throw new RuntimeException('BUYER_CONTINUATION_REFUSED');
 }
 private static function audit(ServiceGate $g,PDO $admin):void {
  self::need(!$admin->inTransaction()&&$g->check($admin,'portal_native_test_bootstrap',self::DB));
 }
 private static function row(PDO $p,string $sql,array $args):?array {
  $q=$p->prepare($sql);self::need($q->execute($args));
  $row=$q->fetch(PDO::FETCH_ASSOC);
  self::need($q->fetch()===false);
  return is_array($row)?$row:null;
 }
 private static function sessionRow(PDO $audit,string $ref):array {
  self::need(preg_match('/\A[a-f0-9]{64}\z/D',$ref)===1);
  $r=self::row($audit,'SELECT identity_id::text,organization_id::text,status,session_purpose FROM public.portal_auth_sessions WHERE session_sha256=?',[hash('sha256',$ref)]);
  self::need(is_array($r));return $r;
 }
 private static function confirmations(PDO $audit):int {
  return (int)$audit->query('SELECT count(*) FROM portal_onepage.entry_confirmations')->fetchColumn();
 }
 private static function sessionStore(ServiceGate $g,string $account):array {
  $r=$g->connect(self::READER,self::DB);
  $w=$g->connect(self::WRITER,self::DB);
  self::need($r!==$w&&$g->check($r,self::READER,self::DB)&&$g->check($w,self::WRITER,self::DB));
  return [new PostgresD0EntryStore($r,$w,self::DB,$account),$r,$w];
 }
 public static function revokeBeforeConsumer(ServiceGate $g,PDO $admin,PostgresPortalBrowserAuthentication $auth,
   string $oldSession,string $order,string $account):void {
  self::audit($g,$admin);
  self::need(self::confirmations($admin)===0&&$auth->isActiveBusinessSession($oldSession));
  [$entry]=self::sessionStore($g,$account);
  self::need(($entry->inspect($oldSession,$order)['state']??null)==='ready');
  self::need($auth->revoke($oldSession)&&!$auth->isActiveBusinessSession($oldSession));
  self::need($entry->inspect($oldSession,$order)===null);
  self::need((new FirstBriefingRelease($entry))->attempt($oldSession,$order)==='unavailable');
  self::need(self::confirmations($admin)===0);
 }
 /** $fakeReceipt comes solely from a scenario-owned provider fixture, never the local ledger.
  * $login invokes the pending assertion once before completing real buyer MFA. */
 public static function confirmAfterConsumer(ServiceGate $g,PDO $admin,PostgresPortalBrowserAuthentication $auth,
   string $oldSession,string $order,string $account,string $payment,array $fakeReceipt,
   array $negativeSessions,Closure $login):void {
  self::audit($g,$admin);
  self::need($account==='native-entry-sandbox'&&!$auth->isActiveBusinessSession($oldSession)
   &&($fakeReceipt['id']??null)===$payment&&($fakeReceipt['status']??null)==='RECEIVED');
  self::need(self::confirmations($admin)===0);
  $observation=self::row($admin,"SELECT evidence_sha256 FROM onepage_finance_lab.observations
   WHERE account_ref=? AND environment='sandbox' AND order_id=?::uuid AND payment_id=?",[$account,$order,$payment]);
  self::need(is_array($observation)&&preg_match('/\A[a-f0-9]{64}\z/D',$observation['evidence_sha256'])===1);
  $original=self::sessionRow($admin,$oldSession);
  self::need($original['session_purpose']==='business'&&$original['status']==='revoked');
  [$entry,$read,$write]=self::sessionStore($g,$account);
  $psp=new class($payment,$fakeReceipt) implements AsaasOfficialPaymentReader {
   public int $reads=0;
   public function __construct(private string $expected,private array $receipt){}
   public function fetchPayment(string $paymentId):?array {
    ++$this->reads;return $paymentId===$this->expected?$this->receipt:null;
   }
  };
  $release=new FirstBriefingRelease($entry,$psp);
  $negative=static function(string $session)use($release,$order,$psp):void{
   $reads=$psp->reads;
   self::need($release->state($session,$order)==='unavailable'&&$release->attempt($session,$order)==='unavailable'&&$psp->reads===$reads);
  };
  self::need(count($negativeSessions)>=2&&count(array_unique($negativeSessions))===count($negativeSessions));
  foreach($negativeSessions as $session){self::need(is_string($session)&&$session!==$oldSession);$negative($session);}
  $negative($oldSession);
  $pending=null;
  $new=$login(static function(string $initial)use(&$pending,$negative,$oldSession):void {
   self::need($pending===null&&$initial!==$oldSession);
   $pending=$initial;$negative($initial);
  });
  self::need(is_string($new)&&$pending!==null&&$new!==$pending&&$new!==$oldSession
   &&!in_array($new,$negativeSessions,true)&&$auth->isActiveBusinessSession($new));
  $active=self::sessionRow($admin,$new);
  self::need($active['identity_id']===$original['identity_id']&&$active['organization_id']===$original['organization_id']
   &&$active['session_purpose']==='business'&&$active['status']==='active');
  $negative($pending);
  $snapshot=$entry->inspect($new,$order);
  self::need(($snapshot['state']??null)==='ready'&&($snapshot['binding']['payment_id']??null)===$payment);
  self::need($release->state($new,$order)==='unavailable'&&$psp->reads===0&&self::confirmations($admin)===0);
  // The merchant read comes from the fixture above, not from the observation.
  self::need($release->attempt($new,$order)==='briefing_available'&&$psp->reads===1);
  self::need(self::confirmations($admin)===1);
  $confirm=self::row($admin,"SELECT evidence_sha256 FROM portal_onepage.entry_confirmations
   WHERE account_ref=? AND environment='sandbox' AND order_id=?::uuid AND payment_id=?",[$account,$order,$payment]);
  self::need($confirm===$observation);
  self::need($release->attempt($new,$order)==='briefing_available'&&$psp->reads===1);
  self::need($auth->revoke($new)&&!$auth->isActiveBusinessSession($new));
  $negative($new);
  $readPending=null;
  $readSession=$login(static function(string $initial)use(&$readPending,$negative):void{
   self::need($readPending===null);$readPending=$initial;$negative($initial);
  });
  self::need(is_string($readSession)&&$readPending!==null&&$readSession!==$new&&$readSession!==$oldSession&&$readSession!==$readPending
   &&$auth->isActiveBusinessSession($readSession));
  $readIdentity=self::sessionRow($admin,$readSession);
  self::need($readIdentity['identity_id']===$original['identity_id']&&$readIdentity['organization_id']===$original['organization_id']);
  [$fresh,$freshRead,$freshWrite]=self::sessionStore($g,$account);
  self::need($freshRead!==$read&&$freshWrite!==$write
   &&$freshRead->query('SELECT pg_backend_pid()')->fetchColumn()!==$read->query('SELECT pg_backend_pid()')->fetchColumn()
   &&$freshWrite->query('SELECT pg_backend_pid()')->fetchColumn()!==$write->query('SELECT pg_backend_pid()')->fetchColumn());
  $freshRelease=new FirstBriefingRelease($fresh,$psp);
  self::need($freshRelease->state($readSession,$order)==='briefing_available'&&$freshRelease->attempt($readSession,$order)==='briefing_available'&&$psp->reads===1);
  foreach([$oldSession,$new,$pending,$readPending,...$negativeSessions] as $denied){
   self::need($freshRelease->state($denied,$order)==='unavailable'&&$freshRelease->attempt($denied,$order)==='unavailable'&&$psp->reads===1);
  }
  self::audit($g,$admin);
  self::need(self::confirmations($admin)===1);
  fwrite(STDOUT,"PASS PURCHASE_MFA_COMMAND_COMMITTED_FRESH_SESSION_READ\n");
 }
}
