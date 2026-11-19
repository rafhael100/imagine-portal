"""Full base/diff/worktree gate. Independent final bytes/tree are pinned by EXTERNAL review."""
import os,json,hashlib,subprocess
from pathlib import Path
BASE='3ad87dceb6c57ff0e5e63ec15aaf0486bbbc201c'
BASE_TREE='90fbcb41aea66219bf61ca789c30fba6f4dd79b5'
REPO='rafhael100/imagine-portal'
BASE_BRANCH='test/onepage-d0-atomic-ingress-pg-v2'
BRANCH='test/onepage-d0-receipt-finance-pg-v1'
CHANGES={'.github/workflows/onepage-d0-receipt-finance-pg.yml': 'A', 'apps/portal/labs/onepage-d0-reader-ci/preflight.py': 'M', 'apps/portal/labs/onepage-d0-reader-ci/scope.py': 'M', 'apps/portal/labs/onepage-dual-inbox/CaptureHarness.php': 'A', 'apps/portal/labs/onepage-dual-inbox/Ingress.php': 'A', 'apps/portal/labs/onepage-dual-inbox/InstallConnected.php': 'A', 'apps/portal/labs/onepage-dual-inbox/PgFixture.php': 'M', 'apps/portal/labs/onepage-dual-inbox/SharedCatalog.php': 'A', 'apps/portal/labs/onepage-dual-inbox/SharedInboxPort.php': 'A', 'apps/portal/labs/onepage-dual-inbox/approved-ubuntu-receipt.json': 'M', 'apps/portal/labs/onepage-dual-inbox/cases.php': 'M', 'apps/portal/labs/onepage-dual-inbox/composer.py': 'M', 'apps/portal/labs/onepage-dual-inbox/delta.sql': 'A', 'apps/portal/labs/onepage-dual-inbox/inbox_supervisor.py': 'M', 'apps/portal/labs/onepage-dual-inbox/inventories.py': 'M', 'apps/portal/labs/onepage-dual-inbox/observer.php': 'M', 'apps/portal/labs/onepage-dual-inbox/qualification.php': 'M', 'apps/portal/labs/onepage-dual-inbox/qualified-ports.php': 'M', 'apps/portal/labs/onepage-dual-inbox/shared-functions.sql': 'A', 'apps/portal/labs/onepage-dual-inbox/shared-post.sql': 'A', 'apps/portal/labs/onepage-dual-inbox/shared-pre.sql': 'A'}
HARNESS_PINS={'apps/portal/labs/onepage-d0-reader-ci/scope.py': '272806454378711adefb01df8c05fd8e1e56ea1f', 'apps/portal/labs/onepage-dual-inbox/CaptureHarness.php': '7e35155b6fce494f51cee795b609172f8cd253c7', 'apps/portal/labs/onepage-dual-inbox/Ingress.php': '755824b5b2a899e7229ae71461f6f6d8498da5ec', 'apps/portal/labs/onepage-dual-inbox/InstallConnected.php': '050d8433c07888530a065cba3edcb350af59b8fd', 'apps/portal/labs/onepage-dual-inbox/PgFixture.php': 'e2db1852968085f4ab8a2985c6c1792315cd3a51', 'apps/portal/labs/onepage-dual-inbox/SharedCatalog.php': '0f539787138e7cc27afc2e8f6a53c19450837a3f', 'apps/portal/labs/onepage-dual-inbox/SharedInboxPort.php': '2d4498b255879dec9140afae869d58fca11ae9d8', 'apps/portal/labs/onepage-dual-inbox/approved-ubuntu-receipt.json': 'f84ff54e57da2e74fa61f200dbff9a322f8aef4a', 'apps/portal/labs/onepage-dual-inbox/cases.php': '95ab91b028ff4cc99483023f6fb2235aa553b2c0', 'apps/portal/labs/onepage-dual-inbox/composer.py': 'd4794d65c4cd9d4b49df9a02cd2cebe21b087ec4', 'apps/portal/labs/onepage-dual-inbox/delta.sql': '3bc0c116185a5108ef93eec6b84d0071f1a507a8', 'apps/portal/labs/onepage-dual-inbox/inbox_supervisor.py': 'edd101c26fade41ebc9e2a5c0f9a9bf19744f97d', 'apps/portal/labs/onepage-dual-inbox/inventories.py': 'a89857e11c6eb27d7abf0d069f66a52fcf2ff369', 'apps/portal/labs/onepage-dual-inbox/observer.php': '831df4769a17558e44e938dedf1489342e8ccebb', 'apps/portal/labs/onepage-dual-inbox/qualification.php': 'c8e4150349a2dd4ea1fc42e4f52b92911fbb1879', 'apps/portal/labs/onepage-dual-inbox/qualified-ports.php': 'd7907f3153a5d6d780ceb98c7e6dc92d802febbf', 'apps/portal/labs/onepage-dual-inbox/shared-functions.sql': '6e6c25ca897325f5a02dadaf9ee18335084039d1', 'apps/portal/labs/onepage-dual-inbox/shared-post.sql': 'ec9110841a4eddca69d15b25d992832405771c7d', 'apps/portal/labs/onepage-dual-inbox/shared-pre.sql': '021a55fd98fa4ccb89979c1c378577a1248b13df'}
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
