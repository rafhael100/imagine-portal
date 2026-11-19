<?php
declare(strict_types=1);
namespace PrivateD0DualDbCases;
use PDO;use Closure;use RuntimeException;use Throwable;
use PrivateD0Lab\{FinancialPort,Snapshot,SyntheticPaymentReader};
use PrivateD0Consumption\{Consumer,Target};
use App\Domain\SitesIntegration\OnepageEntryPaymentTerms as Terms;
use App\Infrastructure\Commerce\PostgresOnepageEntryPayments;
final class LostReturnPort implements FinancialPort {
 private bool $lost=false;
 public function __construct(private FinancialPort $inner,private Closure $visible){}
 public function inspect_binding(string $a,string $e,string $p):?Snapshot{return $this->inner->inspect_binding($a,$e,$p);}
 public function appendCommitted(string $r,Snapshot $s,array $v,string $d,string $at):string{
  $out=$this->inner->appendCommitted($r,$s,$v,$d,$at);
  if(!$this->lost&&in_array($out,['recorded','replay'],true)){
   $this->lost=true;($this->visible)($r);throw new RuntimeException('SYNTHETIC_RETURN_LOST');
  }return $out;
 }
}
final class PgFixture implements Fixture {
 private const ACCOUNT='1fcc491f-96d9-4efe-8ec5-b8b09de750dd';
 private const NATIVE='native-entry-sandbox';
 private array $events=[],$used=[];private string $payment='';private mixed $order=null;private int $calls=0,$sequence=0;
 public function __construct(private array $ctx,private array $ports,private Closure $gate,private FinancialPort $financial){
  $identities=[];
  foreach(['inbox_a','inbox_b','inbox_audit','native_audit','financial'] as $name){
   $p=$this->db($name);
   if($p->inTransaction())throw new RuntimeException('FIXTURE_TRANSACTION_REFUSED');
   $r=$this->q($p,'SELECT pg_backend_pid() pid,current_database() db,session_user login,current_user actor')->fetch(PDO::FETCH_ASSOC);
   $db=in_array($name,['native_audit','financial'],true)?'portal_native_orders_ci':'portal_asaas_ci';
   $role=$name==='financial'?'onepage_finance_lab_verifier':(in_array($name,['inbox_a','inbox_b'],true)?'asaas_reconcile_worker':'portal_native_test_bootstrap');
   self::need($r['db']===$db&&$r['login']===$role&&$r['actor']===$role);
   $identities[]=(string)$r['pid'];
  }
  self::need(count(array_unique($identities))===5);
  foreach(['inbox_a','inbox_b'] as $name)$this->db($name)->exec("SET statement_timeout='1500ms'; SET lock_timeout='1s'");
 }
 private static function need(bool $v):void{if(!$v)throw new RuntimeException('DUAL_FIXTURE_REFUSED');}
 private function db(string $name):PDO{
  $p=$this->ports[$name]??null;self::need($p instanceof PDO&&($this->gate)($name,$p)===true);
  self::need($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql');
  self::need($p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION)&&$p->setAttribute(PDO::ATTR_EMULATE_PREPARES,false));
  return $p;
 }
 private function q(PDO $p,string $s,array $a=[]):\PDOStatement{$q=$p->prepare($s);self::need($q!==false&&$q->execute($a));return $q;}
 private function nativeOrder(string $key):mixed{
  ($this->ctx['recheck'])();self::need(($this->gate)('native_runtime',$this->ctx['runtime'])===true);
  $orders=$this->ctx['store'];$session=$this->ctx['session'];
  $d=$orders->createDraft($session,'dual-'.$key,['site'=>'onepage','channels'=>[],'tier'=>'minimum']);
  self::need(($d['status']??null)==='draft');
  $review=$orders->review($session,$d['id']);self::need(is_array($review));
  $yes=['accepted'=>true]+array_intersect_key($review,array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
  $a=$orders->accept($session,'dual-accept-'.$key,$d['id'],$yes);
  self::need(($a['status']??null)==='pending_payment');
  $order=$orders->findOwnedAcceptedOrder($session,$a['id']);self::need($order!==null);
  return $order;
 }
 private function bind(mixed $order,string $payment):void{
  ($this->ctx['recheck'])();$registrar=$this->ctx['fixture']['registrar'];
  self::need(($this->gate)('registrar',$registrar)===true);
  $reg=new PostgresOnepageEntryPayments($registrar,'portal_native_orders_ci',self::NATIVE);
  self::need(in_array($reg->registerCustomer($order->id,'cus_ConfigFixture1'),['recorded','replay'],true));
  self::need($reg->registerEntry($order->id,$payment,'cus_ConfigFixture1',Terms::reference($order,self::NATIVE,'cus_ConfigFixture1'),33000,Terms::schedule($order)[0]['due_date'],'PIX')==='recorded');
 }
 public function seed(string $scenario):void{
  self::need(in_array($scenario,['scoped_lock','ordinary_claim_lock','ack_lease_expired','lost_financial_result'],true)&&!isset($this->used[$scenario]));
  $this->used[$scenario]=true;$i=++$this->sequence;$this->calls=0;
  if($i===1){$this->order=$this->ctx['order'];$this->payment='pay_ConfigFixture1';}
  else{$this->order=$this->nativeOrder('target'.$i);$this->payment='pay_DualTarget'.$i;$this->bind($this->order,$this->payment);}
  $neighbor=$this->nativeOrder('neighbor'.$i);$np='pay_DualNeighbor'.$i;$this->bind($neighbor,$np);
  $s=$this->financial->inspect_binding(self::NATIVE,'sandbox',$this->payment);
  self::need($s!==null&&$s->binding->orderRef===$this->order->id&&$s->binding->tenantRef===$this->order->companyReference);
  $this->events=['target'=>'evt_dual_target_'.$i,'alias'=>'evt_dual_alias_'.$i,'neighbor'=>'evt_dual_neighbor_'.$i];
  $p=$this->db('inbox_audit');self::need(!$p->inTransaction());$p->beginTransaction();
  try{
   foreach(['neighbor','target','alias'] as $name){
    $event=$this->events[$name];$payment=$name==='neighbor'?$np:$this->payment;
    $this->q($p,"INSERT INTO portal_psp.asaas_inbox(account_ref,event_id,event_type,payment_id,payload_sha256,received_at) VALUES(?::uuid,?,'PAYMENT_RECEIVED',?,?,clock_timestamp())",[self::ACCOUNT,$event,$payment,hash('sha256','synthetic:'.$event)]);
   }
   if($scenario==='scoped_lock')$this->q($p,"UPDATE portal_psp.asaas_inbox SET state='claimed',attempts=8,lease_token='11111111-1111-4111-8111-111111111111',lease_expires_at=clock_timestamp()-interval '1 second' WHERE account_ref=?::uuid AND event_id=?",[self::ACCOUNT,$this->events['target']]);
   self::need($p->commit());
  }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
 }
 public function withInboxLockA(string $event,Closure $onHeld):void{
  $p=$this->db('inbox_a');self::need(!$p->inTransaction());$p->beginTransaction();
  try{
   $q=$this->q($p,'SELECT event_id FROM portal_psp.asaas_inbox WHERE account_ref=?::uuid AND event_id=? AND payment_id=? FOR UPDATE',[self::ACCOUNT,$this->events[$event],$this->payment]);
   self::need($q->fetchColumn()===$this->events[$event]&&$q->fetchColumn()===false);
   $onHeld();
  }finally{self::need($p->inTransaction()&&$p->rollBack());}
 }
 public function runB(string $event,?Closure $duringSyntheticGet=null,bool $commitLossOnce=false):string{
  self::need(in_array($event,['target','alias'],true));
  $s=$this->financial->inspect_binding(self::NATIVE,'sandbox',$this->payment);self::need($s!==null);
  $b=$s->binding;$cents=$b->amountCents;
  $response=['id'=>$b->paymentId,'customer'=>$b->customerId,'externalReference'=>$b->externalReference,'billingType'=>$b->billingType,'status'=>'RECEIVED','value'=>intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT)];
  $touch=function()use($duringSyntheticGet):void{$this->calls++;if($duringSyntheticGet)$duringSyntheticGet();};
  $reader=new class($this->payment,$response,$touch) implements SyntheticPaymentReader{
   public function __construct(private string $id,private array $response,private Closure $touch){}
   public function fetchPayment(string $id):?array{if($id!==$this->id)return null;($this->touch)();return $this->response;}
  };
  $port=$this->financial;
  if($commitLossOnce)$port=new LostReturnPort($port,function(string $request):void{
   $seen=$this->observe();self::need(count($seen['postings'])===1&&isset($seen['requests'][$request]));
  });
  $target=new Target(self::ACCOUNT,self::NATIVE,'sandbox',$this->events[$event],$this->payment,$this->order->companyReference,$this->order->id);
  $pdo=$this->db('inbox_b');$gate=fn(PDO $p):bool=>$p===$pdo&&($this->gate)('inbox_b',$p)===true;
  return (new Consumer($pdo,$gate,$target,$port,$reader))->run();
 }
 public function expireCommittedLease(string $event):void{
  self::need($event==='target');$p=$this->db('inbox_audit');self::need(!$p->inTransaction());$p->beginTransaction();
  try{
   $r=$this->q($p,"SELECT lease_token::text token,state FROM portal_psp.asaas_inbox WHERE account_ref=?::uuid AND event_id=? AND payment_id=? FOR UPDATE",[self::ACCOUNT,$this->events[$event],$this->payment])->fetch(PDO::FETCH_ASSOC);
   self::need(is_array($r)&&$r['state']==='claimed'&&is_string($r['token']));
   $q=$this->q($p,"UPDATE portal_psp.asaas_inbox SET lease_expires_at=clock_timestamp()-interval '1 second' WHERE account_ref=?::uuid AND event_id=? AND payment_id=? AND lease_token=?::uuid AND state='claimed'",[self::ACCOUNT,$this->events[$event],$this->payment,$r['token']]);self::need($q->rowCount()===1&&$p->commit());
   $expired=$this->q($p,'SELECT lease_expires_at<clock_timestamp() FROM portal_psp.asaas_inbox WHERE account_ref=?::uuid AND event_id=? AND lease_token=?::uuid',[self::ACCOUNT,$this->events[$event],$r['token']])->fetchColumn();
   self::need(in_array($expired,[true,'t',1,'1'],true));
  }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
 }
 public function observe():array{
  $p=$this->db('inbox_audit');$n=$this->db('native_audit');self::need(!$p->inTransaction()&&!$n->inTransaction());$out=[];
  foreach($this->events as $name=>$event){
   $raw=$this->q($p,'SELECT row_to_json(i)::text FROM portal_psp.asaas_inbox i WHERE account_ref=?::uuid AND event_id=?',[self::ACCOUNT,$event])->fetchColumn();
   self::need(is_string($raw));$out[$name]=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
  }
  $out['postings']=[];$out['requests']=[];
  foreach(['observations'=>'postings','requests'=>'requests'] as $table=>$key){
   $rows=$this->q($n,'SELECT row_to_json(x)::text FROM onepage_finance_lab.'.$table.' x WHERE account_ref=? AND payment_id=?',[self::NATIVE,$this->payment])->fetchAll(PDO::FETCH_COLUMN);
   foreach($rows as $raw){$r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if($key==='requests')$out[$key][$r['request_id']]=$r;else $out[$key][]=$r;}
   if($key==='requests')ksort($out[$key]);
  }
  $out['entry_confirmations']=(int)$this->q($n,'SELECT count(*) FROM portal_onepage.entry_confirmations')->fetchColumn();return $out;
 }
 public function gets():int{return $this->calls;}
}
