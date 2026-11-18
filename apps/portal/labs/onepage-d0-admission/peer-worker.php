<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');require __DIR__.'/CiGate.php';
function frame():array{$r=[STDIN];$w=null;$e=null;if(stream_select($r,$w,$e,20)!==1)throw new RuntimeException();$s=fgets(STDIN,4098);if(!is_string($s)||!str_ends_with($s,"\n")||strlen($s)>4096)throw new RuntimeException();$v=json_decode($s,true,32,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException();return $v;}
function emit(array $v):void{echo json_encode($v,JSON_THROW_ON_ERROR)."\n";}
try{
 $v=frame()['identity'];NativeBriefingFixture\CiGate::topology();$role=$v['role'];if(!in_array($role,['portal_onepage_d0_verifier','portal_onepage_psp_registrar'],true))throw new RuntimeException();
 $port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');if(!preg_match('/\A[0-9]{4,5}\z/D',$port))throw new RuntimeException();
 $db=new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=portal_native_orders_ci;connect_timeout=3',$role,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $id=$db->query("SELECT session_user,current_user,current_database() db,host(inet_server_addr()) addr,inet_server_port() port,extract(epoch FROM pg_postmaster_start_time()) started")->fetch(PDO::FETCH_ASSOC);
 if($id['session_user']!==$role||$id['current_user']!==$role||$id['db']!=='portal_native_orders_ci'||$id['addr']!=='127.0.0.1'||(string)$id['port']!==$port||$id['started']!==$v['started'])throw new RuntimeException();
 $db->exec("SET statement_timeout='12s'; SET lock_timeout='10s'");emit(['kind'=>'ready','backend'=>(int)$db->query('SELECT pg_backend_pid()')->fetchColumn()]);
 while(true){$c=frame();if($c===['command'=>'stop'])exit(0);if($c!==['command'=>'execute'])throw new RuntimeException();emit(['kind'=>'started']);
  $sql=$role==='portal_onepage_d0_verifier'?'SELECT portal_onepage.d0_admit(?,?::uuid,?,?::jsonb,?,?,?::timestamptz)':'SELECT portal_onepage.revoke_entry_payment(?::uuid,?)';$q=$db->prepare($sql);$q->execute($v['args']);emit(['kind'=>'result','value'=>$q->fetchColumn()]);
 }
}catch(Throwable){emit(['kind'=>'refused']);exit(1);}
