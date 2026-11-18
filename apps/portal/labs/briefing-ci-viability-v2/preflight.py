"""Event/parent/exact additions. Independent byte approval is external, not event SHA."""
import json,os,subprocess,sys
from pathlib import Path
BASE='0e0ccc06755b6ddd938635e5c4c3625ff6497e2c'
REPO='rafhael100/imagine-portal'
BRANCH='test/onepage-briefing-ci-viability-v2'
BASE_BRANCH='feature/portal-native-onepage-sites-2026-11-18'
LAB='apps/portal/labs/briefing-ci-viability-v2/'
FILES={LAB+n for n in ['preflight.py','supervisor.py','root_wrapper.py','probe.php','offline.php','checks.py','README.md']}|{'.github/workflows/onepage-briefing-ci-viability-v2.yml'}
def verify(e,f):
 def need(v):
  if not v:raise ValueError('EVENT_SCOPE_REFUSED')
 p=e['pull_request'];need(e['action']=='opened' and e['repository']['full_name']==REPO and p['draft'] is True)
 need(p['head']['repo']['full_name']==REPO and p['base']['repo']['full_name']==REPO)
 need(p['head']['ref']==BRANCH and p['base']['ref']==BASE_BRANCH and p['base']['sha']==BASE)
 need(f['attempt']=='1' and f['head']==p['head']['sha'] and f['parents']==[BASE] and f['clean'])
 need(f['changes']=={n:'A' for n in FILES} and f['modes']=={n:'100644' for n in FILES})
 return 'PASS VIABILITY_EVENT_SCOPE'
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
 print(verify(e,f))
if __name__=='__main__':
 try:main()
 except Exception:print('VIABILITY_EVENT_REFUSED');sys.exit(1)
