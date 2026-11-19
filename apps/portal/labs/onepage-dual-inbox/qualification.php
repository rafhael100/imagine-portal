<?php
declare(strict_types=1);
use PrivateDualDb\DualDbGate;
use PrivateInboxProtocol\Audit;

$need=static function(bool $ok):void{if(!$ok)throw new RuntimeException('SUPERVISOR_REVIEW_REFUSED');};
foreach(['Audit.php','Compare.php'] as $f)require_once __DIR__.'/'.$f;
$stage=getenv('DUAL_INBOX_STAGE');$data=getenv('PORTAL_NATIVE_ORDER_TEST_DATA');
$hash=getenv('DUAL_INBOX_REVIEW_SHA');
$need(is_string($stage)&&is_string($data)&&$stage===dirname($data).'/inbox-bridge'
 &&realpath($stage)===$stage&&is_string($hash)&&preg_match('/\A[a-f0-9]{64}\z/D',$hash)===1);
$file=$stage.'/supervisor-review.json';$before=lstat($file);
$need(is_array($before)&&!is_link($file)&&is_file($file)&&$before['nlink']===1&&$before['size']<=262144);
$bytes=file_get_contents($file);clearstatcache(true,$file);$after=lstat($file);
$need(is_string($bytes)&&is_array($after)&&$after['ino']===$before['ino']&&$after['dev']===$before['dev']
 &&$after['size']===$before['size']&&hash_equals($hash,hash('sha256',$bytes)));
$review=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
$need(is_array($review)&&array_keys($review)===['model','bridge','finance']);
$model=$review['model'];$anchor=$model['anchor'];
DualDbGate::validateAnchor($anchor);
$audit=new Audit(file_get_contents(__DIR__.'/catalog.sql'),[
 'F391'=>file_get_contents(__DIR__.'/F391.semantic.json'),'F392'=>file_get_contents(__DIR__.'/F392.semantic.json'),
],$model['baseline'],$model['phaseAnchors'],$model['globalExpected']);
$finance=json_encode($review['finance'],JSON_THROW_ON_ERROR);
$financeSha=hash('sha256',$finance);
$q=static function(PDO $admin,PDO $inbox,Closure $nativeRecheck,array $nativeGate)use($need,$anchor,$finance,$financeSha):array {
 $identity=array_intersect_key($anchor['expected'],array_flip(['cluster','pid','data','port','started','address','major','listen','sockets']));
 $identity['nonce']=$anchor['nonce'];
 $native=app('db')->connection('pgsql');$aux=app('db')->connection(DualDbGate::NAME);
 $np=$native->getPdo();$ap=$aux->getPdo();
 $nc=config('database.connections.pgsql');$ac=config('database.connections.'.DualDbGate::NAME);
 $need($np===$nativeGate['pdo']&&$inbox===$ap&&$np!==$inbox&&$admin!==$inbox&&$admin!==$np
  &&is_array($nc)&&is_array($ac));
 $recheck=static function()use($nativeRecheck,$need,$native,$aux,$np,$ap,$nc,$ac,$identity):array {
  $nativeRecheck();
  $need(app('db')->connection('pgsql')===$native&&app('db')->connection(DualDbGate::NAME)===$aux
   &&$native->getPdo()===$np&&$aux->getPdo()===$ap&&config('database.connections.pgsql')===$nc
   &&config('database.connections.'.DualDbGate::NAME)===$ac);
  return $identity; // Pool also compares the facts read from BOTH exact PDOs for every phase.
 };
 return ['admin_pdo'=>$admin,'inbox_pdo'=>$inbox,'native_pdo'=>$np,'anchor'=>$identity,
      'native_config'=>$nc,'inbox_config'=>$ac,'manifest_json'=>$finance,'manifest_sha256'=>$financeSha,'recheck'=>$recheck];
};
return [$review['bridge'],$audit->forSetupBridge(),$q];
