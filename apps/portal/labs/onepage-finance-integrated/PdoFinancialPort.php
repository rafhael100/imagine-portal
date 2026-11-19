<?php
declare(strict_types=1);
namespace PrivateD0IntegratedLab;
use PrivateD0Lab\{FinancialPort,Snapshot};
use App\Domain\SitesIntegration\AsaasTrustedPaymentBinding;
use PDO;
use Closure;
use WeakMap;
use RuntimeException;
use Throwable;
/** Private candidate; no connection factory, credential, migration or network IO. */
final class PdoFinancialPort implements FinancialPort {
 private WeakMap $raw;
 public function __construct(private readonly PDO $db,private readonly Closure $effectiveConfigGate,
  private readonly array $destination) {
  $this->raw=new WeakMap();
  $this->attest();
 }
 private function attest():void {
  // Runner must compare effective Laravel config AND its PDO object before SQL.
  // Not an arbitrary environment string or a replacement for P20 qualification.
  if(($this->effectiveConfigGate)($this->db)!==true || $this->db->inTransaction()
   || $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')throw new RuntimeException('DESTINATION_REFUSED');
  $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
  // Restricted runtime cannot call pg_control_system; trusted bootstrap must
  // bind backend/database/role below to its already-attested fresh cluster.
  $r=$this->db->query("SELECT current_database() db,session_user login,current_user actor,
   host(inet_server_addr()) host,inet_server_port() port,
   current_setting('server_version_num')::int/10000 major,
   current_setting('transaction_isolation') isolation,
   rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
    OR EXISTS(SELECT FROM pg_auth_members WHERE member=r.oid) unsafe
   FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
  if(!is_array($r)||$r['login']!=='onepage_finance_lab_verifier'||$r['actor']!==$r['login']
   ||$r['db']!==($this->destination['database']??null)||$r['host']!=='127.0.0.1'
   ||(string)$r['port']!==(string)($this->destination['port']??null)|| (int)$r['major']!==18
   ||$r['isolation']!=='read committed'||!in_array($r['unsafe'],[false,'f',0,'0'],true))throw new RuntimeException('IDENTITY_REFUSED');
 }
 public static function decode(string $raw,string $account,string $environment,string $payment):Snapshot {
  $s=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
  if(!is_array($s)||($s['account']??null)!==$account||($s['environment']??null)!==$environment
   ||!is_array($s['context']??null)||!is_array($s['binding']??null)||!is_array($s['account_authorization']??null))throw new RuntimeException('SNAPSHOT_REFUSED');
  $c=$s['context'];$b=$s['binding'];$a=$s['account_authorization'];
  if(($b['payment_id']??null)!==$payment||!is_string($b['due_date']??null)
   ||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$b['due_date'])
   ||($c['line_key']??null)!=='setup-1'||($c['product_code']??null)!=='onepage'
   ||!is_int($c['contract_revision']??null)||!is_int($b['amount_cents']??null))throw new RuntimeException('SNAPSHOT_REFUSED');
  return new Snapshot($account,$environment,$a['configuration'],$a['generation'],'setup-1',$c,
   new AsaasTrustedPaymentBinding($c['tenant_ref'],$c['order_ref'],$c['contract_ref'],$c['contract_revision'],'onepage',
    $b['payment_id'],$b['customer_id'],$b['external_reference'],$b['amount_cents'],$b['billing_type']));
 }
 public function inspect_binding(string $account,string $environment,string $payment):?Snapshot {
  $this->attest();
  try {
   $this->db->beginTransaction();
   $q=$this->db->prepare('SELECT onepage_finance_lab.inspect_binding(?,?,?)::text');$q->execute([$account,$environment,$payment]);
   $raw=$q->fetchColumn();$s=is_string($raw)?self::decode($raw,$account,$environment,$payment):null;
   if(!$this->db->commit())throw new RuntimeException('COMMIT_UNCONFIRMED');
   if($s!==null)$this->raw[$s]=$raw; // Preserve exact DB JSON including due_date.
   return $s;
  } catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function appendCommitted(string $request,Snapshot $expected,array $receipt,string $digest,string $observedAt):string {
  if(!isset($this->raw[$expected]))return 'denied'; // never reconstruct a caller snapshot
  $this->attest();
  try {
   $this->db->beginTransaction();
   $q=$this->db->prepare('SELECT onepage_finance_lab.record_observation(?::uuid,?,?,?::uuid,?::jsonb,?::jsonb,?,?::timestamptz)');
   $q->execute([$request,$expected->account,$expected->environment,$expected->binding->orderRef,
    $this->raw[$expected],json_encode($receipt,JSON_THROW_ON_ERROR),$digest,$observedAt]);
   $result=$q->fetchColumn();
   if(!in_array($result,['recorded','replay','conflict','denied'],true))throw new RuntimeException('APPEND_REFUSED');
   if(in_array($result,['recorded','replay'],true)){
    if(!$this->db->commit())throw new RuntimeException('COMMIT_UNCONFIRMED');
   } else {$this->db->rollBack();}
   return $result;
  } catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
final class RequireConfirmedAck {
 /** Backend MUST acquire and validate a live lease and commit ACK itself.
  * This wrapper proves only false/exception cannot become positive to Reconcile. */
 public static function wrap(Closure $backend):Closure {
  return static function(array $lease,string $outcome)use($backend):void {
   if($outcome!=='observed_psp_received'||$backend($lease,$outcome)!==true)throw new RuntimeException('ACK_UNCONFIRMED');
  };
 }
}
