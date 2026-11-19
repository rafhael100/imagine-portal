"""Runs only after the #944 root wrapper's namespace and capability drop."""
import sys,os,json,time,tempfile,shutil,signal,stat,re
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent))
from processes import Owned,OWNED
from pathlib import Path
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'onepage-dual-inbox'))
from composer import compose
from inbox_supervisor import collect_with_inbox
from bundle_inputs import read_inputs
ATOMIC_DIR=Path(__file__).resolve().parents[1]/'onepage-atomic-ingress-ci'
sys.path.insert(0,str(ATOMIC_DIR))
from atomic_supervisor import AtomicRefused, run as run_atomic_ingress
HERE=Path(__file__).resolve().parent
WORKER=[b'PASS ATOMIC_INGRESS_CASES']
PHASE_COUNTS={b'ATOMIC_BOOTSTRAP': {0}, b'ATOMIC_DDL': {0}, b'ATOMIC_CATALOG': {0}, b'ATOMIC_ROLLBACK': {0}, b'ATOMIC_COMMIT_LOSS': {0}, b'ATOMIC_DUPLICATE': {0}, b'ATOMIC_CAPTURE_FIRST': {0}, b'ATOMIC_SCOPE': {0}, b'ATOMIC_REVOKE_FIRST': {0}}
def exact(raw,expected):return raw==b'\n'.join(expected)+b'\n'
def worker_failure(raw):
 # Accept only a complete fixed refusal and the exact positive prefix for that phase.
 if not raw.endswith(b'\n'):return None
 lines=raw.splitlines()
 if not lines:return None
 match=re.fullmatch(rb'BRIEFING_WORKER_REFUSED phase=([A-Z_]+)',lines[-1])
 if not match or match[1] not in PHASE_COUNTS:return None
 counts=PHASE_COUNTS
 prefix=lines[:-1]
 if len(prefix) not in counts[match[1]] or prefix!=WORKER[:len(prefix)]:return None
 return lines
def need(v):
 if not v:raise ValueError('SCOPE_REFUSED')
def erase(root,identity):
 s=root.lstat();need((s.st_dev,s.st_ino,s.st_uid)==identity and stat.S_ISDIR(s.st_mode) and not root.is_symlink())
 need(shutil.rmtree.avoids_symlink_attacks);shutil.rmtree(root);return not root.exists()
