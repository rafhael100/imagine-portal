<?php
declare(strict_types=1);
namespace PrivateSharedIngress;
use PDO;use Closure;use RuntimeException;use Throwable;
use PrivateD0Lab\{FinancialPort,Snapshot};
use PrivateOnepageIngress\{ApprovedCase,CaseRegistry,Pinned768Authentication,Ingress,SharedInboxPort};
/** Concrete lab adapter; no HTTP route, connection factory, parser change or PSP. */
final readonly class CaptureHarness {
 public function __construct(private PDO $admin,private PDO $capture,private Closure $gate,private FinancialPort $financial){}
 private static function need(bool $v):void{if(!$v)throw new RuntimeException('SHARED_CAPTURE_HARNESS_REFUSED');}
 public function receive(string $account,string $native,string $event,string $payment):void{
  self::need($account==='1fcc491f-96d9-4efe-8ec5-b8b09de750dd'&&$native==='native-entry-sandbox');
  self::need(($this->gate)('inbox_audit',$this->admin)===true&&($this->gate)('ingress_capture',$this->capture)===true);
  $s=$this->financial->inspect_binding($native,'sandbox',$payment);self::need($s!==null);
  $r=hash('sha256',json_encode($s->stamp(),JSON_THROW_ON_ERROR));
  $c=new ApprovedCase($account,$s->binding->orderRef,$payment,$r);
  // Setup-only projection of a PREEXISTING native binding. Not event-provided authority.
  // Revoke remains terminal; no update/rearm. The financial consumer rechecks native
  // binding independently: this projection does not promise cross-DB revocation.
  self::need(!$this->admin->inTransaction()&&$this->admin->beginTransaction());
  try{
   $q=$this->admin->prepare("INSERT INTO onepage_ingress_lab.admissions(account_ref,order_id,payment_id,revision,environment,active) VALUES(?,?,?,?,'sandbox',true) ON CONFLICT(account_ref,order_id) DO NOTHING");
   $q->execute([$c->account,$c->order,$c->payment,$c->revision]);
   $q=$this->admin->prepare('SELECT payment_id,revision,environment,active FROM onepage_ingress_lab.admissions WHERE account_ref=? AND order_id=?');$q->execute([$c->account,$c->order]);$a=$q->fetch(PDO::FETCH_ASSOC);
   self::need(is_array($a)&&$a['payment_id']===$c->payment&&$a['revision']===$c->revision&&$a['environment']==='sandbox'&&in_array($a['active'],[true,'t',1,'1'],true)&&$q->fetch()===false);
   self::need($this->admin->commit());
  }catch(Throwable $e){if($this->admin->inTransaction())$this->admin->rollBack();throw $e;}
  $registry=new class($this->financial,$s) implements CaseRegistry{
   public function __construct(private FinancialPort $p,private Snapshot $s){}
   public function isCurrent(ApprovedCase $c):bool{
    $n=$this->p->inspect_binding($this->s->account,$this->s->environment,$c->payment);
    return $n!==null&&$n->stamp()===$this->s->stamp()&&$c->order===$n->binding->orderRef;
   }
  };
  $port=new SharedInboxPort($this->capture,fn(PDO $p):bool=>$p===$this->capture&&($this->gate)('ingress_capture',$p)===true,true);
  $token='SYNTHETIC_SHARED_RECEIVER_TOKEN_NOT_A_SECRET_0000';
  $ingress=new Ingress(new Pinned768Authentication($token),$registry,$port,$c,true);
  $body=json_encode(['id'=>$event,'event'=>'PAYMENT_RECEIVED','payment'=>['id'=>$payment]],JSON_THROW_ON_ERROR);
  self::need($ingress->receive('POST','application/json',[],$body)==='unauthorized');
  self::need($ingress->receive('POST','application/json',[$token],$body)==='captured');
  self::need($ingress->receive('POST','application/json',[$token],$body)==='duplicate');
  self::need(($this->gate)('inbox_audit',$this->admin)===true);
  $q=$this->admin->prepare('SELECT event_type,payment_id,state,attempts FROM portal_psp.asaas_inbox WHERE account_ref=?::uuid AND event_id=?');$q->execute([$account,$event]);$v=$q->fetch(PDO::FETCH_ASSOC);
  self::need(is_array($v)&&$v['event_type']==='PAYMENT_RECEIVED'&&$v['payment_id']===$payment&&$v['state']==='pending_reconciliation'&&(int)$v['attempts']===0&&$q->fetch()===false);
 }
}
