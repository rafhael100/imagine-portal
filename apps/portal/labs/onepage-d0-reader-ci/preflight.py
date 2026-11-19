"""Full base/diff/worktree gate. Independent final bytes/tree are pinned by EXTERNAL review."""
import os,json,hashlib,subprocess
from pathlib import Path
BASE='38b27035ac7d08d67e5f7cd77e104b1b0d77288e'
BASE_TREE='b99962e6720f0781dc9e3cd5c78f70cad6ec43e0'
REPO='rafhael100/imagine-portal'
BASE_BRANCH='test/onepage-d0-composed-pg-v1'
BRANCH='test/onepage-d0-atomic-ingress-pg-v1'
CHANGES={'apps/portal/labs/onepage-atomic-ingress-ci/atomic_supervisor.py': 'A', 'apps/portal/labs/onepage-atomic-ingress-ci/harness.php': 'A', 'apps/portal/labs/onepage-atomic-ingress-ci/Fixture.php': 'A', 'apps/portal/labs/onepage-atomic-ingress-ci/AtomicInboxPort.php': 'A', 'apps/portal/labs/onepage-atomic-ingress-ci/Ingress.php': 'A', 'apps/portal/labs/onepage-atomic-ingress-ci/pre.sql': 'A', 'apps/portal/labs/onepage-atomic-ingress-ci/post.sql': 'A', 'apps/portal/labs/onepage-atomic-ingress-ci/atomic-capture.candidate.sql': 'A', 'apps/portal/labs/onepage-d0-reader-ci/scope.py': 'M', 'apps/portal/labs/onepage-d0-reader-ci/preflight.py': 'M', '.github/workflows/onepage-d0-atomic-ingress-pg.yml': 'A'}
HARNESS_PINS={'apps/portal/labs/onepage-atomic-ingress-ci/atomic_supervisor.py': '31b809e8d95c3647e32850eade5cc81543110837', 'apps/portal/labs/onepage-atomic-ingress-ci/harness.php': '5183166cd4dfc9bd86da6e7d27a3c0447ccfe96a', 'apps/portal/labs/onepage-atomic-ingress-ci/Fixture.php': '8f063ed46233ffaa54aa200e5bcb77673b28ea01', 'apps/portal/labs/onepage-atomic-ingress-ci/AtomicInboxPort.php': '54fd2085c96e345c55daef03d091164fe1a07cba', 'apps/portal/labs/onepage-atomic-ingress-ci/Ingress.php': '755824b5b2a899e7229ae71461f6f6d8498da5ec', 'apps/portal/labs/onepage-atomic-ingress-ci/pre.sql': '940bf7b5305dcb379c9361c43d80774d76095c07', 'apps/portal/labs/onepage-atomic-ingress-ci/post.sql': '726d16e08154df1784a4b039e0d1df1b74a3c5f8', 'apps/portal/labs/onepage-atomic-ingress-ci/atomic-capture.candidate.sql': 'c660b9f22c290d35ec4d743d0def0b5d421aab46', 'apps/portal/labs/onepage-d0-reader-ci/scope.py': 'c85f466fc535e9421e7501202ffea5040029c363'}
FILES=list(CHANGES)
ENV_KEYS=('GITHUB_ACTIONS','GITHUB_EVENT_NAME','GITHUB_REPOSITORY','GITHUB_EVENT_PATH','GITHUB_RUN_ATTEMPT')
def need(v):
 if not v:raise ValueError('D0_ENVELOPE_REFUSED')
def context(env):
 need(all(isinstance(env.get(k),str) and env[k] for k in ENV_KEYS))
 need(env['GITHUB_ACTIONS']=='true' and env['GITHUB_EVENT_NAME']=='pull_request' and env['GITHUB_REPOSITORY']==REPO and env['GITHUB_RUN_ATTEMPT']=='1')
 return {k:env[k] for k in ENV_KEYS}
def verify(e,f):
 p=e['pull_request'];need(e['action']=='opened' and e['repository']['full_name']==REPO and p['draft'] is True and p['state']=='open')
 need(p['base']['sha']==BASE and p['base']['ref']==BASE_BRANCH and p['head']['ref']==BRANCH)
 need(p['base']['repo']['full_name']==p['head']['repo']['full_name']==REPO)
 need(f['attempt']=='1' and f['head']==p['head']['sha'] and f['parents']==[BASE] and f['clean'] and f['base_tree']==BASE_TREE)
 need(f['changes']==CHANGES)
def tree_check(base,head):
 need(set(head)==set(base)|{p for p,s in CHANGES.items() if s=='A'})
 for p,v in base.items():
  if p not in FILES:need(head[p]==v)
 for p in FILES:
  need(head[p][0]=='100644' and head[p][1]=='blob')
  if p in HARNESS_PINS:need(head[p][2]==HARNESS_PINS[p])
  if CHANGES[p]=='A':need(p not in base)
  else:need(p in base and base[p][0:2]==('100644','blob') and head[p]!=base[p])
def parse(raw):
 out={}
 for entry in raw.split(b'\0'):
  if not entry:continue
  meta,path=entry.split(b'\t',1);mode,kind,sha=meta.decode().split();p=path.decode();need(p not in out);out[p]=(mode,kind,sha)
 return out
def check_checkout():
 root=Path(__file__).resolve().parents[4];env=context(os.environ)
 def git(*args):return subprocess.check_output(['git',*args],cwd=root,stderr=subprocess.DEVNULL,timeout=8)
 need(git('cat-file','-t',BASE).strip()==b'commit')
 e=json.loads(Path(env['GITHUB_EVENT_PATH']).read_text());head=git('rev-parse','HEAD').decode().strip()
 changes={}
 for line in git('diff','--name-status',BASE,head).decode().splitlines():
  status,name=line.split('\t');need(name not in changes);changes[name]=status
 f={'attempt':env['GITHUB_RUN_ATTEMPT'],'head':head,'parents':git('rev-list','--parents','-n','1',head).decode().split()[1:],
 'clean':git('status','--porcelain','--untracked-files=all')==b'','changes':changes,'base_tree':git('rev-parse',BASE+'^{tree}').decode().strip()}
 verify(e,f);tree_check(parse(git('ls-tree','-rz',BASE)),parse(git('ls-tree','-rz',head)))
 for p in FILES:
  file=root/p;need(file.is_file() and not file.is_symlink())
  if p in HARNESS_PINS:
   b=file.read_bytes();need(hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()==HARNESS_PINS[p])
 for p in ['.env','.env.testing','bootstrap/cache/config.php']:
  file=root/'apps/portal'/p;need(not file.exists() and not file.is_symlink())
if __name__=='__main__':
 try:check_checkout();print('PASS D0_ENVELOPE_SCOPE')
 except BaseException:print('D0_ENVELOPE_REFUSED');raise SystemExit(1)
