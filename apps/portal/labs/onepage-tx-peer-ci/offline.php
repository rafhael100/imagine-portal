<?php
declare(strict_types=1);
require_once __DIR__.'/Peer.php';
$n=0;$ok=static function(bool $v)use(&$n):void{if(!$v)throw new RuntimeException('TX_OFFLINE_REFUSED');$n++;};
$sql=file_get_contents(__DIR__.'/fixture.sql');
$ok(substr_count($sql,'PERFORM pg_advisory_xact_lock(948001,1);')===2);
$ok(!str_contains($sql,'GRANT ')&&!str_contains($sql,'CREATE ROLE')&&!str_contains($sql,'portal_onepage.'));
$confirm=substr($sql,strpos($sql,'CREATE FUNCTION tx_boundary_fixture.confirm'),strpos($sql,'CREATE FUNCTION tx_boundary_fixture.invalidate')-strpos($sql,'CREATE FUNCTION tx_boundary_fixture.confirm'));
$ok(strpos($confirm,'pg_advisory_xact_lock')<strpos($confirm,'SELECT * INTO b')&&strpos($confirm,'SELECT * INTO b')<strpos($confirm,'INSERT INTO'));
$ok(str_contains($confirm,'b.revision IS DISTINCT FROM p_revision')&&str_contains($confirm,'NOT b.active'));
// Exercise the real Peer transport with harmless local subprocesses; never run peer-worker/SQL.
foreach(['normal','invalid','timeout'] as $mode){
 $code='import sys,json,time\nfor line in sys.stdin:\n v=json.loads(line)\n if v["command"]=="stop": break\n if MODE=="timeout": time.sleep(10)\n print(json.dumps({"kind":"started"}),flush=True)\n print(json.dumps({"kind":"result","value":"ok"}) if MODE=="normal" else json.dumps({"kind":"refused"}),flush=True)';
 $code=str_replace(['\\n','MODE'],["\n",json_encode($mode)],$code);
 $proc=proc_open(['/usr/bin/python3','-I','-c',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes,null,['PATH'=>'/usr/bin:/bin','LC_ALL'=>'C']);$ok(is_resource($proc));
 foreach($pipes as $p)stream_set_blocking($p,false);
 $r=new ReflectionClass(\TxBoundaryFixture\Peer::class);$peer=$r->newInstanceWithoutConstructor();
 foreach(['process'=>$proc,'pipes'=>$pipes] as $name=>$value)$r->getProperty($name)->setValue($peer,$value);
 try{
  if($mode==='normal')$ok($peer->call('probe')==='ok');
  else{try{$peer->call('probe');$ok(false);}catch(RuntimeException $e){$ok($e->getMessage()===($mode==='timeout'?'PEER_TIMEOUT':'PEER_REFUSED'));}}
 }finally{if($mode==='timeout'){try{$peer->close();$ok(false);}catch(RuntimeException $e){$ok($e->getMessage()==='PEER_CLEANUP_REFUSED');}}else{$peer->close();}$ok(!is_resource($proc));}
}
echo 'PASS TX_PROTOCOL_OFFLINE '.$n."; REAL_LOCAL_PIPES_NO_SQL\n";
