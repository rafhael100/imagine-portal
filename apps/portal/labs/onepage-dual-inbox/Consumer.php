<?php
declare(strict_types=1);
namespace PrivateD0Consumption;
use PDO;
use Closure;
use Throwable;
use RuntimeException;
use PrivateD0Lab\{FinancialPort,SyntheticPaymentReader,Snapshot,Reconcile};
use App\Domain\SitesIntegration\PortalSettlementEvidence;

final class CommittedPort implements FinancialPort {
 public bool $committed=false,$indeterminate=false;
 public function __construct(private FinancialPort $inner,private Target $target) {}
 public function inspect_binding(string $a,string $e,string $p):?Snapshot{
  $s=$this->inner->inspect_binding($a,$e,$p);
  return $s!==null&&$this->target->matches($s)?$s:null;
 }
 public function appendCommitted(string $r,Snapshot $s,array $v,string $d,string $at):string {
  if(!$this->target->matches($s))return 'denied';
  $current=$this->inspect_binding($s->account,$s->environment,$s->binding->paymentId);
  if($current===null||$current->stamp()!==$s->stamp())return 'denied';
  $this->indeterminate=true; // Set BEFORE handing control to a possibly committing port.
  $out=$this->inner->appendCommitted($r,$s,$v,$d,$at);
  if(in_array($out,['recorded','replay','denied','conflict'],true))$this->indeterminate=false;
  if(in_array($out,['recorded','replay'],true))$this->committed=true;
  return $out;
 }
}
final readonly class Target {
 public function __construct(public string $account,public string $nativeAccount,
  public string $environment,public string $event,public string $payment,
  public string $tenant,public string $order) {
  if(preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$account)!==1||
   $nativeAccount===''||$environment!=='sandbox'||
   preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$tenant)!==1||
   preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$order)!==1||
   preg_match('/\Aevt_[A-Za-z0-9_&-]{8,128}\z/D',$event)!==1||
   preg_match('/\Apay_[A-Za-z0-9_]{6,64}\z/D',$payment)!==1)throw new RuntimeException('SCOPE_REFUSED');
 }
 public function matches(Snapshot $s):bool {
  return $s->account===$this->nativeAccount&&$s->environment===$this->environment&&
   $s->binding->paymentId===$this->payment&&$s->binding->tenantRef===$this->tenant&&
   $s->binding->orderRef===$this->order&&$s->binding->productCode==='onepage'&&$s->line==='setup-1';
 }
 // Stable laboratory request mapping v1. No token or attempt counter.
 // Not an authorization proof; financial request conflicts must remain closed.
 public function request():string {
  $hex=hash('sha256',json_encode(['onepage.claim-request.lab.v1',$this->account,$this->environment,$this->event],JSON_THROW_ON_ERROR));
  $hex=substr($hex,0,32);$hex[12]='8';$hex[16]=dechex((hexdec($hex[16])&3)|8);
  return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
 }
}
final class ScopeSql {
 public const CLAIM=<<<'SQL'
WITH pick AS (
 SELECT account_ref,event_id FROM portal_psp.asaas_inbox
 WHERE account_ref=CAST(? AS uuid) AND event_id=? AND payment_id=?
 AND attempts<8 AND (
 state='pending_reconciliation'
 OR (state='retry_wait' AND retry_after<=clock_timestamp())
 OR (state='claimed' AND lease_expires_at<clock_timestamp()))
 ORDER BY received_at,event_id FOR UPDATE SKIP LOCKED LIMIT 1
)
UPDATE portal_psp.asaas_inbox i
 SET state='claimed',attempts=i.attempts+1,lease_token=CAST(? AS uuid),
 lease_expires_at=clock_timestamp()+INTERVAL '45 seconds',retry_after=NULL
 FROM pick WHERE i.account_ref=pick.account_ref AND i.event_id=pick.event_id
 RETURNING i.event_id,i.event_type,i.payment_id,i.attempts,i.lease_token::text AS lease_token
SQL;
 public const FINISH=<<<'SQL'
UPDATE portal_psp.asaas_inbox SET state=?,lease_token=NULL,lease_expires_at=NULL,
 retry_after=CASE WHEN ?='retry_wait' THEN clock_timestamp()+INTERVAL '120 seconds' ELSE NULL END,
 last_checked_at=clock_timestamp()
 WHERE account_ref=CAST(? AS uuid) AND event_id=? AND payment_id=?
 AND state='claimed' AND lease_token=CAST(? AS uuid) AND lease_expires_at>clock_timestamp()
SQL;
 public const REAP=<<<'SQL'
WITH exhausted AS (
 SELECT account_ref,event_id FROM portal_psp.asaas_inbox
 WHERE account_ref=CAST(? AS uuid) AND event_id=? AND payment_id=?
 AND state='claimed' AND attempts>=8 AND lease_expires_at<clock_timestamp()
 FOR UPDATE SKIP LOCKED
)
UPDATE portal_psp.asaas_inbox i SET state='manual_review',lease_token=NULL,
 lease_expires_at=NULL,retry_after=NULL,last_checked_at=clock_timestamp()
 FROM exhausted e WHERE i.account_ref=e.account_ref AND i.event_id=e.event_id
 RETURNING i.event_id
SQL;
}
final readonly class Consumer {
 // PRIVATE LAB ONLY: target mapping from trusted configuration + preexisting binding.
 // gate MUST attest this exact PDO/effective config/fresh isolated cluster BEFORE SQL.
 // No factory, route, credential, schema/grant creation, or unrestricted dequeue.
 public function __construct(private PDO $db,private Closure $gate,private Target $target,
  private FinancialPort $financial,private SyntheticPaymentReader $reader) {}
 private function attest():void {
  if(($this->gate)($this->db)!==true||$this->db->inTransaction()||
   $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')throw new RuntimeException('GATE_REFUSED');
  if(!$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION)||
   !$this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false))throw new RuntimeException('PDO_MODE_REFUSED');
  $r=$this->db->query("SELECT current_database() db,current_user actor,session_user login,r.rolsuper OR r.rolbypassrls unsafe,
   c.relrowsecurity enabled,c.relforcerowsecurity forced
   FROM pg_roles r JOIN pg_class c ON c.oid='portal_psp.asaas_inbox'::regclass
   WHERE r.rolname=session_user")->fetch(PDO::FETCH_ASSOC);
  $yes=static fn($v)=>in_array($v,[true,'t',1,'1'],true);
  $no=static fn($v)=>in_array($v,[false,'f',0,'0'],true);
  if(!$r||$r['db']!=='portal_asaas_ci'||$r['actor']!=='asaas_reconcile_worker'||$r['login']!==$r['actor']||
   !$no($r['unsafe'])||!$yes($r['enabled'])||!$yes($r['forced']))throw new RuntimeException('ACL_REFUSED');
 }
 private function transaction(Closure $work):mixed {
  $this->attest();
  if(!$this->db->beginTransaction())throw new RuntimeException('BEGIN_REFUSED');
  try{$v=$work();if(!$this->db->commit())throw new RuntimeException('COMMIT_UNCONFIRMED');return $v;}
  catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private function execute(string $sql,array $args):\PDOStatement {
  $q=$this->db->prepare($sql);
  if($q===false||!$q->execute($args))throw new RuntimeException('SQL_REFUSED');
  return $q;
 }
 private function finish(array $l,string $out):void {
  if(!in_array($out,['retry_wait','manual_review','observed_psp_received'],true))throw new RuntimeException('OUTCOME_REFUSED');
  $t=$this->target;
  $this->transaction(function()use($t,$l,$out){
   if($this->execute(ScopeSql::FINISH,[$out,$out,$t->account,$t->event,$t->payment,$l['lease_token']])->rowCount()!==1)
    throw new RuntimeException('LEASE_REFUSED');
  });
 }
 public static function result(bool $committed,bool $acked,string $out):string {
  if($committed)return $acked?'completed':'financial_committed_ack_pending';
  return $acked?($out==='manual_review'?'reviewed':'deferred'):'attempt_unfinished';
 }
 public function run():string {
  $tracked=new CommittedPort($this->financial,$this->target);$acked=false;$out='retry_wait';$lease=null;$t=$this->target;
  try {
   $this->attest();
   $s=$this->financial->inspect_binding($t->nativeAccount,$t->environment,$t->payment);
   if($s===null)return 'unbound';
   if(!$t->matches($s))return 'scope_refused';
   $peek=$this->execute('SELECT payment_id,state FROM portal_psp.asaas_inbox WHERE account_ref=CAST(? AS uuid) AND event_id=?',[$t->account,$t->event])->fetch(PDO::FETCH_ASSOC);
   if($peek===false)return 'target_absent'; // NOT a claim that the whole queue is empty.
   if($peek['payment_id']!==$t->payment)return 'foreign_event';
   if($peek['state']==='manual_review')return 'terminal_review_financial_unresolved';
   // Lease token must change on reclaim; request above must NOT change.
   $h=bin2hex(random_bytes(16));$h[12]='4';$h[16]=dechex((hexdec($h[16])&3)|8);
   $token=substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
   $lease=$this->transaction(function()use($t,$token){
    if($this->execute(ScopeSql::REAP,[$t->account,$t->event,$t->payment])->fetchColumn()!==false)
     return ['reaped'=>true];
    return $this->execute(ScopeSql::CLAIM,[$t->account,$t->event,$t->payment,$token])->fetch(PDO::FETCH_ASSOC);
   });
   if($lease===false)return 'not_claimable';
   if(($lease['reaped']??false)===true)return 'terminal_review_financial_unresolved';
   if($lease['event_id']!==$t->event||$lease['payment_id']!==$t->payment||$lease['lease_token']!==$token)
    throw new RuntimeException('CLAIM_REFUSED');
   $out=(new Reconcile($tracked,$this->reader,$t->nativeAccount,$t->environment))->run($t->request(),$lease,
    function(array $got,string $state)use($lease,&$acked){
     if($got!==$lease)throw new RuntimeException('LEASE_REFUSED');
     $this->finish($lease,$state);$acked=true;
    });
   if($tracked->indeterminate)return 'financial_outcome_indeterminate';
   // Never mark a financially committed event deferred after an uncertain ACK.
   if(!$tracked->committed&&!$acked){
    $out=$out==='manual_review'||(int)$lease['attempts']>=8?'manual_review':'retry_wait';
    $this->finish($lease,$out);$acked=true;
   }
  } catch(Throwable) {}
  if($tracked->indeterminate)return 'financial_outcome_indeterminate';
  return self::result($tracked->committed,$acked,$out);
 }
}