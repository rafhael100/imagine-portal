<?php
// Disposable runner-only HTTP adapter, not an operational route/bootstrap.
declare(strict_types=1);
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE3_HTTPS_CI')!=='1')exit(1);
$root=getenv('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT');
foreach(['AdministrativeServiceEnvelope','AdministrativeServiceInbox','AdministrativeReadReceiver'] as $file)require $root.'/src/Domain/Administration/'.$file.'.php';
$stateFile=getenv('ADMIN_PHASE3_STATE');
$state=fn()=>json_decode(file_get_contents($stateFile),true,16,JSON_THROW_ON_ERROR);
$s=$state();$db=new PDO($s['dsn'],$s['db_user'],$s['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$resolve=function()use($state){$s=$state();return ['active'=>$s['active'],'secret'=>$s['secret'],'expires_at'=>time()+120,'companies'=>['company_alfa'],'operations'=>['dashboard.read']];};
$receiver=new Imagine\Performance\Administration\AdministrativeReadReceiver(new Imagine\Performance\Administration\AdministrativeServiceInbox($db),
 fn()=>getenv('ADMIN_CI_VERIFIED_PEER'),$resolve,
 fn()=> $state()['active']?str_repeat('a',64):null,
 function($company,$op,$query)use($state,$stateFile){
  if(($query['resource']??null)==='revoke_during_read'){$s=$state();$s['active']=false;file_put_contents($stateFile,json_encode($s,JSON_THROW_ON_ERROR),LOCK_EX);}
  return ['status'=>'unavailable','metrics'=>null];
 },function($event,$company,$op,$id)use($state){file_put_contents($state()['audit'],json_encode([$event,$company,$op,$id])."\n",FILE_APPEND|LOCK_EX);});
try{$response=$receiver->receive(stream_get_contents(STDIN,16385),time());echo json_encode(['status'=>200,'body'=>$response],JSON_THROW_ON_ERROR);}
catch(Throwable){echo json_encode(['status'=>403,'body'=>['error'=>'read_denied']],JSON_THROW_ON_ERROR);}
