#!/usr/bin/env python3
"""Candidate entrypoint. Preparation only here: do not execute --run in this executor."""
import contextlib,hashlib,importlib.util,io,json,os,pathlib,subprocess,sys,tempfile
sys.dont_write_bytecode=True
HERE=pathlib.Path(__file__).resolve().parent
REQUIRED=['PASS ORDER_CLEAN_PHP_BEFORE_STARTUP','PASS ORDER_INVALID_CONFIG_DENIED_BEFORE_SQL',
 'PASS ORDER_MAINTENANCE_PREFLIGHT_BEFORE_WRITES','PASS ORDER_BASE_IDENTITY_BEFORE_SCHEMA',
 'PASS ORDER_BASE_PREPARATION_ATTESTED','PASS ORDER_PREFLIGHT_LARAVEL_SQL_ATTESTED','PASS ATOMIC_NATIVE_FIXTURES',
 'PASS ADMISSION_ANCHORED_ACL_NULL_OTHER_ATTEMPTED_ROLLBACK_CONCURRENT_COMMIT_READBACK_REPLAY',
 'PASS ORDER_RUNNER_COMPLETE_NO_RELEASE','PASS ORDER_CLUSTER_STOPPED']

def load(path,name):
 s=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m

def proof(code,lines):
 actual=[s for s in lines if s.startswith('PASS ')]
 if code!=0 or actual!=REQUIRED:raise ValueError('SEQUENCE_REFUSED')

def diagnostic(phase,lines):
 if phase not in ('SOURCE','OFFLINE','ASSEMBLY','WORKER'):phase='SOURCE'
 actual=[s for s in lines if s.startswith('PASS ')];prefix=0
 for a,b in zip(actual,REQUIRED):
  if a!=b:break
  prefix+=1
 cleanup='VERIFIED' if actual.count(REQUIRED[-1])==1 and actual[-1:]==[REQUIRED[-1]] else 'UNVERIFIED'
 return f'ADMISSION_RUN_REFUSED phase={phase} prefix={prefix} cleanup={cleanup}'

def check(portal,v3,php,lib):
 pins=json.loads((HERE/'v3-pins.json').read_text())
 for name,h in pins.items():
  if hashlib.sha256((v3/name).read_bytes()).hexdigest()!=h:raise ValueError('V3_HASH_REFUSED')
 compose=load(v3/'compose.py','admission_v3_compose');compose.pinned(portal)
 with contextlib.redirect_stdout(io.StringIO()):compose.offline(portal,php,lib)
 env={'PATH':'/usr/bin:/bin','LC_ALL':'C','LD_LIBRARY_PATH':str(lib)}
 paths=json.loads((v3/'base-pins.json').read_text())['blobs']
 inherited=[portal/n for n in paths if n.endswith('.php')]
 for path in inherited:subprocess.run([str(php),'-n','-l',str(path)],env=env,check=True,capture_output=True,timeout=15)
 r=subprocess.run([str(php),'-n',str(v3/'offline.php')],env=env,check=True,capture_output=True,timeout=15)
 if r.stdout!=b'PASS ADMISSION_V3_OFFLINE 19\n':raise ValueError('NEW_OFFLINE_REFUSED')
 print('PASS ADMISSION_CI_OFFLINE_COMPLETE')
 return compose,len(inherited)

def main():
 phase='SOURCE';lines=[]
 try:
  if sys.argv[1:] not in (['--check'],['--run']):raise ValueError('MODE_REQUIRED')
  portal=HERE.parent.parent;v3=portal/'labs/onepage-evidence-admission-v3-private'
  allowed={'PATH','LC_ALL','PWD','SHLVL','_','PHP_BIN','PG_BIN','ONEPAGE_PG_RUNTIME_LIB','ONEPAGE_ORDER_ALLOW_LOCAL_START'}
  if set(os.environ)-allowed or os.environ.get('LC_ALL')!='C':raise ValueError('CLEAN_ENV_REQUIRED')
  php=pathlib.Path(os.environ['PHP_BIN']);lib=pathlib.Path(os.environ['ONEPAGE_PG_RUNTIME_LIB'])
  if not php.is_absolute() or not lib.is_absolute():raise ValueError('TOOL_PATH_REQUIRED')
  phase='OFFLINE';compose,_=check(portal,v3,php,lib)
  if sys.argv[1]=='--check':return
  if os.environ.get('ONEPAGE_ORDER_ALLOW_LOCAL_START')!='SYNTHETIC_ONLY':raise ValueError('OPT_IN_REQUIRED')
  phase='ASSEMBLY'
  bounded=load(portal/'labs/onepage-recovery-composition-private/bounded.py','admission_bounded')
  with tempfile.TemporaryDirectory(prefix='onepage-d0-admission-run-') as root:
   tmp=pathlib.Path(root);compose.assemble(portal,tmp)
   # The exact inherited launcher enforces fresh isolation. No fallback or cluster reuse.
   env={k:v for k,v in os.environ.items() if k in allowed and k not in ('PWD','SHLVL','_')}
   phase='WORKER'
   code,data=bounded.run(['/bin/bash','--noprofile','--norc',str(tmp/'launch.sh')],seconds=300,grace=180,cleanup_shell=True,env=env)
   lines=data.decode('utf-8',errors='replace').splitlines();proof(code,lines)
  for marker in REQUIRED:print(marker)
  print('PASS ADMISSION_RUN_COMPLETE_NO_RELEASE')
 except Exception:
  print(diagnostic(phase,lines),file=sys.stderr);sys.exit(1)
if __name__=='__main__':main()
