<?php
declare(strict_types=1);
namespace PrivateD0Ports;
use Closure;use PDO;use RuntimeException;use Throwable;
use PrivateDualDb\DualDbGate;
final class Pool {
 private array $ctx,$q,$m,$slots=[];private string $phase='BEFORE_FINANCE';private bool $closed=false;
 private const MAP=[
 'installer'=>['portal_native_orders_ci','portal_native_test_bootstrap'],
 'registrar'=>['portal_native_orders_ci','portal_onepage_psp_registrar'],
 'native_runtime'=>['portal_native_orders_ci','portal_onepage_runtime'],
 'inbox_a'=>['portal_asaas_ci','asaas_reconcile_worker'],
 'inbox_b'=>['portal_asaas_ci','asaas_reconcile_worker'],
 'inbox_audit'=>['portal_asaas_ci','portal_native_test_bootstrap'],
 'native_audit'=>['portal_native_orders_ci','portal_native_test_bootstrap'],
 'ingress_capture'=>['portal_asaas_ci','onepage_ingress_capture'],
 'financial'=>['portal_native_orders_ci','onepage_finance_lab_verifier']];
 private const SELF_SQL="SELECT pg_backend_pid()::text pid,current_database() db,session_user login,current_user actor,host(inet_server_addr()) host,inet_server_port()::text port,extract(epoch FROM pg_postmaster_start_time())::text started,(current_setting('server_version_num')::int/10000)::text major,(SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()) born";
 private static function need(bool $v):void{if(!$v)throw new RuntimeException('QUALIFIED_PORTS_REFUSED');}
 private static function norm(mixed $v):mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map(self::norm(...),$v);}
 private static function same(mixed $a,mixed $b):bool{return self::norm($a)===self::norm($b);}
 public static function manifest(string $bytes,string $hash):array{
  self::need(preg_match('/\A[a-f0-9]{64}\z/D',$hash)===1&&hash_equals($hash,hash('sha256',$bytes)));
  $m=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
  self::need(is_array($m)&&array_keys($m)===['BEFORE_FINANCE','FINANCE_INSTALLED','AFTER_FINANCE','SHARED_INSTALLED']);
  foreach($m as $phase=>$pair){
   self::need(is_array($pair)&&array_keys($pair)===['native','inbox']);
   foreach($pair as $side=>$f){
    $a=['phase'=>'asaas_empty_owner_ready','nonce'=>str_repeat('a',64),'expected'=>$f];
    // Reuse structural identity checks only; actual phases are populated.
    $a['expected']['database']='portal_asaas_ci';$a['expected']['empty']=true;
    DualDbGate::validateAnchor($a);
    self::need($f['database']===($side==='native'?'portal_native_orders_ci':'portal_asaas_ci')&&$f['empty']===false);
   }
   $n=$pair['native'];$i=$pair['inbox'];unset($n['database'],$i['database']);self::need(self::same($n,$i));
  }
  $base=$m['BEFORE_FINANCE']['native'];
  $roles=$base['roles'];foreach($roles as $r)self::need(!in_array($r[0],['onepage_finance_lab_owner','onepage_finance_lab_verifier'],true));
  foreach(['FINANCE_INSTALLED','AFTER_FINANCE'] as $phase){
   $expected=$base;$expected['roles']=$roles;
   $expected['roles'][]=['onepage_finance_lab_owner',false,false,false,false,false,false,false];
   $expected['roles'][]=['onepage_finance_lab_verifier',$phase==='AFTER_FINANCE',false,false,false,false,false,false];
   usort($expected['roles'],fn($a,$b)=>strcmp($a[0],$b[0]));
   self::need(self::same($expected,$m[$phase]['native']));
  }
  $expected=$m['AFTER_FINANCE']['native'];
  foreach(['onepage_ingress_capture','onepage_ingress_owner','onepage_ingress_revoker'] as $n){
   foreach($expected['roles'] as $r)self::need($r[0]!==$n);
   $expected['roles'][]=[$n,$n!=='onepage_ingress_owner',false,false,false,false,false,false];
  }
  usort($expected['roles'],fn($a,$b)=>strcmp($a[0],$b[0]));
  self::need(self::same($expected,$m['SHARED_INSTALLED']['native']));
  return $m;
 }
 public function __construct(array $ctx){
  $this->ctx=$ctx;$this->q=$ctx['qualification']??[];
  self::need(($ctx['recheck']??null) instanceof Closure&&($this->q['recheck']??null) instanceof Closure);
  foreach(['admin_pdo','inbox_pdo','native_pdo'] as $k)self::need(($this->q[$k]??null) instanceof PDO);
  self::need(($ctx['native']??null)===$this->q['admin_pdo']&&($ctx['inbox']??null)===$this->q['inbox_pdo']);
  self::need(is_array($this->q['anchor']??null)&&is_array($this->q['native_config']??null)&&is_array($this->q['inbox_config']??null));
  $this->m=self::manifest($this->q['manifest_json']??'',$this->q['manifest_sha256']??'');
  $anchor=$this->q['anchor'];self::need(is_string($anchor['nonce']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$anchor['nonce'])===1);
  $identity=$this->m['BEFORE_FINANCE']['native'];
  foreach(['cluster','pid','data','port','started','address','major','listen','sockets'] as $k)self::need(($anchor[$k]??null)===$identity[$k]);
  $this->control();
 }
 private function control():void{
  self::need(!$this->closed);($this->ctx['recheck'])();
  self::need(self::same(($this->q['recheck'])(),$this->q['anchor']));
  self::need(config('database.default')==='pgsql'&&config('database.connections.pgsql')===$this->q['native_config']);
  self::need(app('db')->connection('pgsql')->getPdo()===$this->q['native_pdo']);
  self::need(config('database.connections.'.DualDbGate::NAME)===$this->q['inbox_config']);
  self::need(app('db')->connection(DualDbGate::NAME)->getPdo()===$this->q['inbox_pdo']);
  foreach(['native'=>'admin_pdo','inbox'=>'inbox_pdo'] as $side=>$key){
   $p=$this->q[$key];self::need(!$p->inTransaction());
   $r=$p->query(DualDbGate::SQL)->fetch(PDO::FETCH_ASSOC);
   self::need(in_array($r['fresh']??null,[true,'t',1,'1'],true));
   self::need(self::same(json_decode($r['facts'],true,64,JSON_THROW_ON_ERROR),$this->m[$this->phase][$side]));
  }
 }
 private function add(string $label,?PDO $borrowed=null):PDO{
  $this->control();self::need(isset(self::MAP[$label])&&!isset($this->slots[$label]));
  [$db,$role]=self::MAP[$label];$name='dual_ports_'.$label;
  self::need(config('database.connections.'.$name)===null);
  $cfg=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>(int)$this->q['anchor']['port'],'database'=>$db,
   'username'=>$role,'password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable'];
  config(['database.connections.'.$name=>$cfg]);
  try{$c=app('db')->connection($name);}catch(Throwable $e){app('db')->purge($name);config(['database.connections.'.$name=>null]);throw $e;}
  // Bind existing fixture PDOs without reconnecting or changing product objects.
  if($borrowed!==null)$c->setPdo($borrowed);
  $this->slots[$label]=['name'=>$name,'cfg'=>$cfg,'connection'=>$c,'pdo'=>null,'borrowed'=>$borrowed!==null,'identity'=>null];
  $p=$c->getPdo();$this->slots[$label]['pdo']=$p;
  self::need($this->gate($label,$p));return $p;
 }
 public function gate(string $label,PDO $p):bool{
  if($label==='finance_installed'){
   self::need($this->phase==='BEFORE_FINANCE'&&$p===$this->ctx['native']);
   $this->phase='FINANCE_INSTALLED';$this->control();return true;
  }
  $this->control();$s=$this->slots[$label]??null;self::need(is_array($s)&&$s['pdo']===$p);
  self::need(config('database.connections.'.$s['name'])===$s['cfg']);
  self::need(app('db')->connection($s['name'])===$s['connection']&&$s['connection']->getPdo()===$p);
  foreach(['driver','host','port','database','username','password','url'] as $k)self::need(($s['connection']->getConfig($k)??null)===($s['cfg'][$k]??null));
  self::need(!$p->inTransaction()&&$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql');
  self::need($p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION)&&$p->setAttribute(PDO::ATTR_EMULATE_PREPARES,false));
  $r=$p->query(self::SELF_SQL)->fetch(PDO::FETCH_ASSOC);
  self::need(is_array($r)&&$r['db']===$s['cfg']['database']&&$r['login']===$s['cfg']['username']&&$r['actor']===$r['login']);
  foreach(['host'=>'address','port'=>'port','started'=>'started','major'=>'major'] as $k=>$a)self::need($r[$k]===$this->q['anchor'][$a]);
  // Bootstrap independently binds this backend to the SAME attested cluster.
  $q=$this->q['admin_pdo']->prepare("SELECT datname,usename,backend_start::text born,host(client_addr) host,backend_type FROM pg_stat_activity WHERE pid=?");
  $q->execute([$r['pid']]);$b=$q->fetch(PDO::FETCH_ASSOC);
  self::need(is_array($b)&&$b['datname']===$r['db']&&$b['usename']===$r['login']&&$b['born']===$r['born']&&$b['host']==='127.0.0.1'&&$b['backend_type']==='client backend');
  if($s['identity']===null){
   foreach($this->slots as $other=>$v)if($other!==$label&&$v['identity']!==null)self::need($v['identity']['pid']!==$r['pid']);
   $this->slots[$label]['identity']=$r;
  }else self::need($s['identity']===$r);
  self::need($s['connection']->getPdo()===$p);return true;
 }
 public function provide(string $phase,array $ctx):array{
  self::need($ctx['native']===$this->ctx['native']&&$ctx['inbox']===$this->ctx['inbox']);
  if($phase==='BEFORE_FINANCE'){
   self::need($this->phase==='BEFORE_FINANCE'&&$this->slots===[]);
   $this->add('installer',$ctx['native']);
  }elseif($phase==='SHARED_INSTALLED'){
   self::need($this->phase==='AFTER_FINANCE');$this->phase='SHARED_INSTALLED';$this->control();
   $this->add('ingress_capture');
  }else{
   self::need($phase==='AFTER_FINANCE'&&$this->phase==='FINANCE_INSTALLED');
   $this->phase='AFTER_FINANCE';$this->control();
   $this->add('registrar',$ctx['fixture']['registrar']);$this->add('native_runtime',$ctx['runtime']);
   foreach(['inbox_a','inbox_b','inbox_audit','native_audit','financial'] as $label)$this->add($label);
  }
  return ['gate'=>$this->gate(...),'ports'=>array_map(fn($s)=>$s['pdo'],$this->slots),'cleanup'=>$this->close(...)];
 }
 public function close():void{
  if($this->closed)return;$this->closed=true;$ok=true;
  foreach(array_reverse($this->slots) as $s){
   try{if($s['pdo'] instanceof PDO&&$s['pdo']->inTransaction()){
     if($s['borrowed'])$ok=false;else $ok=$s['pdo']->rollBack()&&$ok;
    }
    $s['connection']->setPdo(null);app('db')->purge($s['name']);config(['database.connections.'.$s['name']=>null]);
   }catch(Throwable){$ok=false;}
  }$this->slots=[];self::need($ok);
 }
}
$pool=null;
return static function(string $phase,array $ctx)use(&$pool):array{
 try{
  if($pool===null){if($phase!=='BEFORE_FINANCE')throw new RuntimeException('QUALIFIED_PORTS_REFUSED');$pool=new Pool($ctx);}
  return $pool->provide($phase,$ctx);
 }catch(Throwable $e){if($pool!==null)$pool->close();throw new RuntimeException('QUALIFIED_PORTS_REFUSED');}
};

