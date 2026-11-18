<?php
declare(strict_types=1);
require __DIR__.'/CiGate.php';
use NativeBriefingFixture\CiGate;
$n=0;$ok=static function(bool $v) use(&$n):void {if(!$v)throw new RuntimeException('OFFLINE_REFUSED');$n++;};
$c=['driver'=>'pgsql','host'=>'127.0.0.1','database'=>'postgres','username'=>'portal_native_test_bootstrap','password'=>'','port'=>55491];
$ok(CiGate::configValid($c,'postgres','55491'));
foreach(['host'=>'localhost','driver'=>'sqlite','database'=>'other','username'=>'postgres','password'=>'x','url'=>'pgsql://elsewhere','port'=>5432] as $k=>$v)$ok(!CiGate::configValid(array_replace($c,[$k=>$v]),'postgres','55491'));
$r=['session_user'=>'portal_native_test_bootstrap','current_user'=>'portal_native_test_bootstrap','current_database'=>'postgres','addr'=>'127.0.0.1','port'=>55491,'major'=>18,'data'=>'/tmp/native-briefing-fixture/data','listen'=>'127.0.0.1','sockets'=>'','age'=>2,'created_age'=>3,'started'=>1000];
$ok(CiGate::identityValid($r,'postgres','55491',$r['data'],1000));
foreach(['session_user'=>'other','current_user'=>'other','current_database'=>'other','addr'=>'10.0.0.2','port'=>5432,'major'=>17,'data'=>'/shared/data','listen'=>'*','sockets'=>'/tmp','age'=>-1,'created_age'=>-1,'started'=>900] as $k=>$v)$ok(!CiGate::identityValid(array_replace($r,[$k=>$v]),'postgres','55491',$r['data'],1000));
foreach(['age','created_age'] as $k)$ok(!CiGate::identityValid(array_replace($r,[$k=>301]),'postgres','55491',$r['data'],1000));
echo 'PASS CI_DESTINATION_MODELS '.$n."; NO_SQL_OR_PROC\n";

// Driverless PDO doubles exercise the SAME SQL gate; no socket, driver connection or DDL.
final class GateStatement extends PDOStatement {
    public function __construct(private mixed $value){}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {return $this->value;}
    public function fetchColumn(int $column=0):mixed {return $this->value;}
}
final class GatePdo extends PDO {
    public array $reads=[];
    public function __construct(public array $identity,public array $counts=[0,0,0],public bool $transaction=false){}
    public function inTransaction():bool {return $this->transaction;}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        $this->reads[]=$query;
        return new GateStatement(count($this->reads)===1?$this->identity:$this->counts[count($this->reads)-2]);
    }
    public function exec(string $statement):int|false {throw new LogicException('DDL_FORBIDDEN_OFFLINE');}
}
putenv('PORTAL_NATIVE_ORDER_TEST_PORT=55491');putenv('PORTAL_NATIVE_ORDER_TEST_DATA=/tmp/native-briefing-fixture/data');putenv('BRIEFING_START_EPOCH=1000');
$r['cluster_id']='123456789';
$baseline=new GatePdo($r);$fingerprint=CiGate::attestSql($baseline,'postgres');
$ticket=['pdo'=>$baseline,'database'=>'postgres','identity'=>$fingerprint];
$n=0;
$admin=new GatePdo($r);$ok(CiGate::adminBeforeWrites($admin,$ticket)===$admin);$ok($admin->reads===$baseline->reads && count($admin->reads)===4);
$refused=static function(Closure $f) use($ok):void {try{$f();}catch(RuntimeException){$ok(true);return;}throw new LogicException('ADMIN_MODEL_FAILED');};
foreach(['cluster_id'=>'987654321','started'=>1001,'data'=>'/tmp/native-briefing-other/data','session_user'=>'other','current_user'=>'other','listen'=>'*','sockets'=>'/tmp','age'=>301] as $k=>$v){
 $bad=new GatePdo(array_replace($r,[$k=>$v]));$refused(fn()=>CiGate::adminBeforeWrites($bad,$ticket));
}
foreach([[1,0,0],[0,1,0],[0,0,1]] as $counts)$refused(fn()=>CiGate::adminBeforeWrites(new GatePdo($r,$counts),$ticket));
$transaction=new GatePdo($r,transaction:true);$refused(fn()=>CiGate::adminBeforeWrites($transaction,$ticket));$ok($transaction->reads===[]);
CiGate::sameConnection($baseline,$baseline);$ok(true);
$refused(fn()=>CiGate::sameConnection(new GatePdo($r),$baseline)); // Same facts, different object: refused before SQL.
final class AdminSlot {public readonly PDO $admin;public function __construct(PDO $pdo){$this->admin=$pdo;}}
$slot=new AdminSlot($admin);
try{$slot->admin=new GatePdo($r);throw new LogicException('SWAP_ACCEPTED');}catch(Error){$ok($slot->admin===$admin);}
putenv('PORTAL_NATIVE_ORDER_TEST_PORT');putenv('PORTAL_NATIVE_ORDER_TEST_DATA');putenv('BRIEFING_START_EPOCH');
echo 'PASS ADMIN_PDO_GATE_MODELS '.$n."; DRIVERLESS_NO_SQL_SERVER\n";

require __DIR__.'/Trace.php';
use NativeBriefingFixture\Trace;
$traceChecks=0;
foreach([static fn()=>Trace::enter('CASES'),static fn()=>Trace::enter('UNKNOWN'),static fn()=>Trace::pass('PASS BRIEFING_WORKER_COMPLETE')] as $bad){
    try{$bad();throw new LogicException('TRACE_ACCEPTED');}catch(RuntimeException){$traceChecks++;}
}
ob_start();
foreach(Trace::MARKERS as $i=>$marker){
    $phase=null;foreach(Trace::PHASE_COUNTS as $name=>$counts)if(in_array($i,$counts,true)){$phase=$name;break;}
    Trace::enter($phase);Trace::pass($marker);
}
$traceOutput=ob_get_clean();
if($traceOutput!=='')throw new LogicException('TRACE_OUTPUT_REFUSED');$traceChecks++;
try{Trace::pass(Trace::MARKERS[0]);throw new LogicException('TRACE_REPLAY_ACCEPTED');}catch(RuntimeException){$traceChecks++;}
echo 'PASS PHP_TRACE_ORDER '.$traceChecks."; NO_APPLICATION_BOOT\n";