def main():
 cluster=None;root=None;identity=None;phase='PREFLIGHT';good=False;clean=True
 try:
  need(len(sys.argv)==9);uid,gid,php,ext,pg,lib,ini,mode=sys.argv[1:]
  need(mode in ('PARTIAL_START','BRIEFING'))
  need(os.geteuid()==os.getuid()==int(uid)>0 and os.getegid()==int(gid) and os.environ.get('GITHUB_ACTIONS')=='true')
  env={'PATH':'/usr/bin:/bin','LC_ALL':'C','GITHUB_ACTIONS':'true','PHP_INI_SCAN_DIR':'','BRIEFING_SCOPE':'BRIEFING_VIABILITY_ONLY'}
  probe=HERE/'probe.php'
  cmd=[php,'-n','-d','display_errors=0','-d','log_errors=0']
  if ext!='builtin':cmd+=['-d','extension='+ext]
  child=Owned(cmd+[str(probe),uid,gid],env,pass_fds=(9,));ok,raw,reaped=child.collect(10)
  os.close(9);need(ok and exact(raw,[b'PASS PHP_UID_NAMESPACE_FACTS']))
  print('PASS BRIEFING_UID_TOPOLOGY',flush=True)
  # Same immutable topology, no capabilities, no netns creation possible after drop.
  ns={k:os.readlink('/proc/self/ns/'+k) for k in ('net','mnt','pid')}
  for name in ['postgres','initdb','pg_isready','psql','pg_controldata']:need((Path(pg)/name).is_file())
  env|={'LD_LIBRARY_PATH':lib,'PHP_INI_SCAN_DIR':''}
  phase='INIT';root=Path(tempfile.mkdtemp(prefix='native-briefing-',dir='/tmp'));os.chmod(root,0o700)
  s=root.lstat();identity=(s.st_dev,s.st_ino,s.st_uid);data=root/'data'
  initializer=Owned([pg+'/initdb','-D',str(data),'-U','portal_native_test_bootstrap','--auth-local=reject','--auth-host=trust','--no-locale','-E','UTF8'],env)
  ok,_,reaped=initializer.collect(30,65536)
  clean &= reaped and initializer.p.returncode==0;need(ok)
  # Trust is confined to this synthetic fresh cluster. It does NOT prove credential custody.
  (data/'pg_hba.conf').write_text('host all all 127.0.0.1/32 trust\n')
  phase='START';started=time.time();port='55491'
  cluster=Owned([pg+'/postgres','-D',str(data),'-c','listen_addresses=127.0.0.1','-c','unix_socket_directories=',
    '-c','port='+port,'-c','logging_collector=off','-c','log_statement=none','-c','log_min_messages=panic']+(['-c','briefing_invalid_startup_parameter=on'] if mode=='PARTIAL_START' else []),env,capture=False)
  deadline=time.monotonic()+20
  while True:
   need(cluster.p.poll() is None)
   ready,_,reaped=Owned([pg+'/pg_isready','-h','127.0.0.1','-p',port,'-U','portal_native_test_bootstrap','-d','postgres','-t','1'],env).collect(2)
   clean &= reaped
   if ready:break
   need(time.monotonic()<deadline);time.sleep(.1)
  pidlines=(data/'postmaster.pid').read_text().splitlines()
  need(int(pidlines[0])==cluster.pid and pidlines[1]==str(data) and int(pidlines[3])==int(port))
  need(mode=='BRIEFING')
  print('PASS BRIEFING_OWNED_POSTMASTER_READY',flush=True)
  phase='WORKER'
  worker_env=env|{'APP_ENV':'testing','APP_DEBUG':'false','LOG_CHANNEL':'null','CACHE_STORE':'array','SESSION_DRIVER':'array',
    'QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','PORTAL_NATIVE_ORDER_TEST':'DISPOSABLE_PG18','PORTAL_NATIVE_ORDER_TEST_PORT':port,
    'PORTAL_NATIVE_ORDER_TEST_DATA':str(data),'BRIEFING_START_EPOCH':str(started),'BRIEFING_CI_SCOPE':'REVIEWED_SINGLE_OPENED_PR',
    **{'BRIEFING_NS_'+k.upper():v for k,v in ns.items()}}
  try:
   ok=run_atomic_ingress(ATOMIC_DIR,php,ini,pg,root,cluster,worker_env)
   raw=b'PASS ATOMIC_INGRESS_CASES\n' if ok else b''
   reaped=ok
  except AtomicRefused as exc:
   print('BRIEFING_WORKER_REFUSED phase='+str(exc),flush=True);raise
  except BaseException:
   print('BRIEFING_WORKER_CAPTURE_REFUSED',flush=True);raise
  clean &= reaped
  if not (ok and exact(raw,WORKER)):
   observed=worker_failure(raw) if not ok else None
   if observed is None:print('BRIEFING_WORKER_CAPTURE_REFUSED',flush=True)
   else:
    for line in observed:print(line.decode(),flush=True)
  need(ok and exact(raw,WORKER))
  for line in WORKER:print(line.decode(),flush=True)
  good=True
 except BaseException:good=False
 finally:
  for child in reversed(OWNED):
   if child is cluster:continue
   try:clean &= child.stop()[0]
   except Exception:clean=False
  if cluster is not None:
   try:
    stopped,graceful=cluster.stop()
    # Fast shutdown exit0 proves PostgreSQL itself waited for its backends.
    # Forced postmaster termination alone cannot prove detached descendants absent.
    accepted_exit=cluster.p.returncode==0
    if mode=='PARTIAL_START':
     accepted_exit=cluster.p.returncode==1 and not (data/'postmaster.pid').exists()
    clean &= stopped and graceful and accepted_exit
    good &= graceful
   except Exception:clean=False
  if root is not None and clean:
   try:clean &= erase(root,identity)
   except Exception:clean=False
  if clean:print('PASS BRIEFING_OWNED_CHILDREN_STOPPED_AND_REMOVED',flush=True)
  if good and clean:print('PASS BRIEFING_RUN_COMPLETE',flush=True)
  else:print('BRIEFING_RUN_REFUSED phase='+phase+' cleanup='+('VERIFIED' if clean else 'UNVERIFIED'),flush=True)
 return 0 if good and clean else 1
if __name__=='__main__':
 signal.signal(signal.SIGTERM,lambda *_:(_ for _ in ()).throw(InterruptedError()))
 sys.exit(main())
