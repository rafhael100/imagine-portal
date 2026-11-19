<?php
declare(strict_types=1);
namespace PrivateDualDb;
use Closure;use PDO;use RuntimeException;
final class SetupBridge {
 private static function need(bool $v):void{if(!$v)throw new RuntimeException('DUAL_SETUP_REFUSED');}
 private static function equal(mixed $a,mixed $b):bool{
  if(is_array($a)&&is_array($b)){if(!array_is_list($a))ksort($a);if(!array_is_list($b))ksort($b);
   if(array_keys($a)!==array_keys($b))return false;
   foreach($a as $k=>$v)if(!self::equal($v,$b[$k]))return false;return true;}
  return $a===$b;
 }
 private static function facts(PDO $p):array{
  self::need(!$p->inTransaction());
  self::need($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql');
  $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
  $r=$p->query(DualDbGate::SQL)->fetch(PDO::FETCH_ASSOC);
  self::need(in_array($r['fresh']??null,[true,'t',1,'1'],true));
  return json_decode($r['facts'],true,64,JSON_THROW_ON_ERROR);
 }
 private static function verify(PDO $p,array $expected):void{self::need(self::equal(self::facts($p),$expected));}
 // m contains reviewed catalog deltas, never observations blessed from the target.
 public static function run(PDO $admin,array $nativeGate,Closure $nativeRecheck,array $m,array $files,Closure $semanticAudit,Closure $qualification):Closure{
  foreach(['initial','database_created','owner_created','empty','inbox','leased','post_native','post_binding'] as $k)self::need(isset($m[$k])&&is_array($m[$k]));
  InboxInstall::pinned($files);
  $stage=getenv('DUAL_INBOX_STAGE');$data=getenv('PORTAL_NATIVE_ORDER_TEST_DATA');
  self::need(is_string($stage)&&is_string($data)&&$stage===dirname($data).'/inbox-bridge');
  self::need(is_dir($stage)&&!is_link($stage)&&realpath($stage)===$stage);
  // Both original #962 gates have already passed; this repeats the admin check BEFORE setup only.
  $nativeRecheck();
  self::need(\NativeBriefingFixture\CiGate::adminBeforeWrites($admin,$nativeGate)===$admin);
  $initial=self::facts($admin);$expected=[];
  $vary=['database','databases','roles','memberships','empty','postgres_safe'];
  foreach(['initial','database_created','owner_created','empty','inbox','leased','post_native','post_binding'] as $phase){
   $delta=$m[$phase];$keys=array_keys($delta);sort($keys);$allowed=$vary;sort($allowed);self::need($keys===$allowed);
   $expected[$phase]=array_replace($initial,$delta);
  }
  self::need($expected['initial']['database']==='portal_native_orders_ci'&&$expected['initial']['empty']===true);
  self::need($expected['initial']['postgres_safe']===null);
  self::need($expected['database_created']['postgres_safe']===null);
  self::need($expected['owner_created']['postgres_safe']===true);
  $nonce=bin2hex(random_bytes(32));
  $anchor=['phase'=>'asaas_empty_owner_ready','nonce'=>$nonce,'expected'=>$expected['empty']];
  // Transport owns the staged preamble, includes and independent inventory.
  // The Laravel worker publishes only the exact fixture SQL files, never a proof/anchor.
  DualDbGate::validateAnchor($anchor);
  self::verify($admin,$expected['initial']);
  $nativeRecheck();
  $admin->exec('CREATE DATABASE portal_asaas_ci');
  $nativeRecheck();self::verify($admin,$expected['database_created']);
  $admin->exec('CREATE ROLE postgres NOLOGIN NOSUPERUSER NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
  $post=function()use($admin,$nativeRecheck,$expected,$anchor):array{
   $nativeRecheck();self::verify($admin,$expected['owner_created']);return $anchor;
  };
  $dual=DualDbGate::bind($anchor,$post);$dual->beforeDdl();
  $request=['files'=>[]];
  foreach($files as $name=>$bytes){
   self::need(!file_exists($stage.'/'.$name));
   self::need(file_put_contents($stage.'/'.$name,$bytes)===strlen($bytes));
   self::need(chmod($stage.'/'.$name,0400));$request['files'][$name]=hash('sha256',$bytes);
  }
  self::need(file_put_contents($stage.'/ready.tmp',json_encode($request,JSON_THROW_ON_ERROR))!==false);
  self::need(rename($stage.'/ready.tmp',$stage.'/ready'));
  $deadline=hrtime(true)+30000000000;
  while(!is_file($stage.'/ack')){self::need(hrtime(true)<$deadline);usleep(20000);}
  self::need(!is_link($stage.'/ack')&&file_get_contents($stage.'/ack')==="INSTALLED\n");
  // Original empty-catalog predicates are no longer applicable after F391/F392.
  $cfg=config('database.connections.'.DualDbGate::NAME);
  $reopen=function(string $phase,string $semanticPhase)use($nativeRecheck,$cfg,$expected,$semanticAudit):PDO{
  $nativeRecheck();self::need(config('database.connections.'.DualDbGate::NAME)===$cfg);
  app('db')->purge(DualDbGate::NAME);$connection=app('db')->connection(DualDbGate::NAME);$audit=$connection->getPdo();
  foreach(['driver','host','port','database','username','password','url'] as $k)self::need(($connection->getConfig($k)??null)===($cfg[$k]??null));
  self::verify($audit,$expected[$phase]);
  self::need($semanticAudit($semanticPhase,$audit)===true);
  self::need($connection->getPdo()===$audit);
  return $audit;
  };
  $reopen('leased','F392'); // Independent installation phase.
  // Called later, when the native fixture has created its binding. No suite duplication here.
  return static function(Closure $prepare,Closure $cases)use($admin,$nativeRecheck,$reopen,$qualification,$nativeGate):void{
   $reopen('post_native','POST_NATIVE');
   $fixture=$prepare($admin,$nativeRecheck);
   self::need(is_array($fixture));
   $inbox=$reopen('post_binding','POST_BINDING');
   $q=$qualification($admin,$inbox,$nativeRecheck,$nativeGate);
   self::need(is_array($q));
   $cases($admin,$inbox,$nativeRecheck,$fixture,$q);
  };
 }
}
