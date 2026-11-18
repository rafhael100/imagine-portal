"""Event/parent/exact additions. Independent byte approval is external, not event SHA."""
import json,os,subprocess,sys
from pathlib import Path
BASE='3d222e95627e8153614a44633bad69464ae16446'
REPO='rafhael100/imagine-portal'
BRANCH='test/onepage-briefing-diagnostic-v1'
BASE_BRANCH='test/onepage-briefing-pg-final-v1'
LAB='apps/portal/labs/briefing-diagnostic-ci/'
FILES={LAB+n for n in ['Trace.php', 'CiGate.php', 'Fixture.php', 'NativeBriefingPgTest.php', 'README.md', 'checks.py', 'launch.py', 'offline.php', 'pins.json', 'preflight.py', 'prepare.py', 'processes.py', 'root_wrapper.py', 'scope.py', 'store.sql.in', 'worker.php']}|{'.github/workflows/onepage-briefing-diagnostic.yml'}
def verify(e,f):
 def need(v):
  if not v:raise ValueError('EVENT_SCOPE_REFUSED')
 p=e['pull_request'];need(e['action']=='opened' and e['repository']['full_name']==REPO and p['draft'] is True)
 need(p['head']['repo']['full_name']==REPO and p['base']['repo']['full_name']==REPO)
 need(p['head']['ref']==BRANCH and p['base']['ref']==BASE_BRANCH and p['base']['sha']==BASE)
 need(f['attempt']=='1' and f['head']==p['head']['sha'] and f['parents']==[BASE] and f['clean'])
 need(f['changes']=={n:'A' for n in FILES} and f['modes']=={n:'100644' for n in FILES})
 return 'PASS BRIEFING_EVENT_SCOPE'
def main():
 def git(*a):return subprocess.check_output(['git',*a],stderr=subprocess.DEVNULL,timeout=5,text=True).strip()
 e=json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text());head=git('rev-parse','HEAD')
 changes={}
 for l in git('diff','--name-status',BASE,head).splitlines():
  status,name=l.split('\t');changes[name]=status
 modes={n:git('ls-tree',head,'--',n).split()[0] for n in FILES}
 f={'attempt':os.environ['GITHUB_RUN_ATTEMPT'],'head':head,'parents':git('rev-list','--parents','-n','1',head).split()[1:],'clean':git('status','--porcelain')=='','changes':changes,'modes':modes}
 for name in ('.env','.env.testing','bootstrap/cache/config.php'):
  q=Path('apps/portal')/name
  if q.exists() or q.is_symlink():raise ValueError('AMBIENT_CONFIG_REFUSED')
 if os.environ.get('GITHUB_EVENT_NAME')!='pull_request' or os.environ.get('GITHUB_REPOSITORY')!=REPO:raise ValueError()
 pins=json.loads((Path(__file__).parent/'pins.json').read_text())
 import hashlib
 for name,pin in pins['github_blobs'].items():
  b=Path(name).read_bytes()
  if hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()!=pin['sha']:raise ValueError('SOURCE_PIN_REFUSED')
 for name,digest in pins['preserved'].items():
  if hashlib.sha256((Path(__file__).parent/name).read_bytes()).hexdigest()!=digest:raise ValueError('FIXTURE_PIN_REFUSED')
 print(verify(e,f))
if __name__=='__main__':
 try:main()
 except Exception:print('BRIEFING_EVENT_REFUSED');sys.exit(1)
