<?php
declare(strict_types=1);
namespace PrivateOnepageOneShot;
use PDO;
use RuntimeException;
use PrivateD0Consumption\Target;
use PrivateD0Lab\SyntheticPaymentReader;
use PrivateD0IntegratedLab\PdoFinancialPort;
use RebuiltOnepageWorker\{Worker,ServiceAuthorityPort};
/** No DDL, account creation, capture endpoint or operational provider. */
final class OneShot {
 public const SOURCE_PIN='5b4fe89fdda1b9009dc5079143ca832e85a4ee9f';
 private bool $used=false;
 private const ROLES=['inbox'=>'asaas_reconcile_worker','finance'=>'onepage_finance_lab_verifier','entry'=>'onepage_d0_service_b'];
 public function __construct(private QualifiedLabContext $context){}
 private function check(string $slot,PDO $pdo,array $receipt):bool {
  $now=$this->context->currentReceipt();
  if($now!==$receipt||($now['mode']??null)!=='dedicated-disposable-synthetic'
   ||($now['authorized']??null)!==true||($now['catalogVerified']??null)!==true
   ||($now['expiresAt']??0)<=time()||($now['sourcePin']??null)!==self::SOURCE_PIN)return false;
  $s=$now['slots'][$slot]??null;if(!is_array($s)||!is_array($s['config']??null)||!is_array($s['sql']??null))return false;
  $c=$this->context->connection($slot);if($c->getRawPdo()!==$pdo||$pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')return false;
  $expected=$s['config'];
  if(($expected['driver']??null)!=='pgsql'||($expected['host']??null)!=='127.0.0.1'
   ||($expected['username']??null)!==self::ROLES[$slot]
   ||!is_int($expected['port']??null)||$expected['port']<1||$expected['port']>65535
   ||($slot==='inbox' ? ($expected['database']??null)!=='portal_asaas_ci' : !preg_match('/\Aonepage_isolated_[a-z0-9_]{8,64}\z/D',$expected['database']??'')))return false;
  foreach([$this->context->effectiveConfiguration($slot),$c->getConfig()] as $cfg){
   foreach(['url','read','write','unix_socket','options'] as $key)if(!empty($cfg[$key]))return false;
   foreach(['driver','host','port','database','username'] as $key)if(!isset($cfg[$key],$expected[$key])||(string)$cfg[$key]!== (string)$expected[$key])return false;
  }
  // Only after configuration/PDO receipt checks: read-only SQL witness, before any write.
  $r=$pdo->query("SELECT current_database() db,session_user login,current_user actor,
   host(inet_server_addr()) host,inet_server_port()::text port,current_setting('server_version_num') version,
   pg_backend_pid()::text pid,pg_postmaster_start_time()::text started,
   (SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()) born,
   current_setting('transaction_isolation') isolation,
   rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
   OR EXISTS(SELECT FROM pg_auth_members WHERE member=r.oid) unsafe
   FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
  return is_array($r)&&self::witnessMatches($r,$expected,self::ROLES[$slot],$s['sql']);
 }
 /** Pure pre-write witness comparison, also exercised without a database. */
 public static function witnessMatches(array $r,array $expected,string $role,array $w):bool {
  if(!is_int($expected['port']??null)||$expected['port']<1||$expected['port']>65535
   ||($expected['host']??null)!=='127.0.0.1'||($r['host']??null)!=='127.0.0.1'
   ||(string)($r['port']??'')!==(string)$expected['port']
   ||($r['db']??null)!==($expected['database']??null)||($r['login']??null)!==$role||($r['actor']??null)!==$role
   ||(int)($r['version']??0)<180000||(int)($r['version']??0)>=190000||($r['isolation']??null)!=='read committed'
   ||!in_array($r['unsafe']??null,[false,'f',0,'0'],true))return false;
  ksort($r);ksort($w);return $r===$w;
 }

 public function run():array {
  if($this->used)throw new RuntimeException('ONESHOT_ALREADY_USED');$this->used=true;
  $r=$this->context->currentReceipt();if(!is_array($r))throw new RuntimeException('LAB_RECEIPT_REQUIRED');
  $db=[];
  foreach(self::ROLES as $slot=>$role){$p=$this->context->connection($slot)->getRawPdo();if(!$p instanceof PDO||!$this->check($slot,$p,$r))throw new RuntimeException('LAB_DESTINATION_REFUSED');$db[$slot]=$p;}
  if(count(array_unique(array_map('spl_object_id',$db)))!==3)throw new RuntimeException('LAB_IDENTITY_REFUSED');
  $slots=$r['slots'];
  $pids=array_map(static fn(array $s):string=>(string)$s['sql']['pid'],$slots);
  if(count($pids)!==3||count(array_unique($pids))!==3||count(array_filter($pids,static fn(string $pid):bool=>ctype_digit($pid)&&(int)$pid>0))!==3)throw new RuntimeException('LAB_BACKENDS_REFUSED');
  if($slots['finance']['config']['database']!==$slots['entry']['config']['database']
   ||$slots['inbox']['config']['database']===$slots['entry']['config']['database'])throw new RuntimeException('LAB_DATABASES_REFUSED');
  foreach(['finance','entry'] as $s)if($slots[$s]['sql']['started']!==$slots['inbox']['sql']['started']||$slots[$s]['sql']['port']!==$slots['inbox']['sql']['port'])throw new RuntimeException('LAB_CLUSTER_REFUSED');
  $t=$r['target'];$target=new Target($t['account'],$t['nativeAccount'],'sandbox',$t['event'],$t['payment'],$t['tenant'],$t['order']);
  // Fixture is independent input attested as synthetic, not made from the binding/ledger.
  $fixture=$r['providerFixture'];if(($fixture['id']??null)!==$target->payment||($fixture['status']??null)!=='RECEIVED')throw new RuntimeException('LAB_FIXTURE_REFUSED');
  $reader=new class($target->payment,$fixture) implements SyntheticPaymentReader {
   public function __construct(private string $id,private array $fixture){}
   public function fetchPayment(string $id):?array{return $id===$this->id?$this->fixture:null;}
  };
  $gate=fn(string $s,PDO $p):bool=>$p===$db[$s]&&$this->check($s,$p,$r);
  $financial=new PdoFinancialPort($db['finance'],fn(PDO $p):bool=>$gate('finance',$p),$slots['finance']['config']);
  $snapshot=$financial->inspect_binding($target->nativeAccount,'sandbox',$target->payment);
  if($snapshot===null||!$target->matches($snapshot))throw new RuntimeException('LAB_BINDING_REFUSED');
  $entry=new ServiceAuthorityPort(fn(PDO $p):bool=>$gate('entry',$p),$db['entry']);
  return (new Worker($db['inbox'],fn(PDO $p):bool=>$gate('inbox',$p),$target,$financial,$reader,$entry))->once();
 }
}
