<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
require __DIR__.'/CiGate.php';
function frame():array{
    $r=[STDIN];$w=null;$e=null;if(stream_select($r,$w,$e,20)!==1)throw new RuntimeException();
    $line=fgets(STDIN,4098);if(!is_string($line)||!str_ends_with($line,"\n")||strlen($line)>4096)throw new RuntimeException();
    $v=json_decode($line,true,16,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException();return $v;
}
function emit(array $v):void{$s=json_encode($v,JSON_THROW_ON_ERROR)."\n";if(fwrite(STDOUT,$s)!==strlen($s))throw new RuntimeException();}
try{
    $v=frame();if(array_keys($v)!==['identity'])throw new RuntimeException();
    \NativeBriefingFixture\CiGate::topology();
    $port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');if(!preg_match('/\A[0-9]{4,5}\z/D',$port))throw new RuntimeException();
    $db=new PDO('pgsql:host=127.0.0.1;port='.$port.';dbname=portal_native_orders_ci;connect_timeout=3','portal_native_test_bootstrap','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    // Empty-cluster SQL attestation before any fixture DDL. No Laravel connection substituted.
    if(\NativeBriefingFixture\CiGate::attestSql($db,'portal_native_orders_ci')!==$v['identity'])throw new RuntimeException();
    $db->exec("SET statement_timeout='15s'; SET lock_timeout='12s'");
    emit(['kind'=>'ready','backend'=>(int)$db->query('SELECT pg_backend_pid()')->fetchColumn()]);
    $order="'00000000-0000-4000-8000-000000000001'::uuid";
    $sql=['confirm'=>"SELECT tx_boundary_fixture.confirm($order,1,'synthetic-fixed-digest')",
        'revoke'=>"SELECT tx_boundary_fixture.invalidate($order,'revoke')",'mutant'=>'SELECT tx_boundary_fixture.mutant_revoke()',
        'count'=>'SELECT count(*) FROM tx_boundary_fixture.evidence'];
    while(true){
        $v=frame();if(array_keys($v)!==['command']||!is_string($v['command']))throw new RuntimeException();
        if($v['command']==='stop'){if($db->inTransaction())$db->rollBack();exit(0);}
        if(!isset($sql[$v['command']]))throw new RuntimeException();
        emit(['kind'=>'started']);$result=$db->query($sql[$v['command']])->fetchColumn();emit(['kind'=>'result','value'=>$result]);
    }
}catch(Throwable){fwrite(STDOUT,"{\"kind\":\"refused\"}\n");exit(1);}
