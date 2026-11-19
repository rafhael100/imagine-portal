<?php
declare(strict_types=1);
namespace PrivateServiceReceipt;

use PDO;
use RuntimeException;

final class ServiceGate {
 public const NATIVE='portal_native_orders_ci';
 public const INBOX='portal_asaas_ci';
 public const BOOT='portal_native_test_bootstrap';
 public const DATA='/var/lib/postgresql/18/docker';
 private const PORT=5432;
 private const ROLES=[
  self::NATIVE=>[self::BOOT,'portal_browser_runtime','portal_onepage_psp_registrar','portal_onepage_runtime','portal_onepage_publisher','portal_onepage_d0_verifier','onepage_finance_lab_owner','onepage_finance_lab_verifier'],
  self::INBOX=>[self::BOOT,'postgres','asaas_reconcile_worker','asaas_webhook_runtime','onepage_ingress_owner','onepage_ingress_capture','onepage_ingress_revoker'],
 ];
 private const STOCK=['pg_checkpoint','pg_create_subscription','pg_database_owner','pg_execute_server_program','pg_maintain','pg_monitor','pg_read_all_data','pg_read_all_settings','pg_read_all_stats','pg_read_server_files','pg_signal_autovacuum_worker','pg_signal_backend','pg_stat_scan_tables','pg_use_reserved_connections','pg_write_all_data','pg_write_server_files',self::BOOT];
 private const SELF_SQL=<<<'SQL'
 SELECT pg_backend_pid()::text pid,current_database() db,session_user login,current_user actor,
 host(inet_server_addr()) address,inet_server_port()::text port,host(inet_client_addr()) client,
 extract(epoch FROM pg_postmaster_start_time())::text started,current_setting('server_version_num') version,
 (SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()) born
 SQL;
 private const EMPTY_SQL=<<<'SQL'
 SELECT CASE WHEN
  NOT EXISTS(SELECT FROM pg_namespace WHERE nspname NOT IN ('pg_catalog','pg_toast','public','information_schema'))
  AND NOT EXISTS(SELECT FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public')
  AND NOT EXISTS(SELECT FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public')
  AND NOT EXISTS(SELECT FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname='public')
  AND NOT EXISTS(SELECT FROM pg_default_acl)
  AND (SELECT array_agg(extname ORDER BY extname)=ARRAY['plpgsql'::name] FROM pg_extension)
  AND (SELECT nspowner='pg_database_owner'::regrole FROM pg_namespace WHERE nspname='public')
 THEN 1 ELSE 0 END
 SQL;
 private PDO $native;
 private array $slots=[];
 private function __construct(private readonly array $witness) {}
 private static function need(bool $ok):void {if(!$ok)throw new RuntimeException('SERVICE_GATE_REFUSED');}
 public static function boot(?string $path=null,?string $sha256=null):self {
  $path??=(string)getenv('PORTAL_TEST_PG_WITNESS');
  $sha256??=(string)getenv('PORTAL_TEST_PG_WITNESS_SHA');
  $temp=getenv('RUNNER_TEMP');
  self::need(getenv('GITHUB_ACTIONS')==='true'&&is_string($temp)&&$temp!==''&&realpath($temp)===$temp
   &&dirname($path)===$temp&&preg_match('/\Aonepage-service-[A-Za-z0-9_-]+\.json\z/D',basename($path))===1
   &&preg_match('/\A[a-f0-9]{64}\z/D',$sha256)===1);
  $info=lstat($path);
  self::need(is_array($info)&&!is_link($path)&&is_file($path)&&$info['nlink']===1&&($info['mode']&0777)===0600&&$info['size']<=4096);
  $bytes=file_get_contents($path);clearstatcache(true,$path);$after=lstat($path);
  self::need(is_string($bytes)&&is_array($after)&&$info['ino']===$after['ino']&&$info['dev']===$after['dev']
   &&strlen($bytes)===$info['size']&&hash_equals($sha256,hash('sha256',$bytes)));
  $w=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
  self::validate($w);
  $g=new self($w);
  self::need(app()->environment('testing')&&config('app.debug')===false&&!app()->configurationIsCached());
  $g->native=$g->open(self::BOOT,self::NATIVE,'pgsql');
  $g->beforeDdl($g->native);
  $g->assertEmpty($g->native);
  $g->assertStock();
  return $g;
 }
 public static function validate(array $w):void {
  self::need(array_keys($w)===['service','image','container_started','address','sql','observed','php']
   &&preg_match('/\A[a-f0-9]{64}\z/D',$w['service'])===1
   &&preg_match('/\Asha256:[a-f0-9]{64}\z/D',$w['image'])===1
   &&$w['service']===getenv('PORTAL_TEST_PG_SERVICE_ID')&&$w['php']===PHP_VERSION
   &&preg_match('/\A8\.4\.[0-9]+\z/D',PHP_VERSION)===1);
  self::need(is_string($w['address'])&&self::privateIPv4($w['address']));
  $s=$w['sql'];self::need(is_array($s)&&array_keys($s)===['cluster','started','data','version','db','login','actor','port']);
  foreach(['data'=>self::DATA,'db'=>self::NATIVE,'login'=>self::BOOT,'actor'=>self::BOOT,'port'=>'5432'] as $k=>$v)self::need($s[$k]===$v);
  self::need(preg_match('/\A[1-9][0-9]{15,19}\z/D',$s['cluster'])===1&&preg_match('/\A18[0-9]{4}\z/D',$s['version'])===1
   &&preg_match('/\A[1-9][0-9]*\.[0-9]{6}\z/D',$s['started'])===1);
  $start=strtotime($w['container_started']);
  self::need(is_int($w['observed'])&&$start!==false&&time()>=$w['observed']-1&&time()-$w['observed']<=900
   &&$w['observed']-$start>=0&&$w['observed']-$start<=900&&(float)$s['started']>=$start-1&&(float)$s['started']<=$w['observed']+1);
 }
 private static function privateIPv4(string $ip):bool{
  if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false)return false;
  $b=explode('.',$ip);return $b[0]==='10'||($b[0]==='172'&&(int)$b[1]>=16&&(int)$b[1]<=31)||($b[0]==='192'&&$b[1]==='168');
 }
 public function address():string{return $this->witness['address'];}
 public function native():PDO{return $this->native;}
 public function admin():PDO{return $this->native;}
 private function cfg(string $role,string $db):array {
  self::need(in_array($role,self::ROLES[$db]??[],true));
  return ['driver'=>'pgsql','host'=>'127.0.0.1','port'=>self::PORT,'database'=>$db,'username'=>$role,'password'=>'',
   'charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable',
   'options'=>[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false]];
 }
 private function open(string $role,string $db,string $name):PDO {
  $cfg=$this->cfg($role,$db);
  config(['database.connections.'.$name=>$cfg]);app('db')->purge($name);
  if($name==='pgsql')config(['database.default'=>'pgsql']);
  $c=app('db')->connection($name);
  foreach($cfg as $k=>$v)self::need(($c->getConfig($k)??null)===$v);
  self::need(($c->getConfig('url')??null)===null);
  $p=$c->getPdo();
  self::need($p instanceof PDO&&$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'&&!$p->inTransaction());
  $this->slots[spl_object_id($p)]=['pdo'=>$p,'name'=>$name,'db'=>$db,'role'=>$role,'cfg'=>$cfg,'conn'=>$c,'birth'=>null];
  return $p;
 }
 public function connect(string $role,string $db=self::NATIVE):PDO {
  $this->assertSession($this->native);
  $name='service_gate_'.count($this->slots);self::need(config('database.connections.'.$name)===null);
  $p=$this->open($role,$db,$name);$this->assertSession($p);return $p;
 }
 private function configured(PDO $p):array {
  $s=$this->slots[spl_object_id($p)]??null;
  self::need(is_array($s)&&$s['pdo']===$p&&config('database.default')==='pgsql'
   &&app()->environment('testing')&&config('app.debug')===false&&!app()->configurationIsCached());
  $c=$s['conn'];self::need(config('database.connections.'.$s['name'])===$s['cfg']&&app('db')->connection($s['name'])===$c
   &&$c->getPdo()===$p&&$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'&&($c->getConfig('url')??null)===null);
  foreach($s['cfg'] as $k=>$v)self::need(($c->getConfig($k)??null)===$v);
  return $s;
 }
 private function witnessSql(PDO $p):void {
  $r=$p->query("SELECT system_identifier::text cluster,current_setting('data_directory') data,current_setting('server_version_num') version,extract(epoch FROM pg_postmaster_start_time())::text started FROM pg_control_system()")->fetch(PDO::FETCH_ASSOC);
  self::need(is_array($r));
  foreach($r as $k=>$v)self::need($this->witness['sql'][$k]===$v);
  self::need(count($r)===4&&time()-(int)$this->witness['observed']<=900);
 }
 public function assertSession(PDO $p):void {
  $s=$this->configured($p);$base=$this->configured($this->native);
  self::need($base['role']===self::BOOT&&$base['db']===self::NATIVE);
  $this->witnessSql($this->native);
  $r=$p->query(self::SELF_SQL)->fetch(PDO::FETCH_ASSOC);self::need(is_array($r));
  foreach(['db'=>$s['db'],'login'=>$s['role'],'actor'=>$s['role'],'address'=>$this->address(),'port'=>'5432','started'=>$this->witness['sql']['started'],'version'=>$this->witness['sql']['version']] as $k=>$v)self::need(($r[$k]??null)===$v);
  self::need(preg_match('/\A[1-9][0-9]*\z/D',$r['pid'])===1&&is_string($r['born'])&&$r['born']!==''
   &&is_string($r['client'])&&self::privateIPv4($r['client']));
  // Validate the restricted role against the witness-attested admin's backend list.
  $q=$this->native->prepare("SELECT datname,usename,backend_start::text born,host(client_addr) client,backend_type FROM pg_stat_activity WHERE pid=?");
  $q->execute([$r['pid']]);$b=$q->fetch(PDO::FETCH_ASSOC);
  self::need(is_array($b)&&$b===['datname'=>$s['db'],'usename'=>$s['role'],'born'=>$r['born'],'client'=>$r['client'],'backend_type'=>'client backend']&&$q->fetch()===false);
  self::need($s['birth']===null||$s['birth']===$r);
  if($s['birth']===null){foreach($this->slots as $known)self::need(($known['birth']['pid']??null)!==$r['pid']);}
  $this->slots[spl_object_id($p)]['birth']=$r;
  $this->configured($p);
 }
 public function check(PDO $p,string $role,string $db):bool {
  $s=$this->configured($p);self::need($s['role']===$role&&$s['db']===$db);$this->assertSession($p);return true;
 }
 public function beforeDdl(PDO $p,?string $db=null):void {
  $s=$this->configured($p);self::need(!$p->inTransaction()&&$s['role']===self::BOOT&&($db===null||$db===$s['db']));
  $this->assertSession($p);$this->witnessSql($p);
 }
 public function assertEmpty(PDO $p,?string $db=null):void {
  $this->beforeDdl($p,$db);$q=$p->query(self::EMPTY_SQL);
  self::need((string)$q->fetchColumn()==='1'&&$q->fetchColumn()===false);
 }
 private function assertStock():void {
  $roles=$this->native->query("SELECT rolname,rolcanlogin,rolsuper,rolinherit,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls FROM pg_roles ORDER BY rolname COLLATE \"C\"")->fetchAll(PDO::FETCH_NUM);
  $exp=array_map(fn($n)=>$n===self::BOOT?[$n,true,true,true,true,true,true,true]:[$n,false,false,true,false,false,false,false],self::STOCK);
  self::need($roles===$exp);
  $db=$this->native->query("SELECT datname,pg_get_userbyid(datdba),datistemplate,datallowconn FROM pg_database ORDER BY datname COLLATE \"C\"")->fetchAll(PDO::FETCH_NUM);
  self::need($db===[[self::NATIVE,self::BOOT,false,true],['postgres',self::BOOT,false,true],['template0',self::BOOT,true,false],['template1',self::BOOT,true,true]]);
  $members=$this->native->query("SELECT pg_get_userbyid(roleid),pg_get_userbyid(member),pg_get_userbyid(grantor),admin_option,inherit_option,set_option FROM pg_auth_members ORDER BY 1,2,3")->fetchAll(PDO::FETCH_NUM);
  self::need($members===array_map(fn($n)=>[$n,'pg_monitor',self::BOOT,false,true,true],['pg_read_all_settings','pg_read_all_stats','pg_stat_scan_tables']));
 }
}
