"""One reviewed child. Event SHA binds checkout, external review independently pins SHA/tree/bytes."""
import json,os,subprocess,sys,hashlib
from pathlib import Path
BASE='c8862c0f2528ba2920e91bfe5254c37b7699cd3e'
REPO='rafhael100/imagine-portal'
BRANCH='test/onepage-native-http-briefing-v1'
BASE_BRANCH='test/onepage-briefing-teardown-v1'
LAB='apps/portal/labs/briefing-native-http-ci/'
HOOK='apps/portal/labs/briefing-session-hook/'
MANIFEST=LAB+'additions.json'
FILES={LAB+n for n in ['CiGate.php','Client.php','NativeBriefingPgTest.php','Pipe.php','RunnerBootstrap.php','Scenario.php','Trace.php','additions.json','launch.py','offline.php','prepare.py','preflight.py','processes.py','root_wrapper.py','scope.py','worker.php']}|{HOOK+n for n in ['Caller.php','Channel.php','FrontController.php','Hook.php','ObservedIds.php','Observer.php','load.php','pins.json']}|{'.github/workflows/onepage-native-http-briefing.yml'}
def need(v):
 if not v:raise ValueError('EVENT_SCOPE_REFUSED')
def verify(e,f):
 p=e['pull_request'];need(e['action']=='opened' and e['repository']['full_name']==REPO and p['draft'] is True)
 need(p['head']['repo']['full_name']==REPO and p['base']['repo']['full_name']==REPO)
 need(p['head']['ref']==BRANCH and p['base']['ref']==BASE_BRANCH and p['base']['sha']==BASE)
 need(f['attempt']=='1' and f['head']==p['head']['sha'] and f['parents']==[BASE] and f['clean'])
 need(f['changes']=={n:'A' for n in FILES} and f['modes']=={n:'100644' for n in FILES})
 return 'PASS BRIEFING_EVENT_SCOPE'
def check_bytes(root):
 pins=json.loads((root/MANIFEST).read_text());need(set(pins)==FILES-{MANIFEST})
 for name,digest in pins.items():
  path=root/name;need(path.is_file() and not path.is_symlink() and hashlib.sha256(path.read_bytes()).hexdigest()==digest)
 for name in ('.env','.env.testing','bootstrap/cache/config.php'):
  q=root/'apps/portal'/name;need(not q.exists() and not q.is_symlink())
def check_checkout(root=None):
 root=Path(root or Path(__file__).resolve().parents[4]).resolve()
 def git(*a):return subprocess.check_output(['git',*a],cwd=root,stderr=subprocess.DEVNULL,timeout=5,text=True).strip()
 need(os.environ.get('GITHUB_EVENT_NAME')=='pull_request' and os.environ.get('GITHUB_REPOSITORY')==REPO)
 e=json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text());head=git('rev-parse','HEAD')
 changes={}
 for line in git('diff','--name-status',BASE,head).splitlines():
  status,name=line.split('\t');need(name not in changes);changes[name]=status
 modes={n:git('ls-tree',head,'--',n).split()[0] for n in FILES}
 f={'attempt':os.environ['GITHUB_RUN_ATTEMPT'],'head':head,'parents':git('rev-list','--parents','-n','1',head).split()[1:],
    'clean':git('status','--porcelain')=='','changes':changes,'modes':modes}
 result=verify(e,f);check_bytes(root);return result
if __name__=='__main__':
 try:print(check_checkout())
 except Exception:print('BRIEFING_EVENT_REFUSED');sys.exit(1)
