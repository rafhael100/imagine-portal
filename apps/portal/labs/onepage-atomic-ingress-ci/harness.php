<?php
declare(strict_types=1);
use NativeBriefingFixture\CiGate;
use PrivateDualDb\DualDbGate;
use PrivateAtomicIngressFixture\Fixture;
use PrivateOnepageIngress\{AtomicInboxPort,ApprovedCase};
const A='11111111-1111-4111-8111-111111111111';
const O='22222222-2222-4222-8222-222222222222';
const P='pay_SyntheticOnly';
const R='r1';
const C='onepage_ingress_capture';
const V='onepage_ingress_revoker';
const B='portal_native_test_bootstrap';
const DB='portal_asaas_ci';
function need(bool $ok):void{if(!$ok)throw new RuntimeException('ATOMIC_HARNESS_REFUSED');}
function out($s,array $m):void{$b=json_encode($m,JSON_THROW_ON_ERROR)."\n";need(fwrite($s,$b)===strlen($b));}
function readone($s,int $seconds=5):array{
 stream_set_blocking($s,true);
 $r=[$s];$w=$x=[];need(stream_select($r,$w,$x,$seconds)===1);
 $v=fgets($s,8192);need(is_string($v)&&str_ends_with($v,"\n"));
 return json_decode($v,true,32,JSON_THROW_ON_ERROR);
}
function permit(?int $backend=null):bool {
 out(STDOUT,$backend===null?['check'=>'owned']:['check'=>'backend','backend'=>$backend]);
 return readone(STDIN)===['permit'=>true];
}
function cfg(string $database,string $user):array{
 return ['driver'=>'pgsql','host'=>'127.0.0.1','port'=>55491,'database'=>$database,'username'=>$user,
  'password'=>'','charset'=>'utf8','prefix'=>'','search_path'=>'public','sslmode'=>'disable',
  'options'=>[PDO::ATTR_PERSISTENT=>false]];
}
function slot(string $name,array $cfg):array{
 config(['database.connections.'.$name=>$cfg]);app('db')->purge($name);
 $c=app('db')->connection($name);$p=$c->getPdo();
 need($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
 &&$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION)
 &&$p->setAttribute(PDO::ATTR_EMULATE_PREPARES,false));
 return [$name,$cfg,$c,$p];
}
function facts(array $s):array{
 [$name,$cfg,$c,$p]=$s;need(config('database.connections.'.$name)===$cfg&&app('db')->connection($name)===$c&&$c->getPdo()===$p);
 foreach(['driver','host','port','database','username','password','url'] as $k)need(($c->getConfig($k)??null)===($cfg[$k]??null));
 $r=$p->query(<<<'SQL'
SELECT pg_backend_pid()::int pid,current_database() db,session_user login,current_user actor,
 host(inet_server_addr()) address,host(inet_client_addr()) client,inet_server_port()::int port,
 extract(epoch FROM pg_postmaster_start_time())::text started,
 (current_setting('server_version_num')::int/10000)::int major,
 (SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()) born
SQL)->fetch(PDO::FETCH_ASSOC);
 need(is_array($r)&&$r['db']===$cfg['database']&&$r['login']===$cfg['username']&&$r['actor']===$r['login']
   &&$r['address']==='127.0.0.1'&&$r['client']==='127.0.0.1'&&$r['port']===55491&&$r['major']===18);
 return $r;
}
function verifyPeer(PDO $admin,array $r,array $expected,string $role):void{
 need($r['db']===DB&&$r['login']===$role&&$r['actor']===$role&&$r['started']===$expected['started']
 &&$r['address']==='127.0.0.1'&&$r['client']==='127.0.0.1'&&$r['port']===55491&&$r['major']===18
 &&is_int($r['pid'])&&$r['pid']>1);
 $q=$admin->prepare("SELECT datname,usename,backend_start::text born,host(client_addr) host,backend_type FROM pg_stat_activity WHERE pid=?");
 $q->execute([$r['pid']]);
 need($q->fetch(PDO::FETCH_ASSOC)===['datname'=>DB,'usename'=>$role,'born'=>$r['born'],'host'=>'127.0.0.1','backend_type'=>'client backend']);
 need(permit($r['pid']));
}
function ev(string $suffix,string $type='PAYMENT_CREATED'):array{
 need(preg_match('/\A[a-z0-9]{1,16}\z/D',$suffix)===1);
 return ['event_id'=>'evt_synthetic_'.$suffix,'event_type'=>$type,'payment_id'=>P,'payload_sha256'=>hash('sha256',$suffix.$type)];
}
function capture(PDO $p,array $e,string $revision=R):string{
 $q=$p->prepare('SELECT onepage_ingress_lab.capture(?,?,?,?,?,?,?,?,?)');
 $q->execute([A,O,P,$revision,'sandbox',$e['event_id'],$e['event_type'],P,$e['payload_sha256']]);return $q->fetchColumn();
}
function revoke(PDO $p):bool{
 $q=$p->prepare('SELECT onepage_ingress_lab.revoke(?,?,?,?)');$q->execute([A,O,P,R]);return $q->fetchColumn()===true;
}
function forkcmd($s,array $a):void{out($s,$a);}
function helper():never{
 $h=readone(STDIN);need(array_keys($h)===['role','started']&&in_array($h['role'],[C,V],true));
 $s=slot('atomic_peer',cfg(DB,$h['role']));$p=$s[3];$proof=facts($s);
 need($proof['started']===$h['started']);out(STDOUT,$proof);
 need(readone(STDIN)===['permit'=>true]);
 $m=readone(STDIN);
 need(facts($s)===$proof);
 need(isset($m['op'])&&($m['op']==='capture'&&$h['role']===C||$m['op']==='revoke'&&$h['role']===V));
 need($p->beginTransaction());$p->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='3s'");
 $value=$m['op']==='capture'?capture($p,ev($m['event'],$m['type'])):revoke($p);
 out(STDOUT,['value'=>$value]);$done=readone(STDIN);
 need($done===['commit'=>true]?$p->commit():($done===['commit'=>false]&&$p->rollBack()));
 out(STDOUT,['closed'=>true]);exit(0);
}
function race(PDO $admin,array $exp,int $held,string $role,array $op,Closure $unblock,bool $commit):array{
 $io=[];$child=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'peer'],
          [['pipe','r'],['pipe','w'],['file','/dev/null','a']],$io,__DIR__);
 need(is_resource($child));
 try{
  out($io[0],['role'=>$role,'started'=>$exp['started']]);
  $f=readone($io[1]);verifyPeer($admin,$f,$exp,$role);need($f['pid']!==$held);
  out($io[0],['permit'=>true]);out($io[0],$op);
  $q=$admin->prepare('SELECT CAST(? AS int)=ANY(pg_blocking_pids(CAST(? AS int)))');
  $deadline=hrtime(true)+1200000000;
  do {
   $q->execute([$held,$f['pid']]);$b=$q->fetchColumn();$q->closeCursor();
   if($b===true)break;
   need(hrtime(true)<$deadline);usleep(10000); // poll measured blocking, not assumed order
  }while(true);
  $unblock();
  $r=readone($io[1]);
  out($io[0],['commit'=>$commit]);need(readone($io[1])===['closed'=>true]);
  fclose($io[0]);fclose($io[1]);need(proc_close($child)===0);
  return $r;
 }finally{
  if(is_resource($child)){proc_terminate($child);foreach($io as $fd)if(is_resource($fd))fclose($fd);proc_close($child);}
 }
}
function start(PDO $p):void{need($p->beginTransaction());$p->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='3s'");}
function countInbox(PDO $p):int{return (int)$p->query('SELECT count(*) FROM onepage_ingress_lab.inbox')->fetchColumn();}
function positive():array{
 global $atomicPhase;
 $w=readone(STDIN);
 need(array_keys($w)===['cluster','pid','epoch','data','ini','memberships']&&$w['data']===getenv('PORTAL_NATIVE_ORDER_TEST_DATA')
   &&$w['ini']===php_ini_loaded_file()&&is_array($w['memberships'])&&count($w['memberships'])===3&&permit());
 $atomicPhase='ATOMIC_GATE_MAINT';
 config(['database.default'=>'pgsql']);$main=slot('pgsql',cfg('postgres',B));
 $native=CiGate::beforeWrites('postgres');need($native['pdo']===$main[3]
       &&$native['identity']['cluster_id']===$w['cluster']&&permit(facts($main)['pid']));
 $main[3]->exec('CREATE DATABASE portal_native_orders_ci');
 $atomicPhase='ATOMIC_GATE_NATIVE';
 $main=slot('pgsql',cfg('portal_native_orders_ci',B));
 $n=CiGate::beforeWrites('portal_native_orders_ci');need($n['pdo']===$main[3]&&$n['identity']['cluster_id']===$w['cluster']
     &&permit(facts($main)['pid']));
 $main[3]->exec('CREATE DATABASE portal_asaas_ci');
 $exp=['cluster'=>$w['cluster'],'started'=>(string)$n['identity']['started'],'pid'=>(string)$w['pid'],
   'data'=>$w['data'],'port'=>'55491','address'=>'127.0.0.1','database'=>DB,'login'=>B,'actor'=>B,
   'major'=>'18','listen'=>'127.0.0.1','sockets'=>'',
   'roles'=>[[B,true,true,true,true,true,true,true]],'memberships'=>$w['memberships'],
   'databases'=>[DB,'portal_native_orders_ci','postgres','template0','template1'],'empty'=>true,'postgres_safe'=>null];
 $atomicPhase='ATOMIC_GATE_INBOX';
 $anchor=['phase'=>'asaas_empty_before_owner','nonce'=>bin2hex(random_bytes(32)),'expected'=>$exp];
 $gate=DualDbGate::bind($anchor,static function()use($anchor):array{CiGate::topology();need(permit());return $anchor;});
 $admin=$gate->beforeDdl();need(permit((int)$admin->query('SELECT pg_backend_pid()')->fetchColumn()));
 $bytes=[];foreach(Fixture::PINS as $file=>$hash)$bytes[$file]=file_get_contents(__DIR__.'/'.$file);
 // One intentional rollback of the exact DDL, with real supervisor permission on every request.
 $atomicPhase='ATOMIC_DDL';
 $op=0;$rolled=false;
 try{Fixture::install($gate,$admin,static function()use(&$op):bool{need(permit());return ++$op!==3;},$bytes,true);}
 catch(RuntimeException $e){$rolled=$e->getMessage()==='ATOMIC_FIXTURE_COMMIT_UNCONFIRMED'&&$op===3;}
 need($rolled&&$gate->beforeDdl()===$admin);
 Fixture::install($gate,$admin,static fn():bool=>permit(),$bytes,true);
 // Keep the same exact admin PDO and the same Laravel-effective configuration after DDL.
 $exp['empty']=false;
 foreach([[C,true],[V,true],['onepage_ingress_owner',false]] as [$role,$login])
  $exp['roles'][]=[$role,$login,false,false,false,false,false,false];
 usort($exp['roles'],static fn($a,$b)=>strcmp($a[0],$b[0]));
 $norm=static function(array $v):array{ksort($v);return $v;};
 $verify=static function()use($admin,$gate,$exp,$norm):void {
  need(app('db')->connection(DualDbGate::NAME)->getPdo()===$admin
    &&config('database.connections.'.DualDbGate::NAME)===array_diff_key(cfg(DB,B),['options'=>true])
    &&permit((int)$admin->query('SELECT pg_backend_pid()')->fetchColumn()));
  $r=$admin->query(DualDbGate::SQL)->fetch(PDO::FETCH_ASSOC);
  need(in_array($r['fresh'],[true,1,'1','t'],true)
       &&$norm(json_decode($r['facts'],true,32,JSON_THROW_ON_ERROR))===$norm($exp));
  Fixture::verifyCatalog($admin);
 };
 $atomicPhase='ATOMIC_CATALOG';
 $verify();
 $capture=slot('atomic_capture',cfg(DB,C));$revoker=slot('atomic_revoke',cfg(DB,V));
 $c=$capture[3];$v=$revoker[3];$cf=facts($capture);$vf=facts($revoker);
 verifyPeer($admin,$cf,$exp,C);verifyPeer($admin,$vf,$exp,V);need($cf['pid']!==$vf['pid']);
 $port=new AtomicInboxPort($c,static function(PDO $p)use($capture,$cf,$c,$admin,$exp,$verify):bool{
    $verify();need($p===$c&&facts($capture)===$cf);verifyPeer($admin,$cf,$exp,C);return true;
 },true);
 $case=new ApprovedCase(A,O,P,R);
 $atomicPhase='ATOMIC_ROLLBACK';
 // Rollback / unconfirmed result and no ACK.
 start($c);need(capture($c,ev('rollback'))==='recorded'&&$c->rollBack()&&countInbox($admin)===0);
 need($port->captureCommitted($case,ev('rollback'))==='recorded'&&countInbox($admin)===1);
 $atomicPhase='ATOMIC_COMMIT_LOSS';
 // Attest commit is durable, then deliberately do not deliver the receipt to incoming side.
 $port->captureCommitted($case,ev('lost'));
 need($port->captureCommitted($case,ev('lost'))==='replay'&&countInbox($admin)===2);
 $atomicPhase='ATOMIC_DUPLICATE';
 start($c);need(capture($c,ev('same'))==='recorded');
 need(race($admin,$exp,$cf['pid'],C,['op'=>'capture','event'=>'same','type'=>'PAYMENT_CREATED'],static fn()=>need($c->commit()),true)===['value'=>'replay']);
 need($port->captureCommitted($case,ev('same','PAYMENT_RECEIVED'))==='conflict'&&countInbox($admin)===3);
 $atomicPhase='ATOMIC_CAPTURE_FIRST';
 // Capture-first lock order, then rollback revoke so we can test terminal revoke-first in one fresh boot.
 start($c);need(capture($c,ev('first'))==='recorded');
 need(race($admin,$exp,$cf['pid'],V,['op'=>'revoke'],static fn()=>need($c->commit()),false)===['value'=>true]&&countInbox($admin)===4);
 $atomicPhase='ATOMIC_SCOPE';
 // Wrong authorities before terminal revocation.
 foreach([[O,P,'r2'],['33333333-3333-4333-8333-333333333333',P,R],[O,'pay_SyntheticOther',R]] as [$o,$p,$revision])
   need($port->captureCommitted(new ApprovedCase(A,$o,$p,$revision),ev('wrong'))==='unarmed');
 need($port->captureCommitted(new ApprovedCase('33333333-3333-4333-8333-333333333333',O,P,R),ev('wrong'))==='unarmed');
 $atomicPhase='ATOMIC_REVOKE_FIRST';
 start($v);need(revoke($v));
 need(race($admin,$exp,$vf['pid'],C,['op'=>'capture','event'=>'revoked','type'=>'PAYMENT_CREATED'],static fn()=>need($v->commit()),true)===['value'=>'unarmed']);
 need($port->captureCommitted($case,ev('same'))==='unarmed'&&countInbox($admin)===4);
 $verify();
 return ['ACL','ROLLBACK','COMMIT_LOSS','DUPLICATE','CAPTURE_FIRST','REVOKE_FIRST','SCOPE'];
}
$atomicPhase='ATOMIC_BOOTSTRAP';
try{
 require __DIR__.'/../onepage-d0-port-ci/CiGate.php';
 require __DIR__.'/../onepage-dual-inbox/DualDbGate.php';
 require __DIR__.'/Fixture.php';require __DIR__.'/Ingress.php';require __DIR__.'/AtomicInboxPort.php';
 CiGate::topology();
 require dirname(__DIR__,2).'/vendor/autoload.php';
 $app=require dirname(__DIR__,2).'/bootstrap/app.php';
 $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if($argv=== [__FILE__,'peer'])helper();
 need($argv===[__FILE__,'central']);
 out(STDOUT,['done'=>positive()]);
} catch(Throwable){if(($argv[1]??'')==='central')out(STDOUT,['refused'=>$atomicPhase]);exit(1);}
