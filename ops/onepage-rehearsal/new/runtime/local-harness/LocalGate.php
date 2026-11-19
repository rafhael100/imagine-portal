<?php
declare(strict_types=1);
namespace PrivateOnepageOneShot;
use PDO;
use Illuminate\Database\Connection;
use RuntimeException;
final class LocalGate {
 const BOOT='onepage_bootstrap_synthetic';
 const PORT=55484;
 const INSTALLER='portal_native_test_bootstrap';
 private array $slots=[];
 private PDO $control;
 public function __construct(private readonly array $w) {
  self::need(($w['mode']??null)==='dedicated-disposable-synthetic'&&($w['port']??null)===self::PORT&&is_int($w['expiresAt']??null)&&$w['expiresAt']>time()&&$w['expiresAt']<=time()+900);
  self::need(is_string($w['dataDirectory']??null)&&preg_match('~^/tmp/onepage-[A-Za-z0-9_.-]+/data$~D',$w['dataDirectory'])===1&&realpath($w['dataDirectory'])===$w['dataDirectory']);
  self::need(is_string($w['systemIdentifier']??null)&&ctype_digit($w['systemIdentifier'])&&is_string($w['postmasterPid']??null)&&ctype_digit($w['postmasterPid'])&&is_string($w['started']??null)&&preg_match('/^[0-9]+\.[0-9]{6}$/D',$w['started'])===1);
  self::need(($w['inboxDatabase']??null)==='portal_asaas_ci'&&preg_match('/^onepage_isolated_native_[a-f0-9]{12}$/D',$w['nativeDatabase']??'')===1);
  $this->control=$this->open('control','postgres',self::BOOT);
  $this->before($this->control);
  self::need($this->control->query("SELECT string_agg(datname,',' ORDER BY datname) FROM pg_database")->fetchColumn()==='postgres,template0,template1');
  self::need($this->control->query("SELECT string_agg(rolname,',' ORDER BY rolname) FROM pg_roles WHERE rolname NOT LIKE 'pg\\_%'")->fetchColumn()===self::BOOT);
  $this->empty($this->control);
 }
 static function need(bool $b):void {if(!$b)throw new RuntimeException('LOCAL_CLUSTER_REFUSED');}
 public function nativeName():string{return $this->w['nativeDatabase'];}
 public function inboxName():string{return $this->w['inboxDatabase'];}
 public function control():PDO{return $this->control;}
 private function disk():void {
  self::need(time()<$this->w['expiresAt']&&realpath($this->w['dataDirectory'])===$this->w['dataDirectory']);
  $file=$this->w['dataDirectory'].'/postmaster.pid';
  self::need(is_file($file)&&!is_link($file)&&filesize($file)<4096);
  $r=file($file,FILE_IGNORE_NEW_LINES);
  self::need(is_array($r)&&count($r)>=8&&$r[0]===$this->w['postmasterPid']&&$r[1]===$this->w['dataDirectory']&&(int)$r[2]===(int)$this->w['started']&&$r[3]===(string)self::PORT&&$r[4]===''&&$r[5]==='127.0.0.1'&&trim($r[7])==='ready');
 }
 public function open(string $slot,string $db,string $role):PDO {
  self::need(!isset($this->slots[$slot])&&preg_match('/^[a-z0-9_]+$/D',$slot)===1);
  $ok=['postgres'=>[self::BOOT],$this->nativeName()=>[self::INSTALLER,'portal_browser_runtime','portal_onepage_runtime','portal_onepage_publisher','portal_onepage_psp_registrar','portal_onepage_d0_verifier','onepage_finance_lab_verifier','onepage_d0_service_b'],$this->inboxName()=>[self::INSTALLER,'onepage_ingress_capture','asaas_reconcile_worker']];
  self::need(in_array($role,$ok[$db]??[],true));$this->disk();
  $name=$slot==='native'?'pgsql':'local_oneshot_'.$slot;
  $cfg=['driver'=>'pgsql','host'=>'127.0.0.1','port'=>self::PORT,'database'=>$db,'username'=>$role,'password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable'];
  config(['database.connections.'.$name=>$cfg,'database.default'=>'pgsql']);app('db')->purge($name);
  $c=app('db')->connection($name);
  $this->configuration($c,$name,$cfg);
  $p=$c->getPdo();self::need($p instanceof PDO&&$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql');
  $this->slots[$slot]=['name'=>$name,'connection'=>$c,'config'=>$cfg,'sql'=>null,'pdo'=>$p];
  self::need(is_array($this->read($slot)));
  return $p;
 }
 private function configuration(Connection $c,string $name,array $cfg):void {
  self::need(config('database.default')==='pgsql'&&config('database.connections.'.$name)===$cfg&&app('db')->connection($name)===$c);
  foreach($cfg as $k=>$v)self::need($c->getConfig($k)===$v);
  foreach(['url','read','write','unix_socket','options'] as $k)self::need(empty($c->getConfig($k)));
 }
 public function read(string $slot):array {
  $this->disk();$s=$this->slots[$slot];$p=$s['pdo'];$cfg=$s['config'];
  $this->configuration($s['connection'],$s['name'],$cfg);self::need($s['connection']->getRawPdo()===$p);
  $r=$p->query("SELECT current_database() db,session_user login,current_user actor,
   host(inet_server_addr()) host,inet_server_port()::text port,current_setting('server_version_num') version,
   pg_backend_pid()::text pid,pg_postmaster_start_time()::text started,
   (SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()) born,
   current_setting('transaction_isolation') isolation,
   rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
   OR EXISTS(SELECT FROM pg_auth_members WHERE member=r.oid) unsafe
   FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
  self::need(is_array($r)&&$r['db']===$cfg['database']&&$r['login']===$cfg['username']&&$r['actor']===$r['login']&&$r['host']==='127.0.0.1'&&$r['port']===(string)self::PORT&&(int)$r['version']>=180000&&(int)$r['version']<190000&&$r['isolation']==='read committed');
  if(!in_array($cfg['username'],[self::BOOT,self::INSTALLER],true)) {
   self::need(in_array($r['unsafe'],[false,'f',0,'0'],true));
   $q=$this->control->prepare('SELECT pg_postmaster_start_time()::text started,backend_start::text born,datname,usename FROM pg_stat_activity WHERE pid=?');
   $q->execute([$r['pid']]);
   self::need($q->fetch(PDO::FETCH_ASSOC)===['started'=>$r['started'],'born'=>$r['born'],'datname'=>$r['db'],'usename'=>$r['login']]);
  }
  self::need($s['sql']===null||$s['sql']===$r);
  $this->slots[$slot]['sql']=$r;return $r;
 }
 public function before(PDO $p):void {
  $slot=null;foreach($this->slots as $name=>$s)if($s['pdo']===$p)$slot=$name;
  self::need($slot!==null&&!$p->inTransaction());$r=$this->read($slot);self::need(in_array($r['login'],[self::BOOT,self::INSTALLER],true));
  $facts=$p->query("SELECT system_identifier::text id,current_setting('data_directory') data,extract(epoch FROM pg_postmaster_start_time())::text started,current_setting('listen_addresses') listen,current_setting('unix_socket_directories') sockets FROM pg_control_system()")->fetch(PDO::FETCH_ASSOC);
  self::need($facts===['id'=>$this->w['systemIdentifier'],'data'=>$this->w['dataDirectory'],'started'=>$this->w['started'],'listen'=>'127.0.0.1','sockets'=>'']);
 }
 public function empty(PDO $p):void {
  $this->before($p);
  self::need((int)$p->query("SELECT (SELECT count(*) FROM pg_namespace WHERE nspname NOT IN ('pg_catalog','pg_toast','public','information_schema'))+(SELECT count(*) FROM pg_class WHERE relnamespace='public'::regnamespace)+(SELECT count(*) FROM pg_proc WHERE pronamespace='public'::regnamespace)")->fetchColumn()===0);
 }
 public function connection(string $slot):Connection{return $this->slots[$slot]['connection'];}
 public function config(string $slot):array{return $this->slots[$slot]['config'];}
 public function current(string $slot):bool {try{$this->read($slot);return true;}catch(\Throwable){return false;}}
 public function expiry():int{return $this->w['expiresAt'];}
}
