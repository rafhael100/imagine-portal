from pathlib import Path
import tempfile,subprocess,json,hashlib,sys,os,signal
D=Path(__file__).resolve().parent
php,ini,original=map(Path,sys.argv[1:]);fixed=D/'Peer.php'
env=dict(os.environ);env['PHP_INI_SCAN_DIR']=''
def run(args):
 p=subprocess.Popen(args,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,start_new_session=True)
 try:
  out,err=p.communicate(timeout=8)
 except BaseException:
  os.killpg(p.pid,signal.SIGKILL);p.communicate();raise
 return subprocess.CompletedProcess(args,p.returncode,out,err)
# Real constructor, real proc_open and PHP child; never load CiGate or PDO.
child='''<?php
$v=json_decode(fgets(STDIN),true,16,JSON_THROW_ON_ERROR);
$expected=['cluster_id'=>'18446744073709551615','port'=>54321,'major'=>18,'started'=>'123.123456','data'=>'synthetic-path'];
if($v!==['identity'=>$expected])exit(4);
MODE
while($s=fgets(STDIN)) { $v=json_decode($s,true,16,JSON_THROW_ON_ERROR); if($v===['command'=>'stop'])exit(0); exit(5); }
exit(6);
'''
main='''<?php
require __DIR__.'/Peer.php';
set_error_handler(static function(int $n,string $s):never{throw new ErrorException('SANITIZED_WARNING');});
try{
$p=new \\TxBoundaryFixture\\Peer(['cluster_id'=>'18446744073709551615','port'=>54321,'major'=>18,'started'=>'123.123456','data'=>'synthetic-path']);
if($p->backend!==12345)exit(7);$p->close();echo "READY_CLOSED\\n";
}catch(Throwable $e){
if($e instanceof Error && str_contains($e->getMessage(),'uninitialized non-nullable property') && str_contains($e->getMessage(),'::$pipes'))echo "UNINITIALIZED_PIPES\\n";
elseif($e instanceof RuntimeException && in_array($e->getMessage(),['PEER_REFUSED','PEER_CLEANUP_REFUSED'],true))echo "PROTOCOL_REFUSED\\n";
else{echo "UNEXPECTED_REFUSAL\\n";exit(8);}
}
'''
with tempfile.TemporaryDirectory(prefix='peer-constructor-') as tmp:
 t=Path(tmp)
 (t/'main.php').write_text(main)
 modes=[('baseline',original,'echo "{\\"kind\\":\\"ready\\",\\"backend\\":12345}\\n";','UNINITIALIZED_PIPES'),('fixed',fixed,'echo "{\\"kind\\":\\"ready\\",\\"backend\\":12345}\\n";','READY_CLOSED'),('pid_string',fixed,'echo "{\\"kind\\":\\"ready\\",\\"backend\\":\\"12345\\"}\\n";','PROTOCOL_REFUSED'),('eof',fixed,'exit(0);','PROTOCOL_REFUSED')]
 for label,source,mode,expect in modes:
  (t/'Peer.php').write_bytes(source.read_bytes());(t/'peer-worker.php').write_text(child.replace('MODE',mode))
  p=run([str(php),'-c',str(ini),str(t/'main.php')])
  assert p.returncode==0 and p.stderr==b'' and p.stdout.decode().strip()==expect,(label,p.returncode)
  print('PASS',label.upper())
 for p in [original,fixed]:
  r=run([str(php),'-c',str(ini),'-l',str(p)]);assert r.returncode==0
m=json.loads((fixed.parent/'additions.json').read_text());assert m['apps/portal/labs/onepage-tx-peer-ci/Peer.php']==hashlib.sha256(fixed.read_bytes()).hexdigest()
assert original.read_text().replace('private array $pipes;','private array $pipes=[];')==fixed.read_text()
print('PASS LINT_2 MANIFEST MINIMAL_DELTA; NO_SQL_NO_TOPOLOGY_NO_NETWORK')
