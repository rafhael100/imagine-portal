"""Full base/diff/worktree gate. Independent final bytes/tree are pinned by EXTERNAL review."""
import os,json,hashlib,subprocess
from pathlib import Path
BASE='b5f614044fcaaef633d0051cc1322b96c0b2fffd'
BASE_TREE='3787a9363f54e36ec5443840c90e86d13bd91485'
REPO='rafhael100/imagine-portal'
BASE_BRANCH='test/onepage-d0-receipt-finance-pg-v1'
BRANCH='test/onepage-service-receipt-pg-v1'
CHANGES={'.github/workflows/onepage-service-receipt-pg.yml': 'A', 'apps/portal/labs/onepage-service-receipt-ci/ServiceBindingFixture.php': 'A', 'apps/portal/labs/onepage-service-receipt-ci/ServiceFinancialPort.php': 'A', 'apps/portal/labs/onepage-service-receipt-ci/ServiceGate.php': 'A', 'apps/portal/labs/onepage-service-receipt-ci/ServiceHarness.php': 'A', 'apps/portal/labs/onepage-service-receipt-ci/ServiceReceiptPgTest.php': 'A', 'apps/portal/labs/onepage-service-receipt-ci/attest_service.py': 'A', 'apps/portal/labs/onepage-service-receipt-ci/preflight.py': 'A'}
HARNESS_PINS={'apps/portal/labs/onepage-service-receipt-ci/ServiceBindingFixture.php': '23aa763fc576ed62012a3fb0e3f8850e9269c2bf', 'apps/portal/labs/onepage-service-receipt-ci/ServiceFinancialPort.php': '6706943155d79317c60277d7672cd6657709c5d5', 'apps/portal/labs/onepage-service-receipt-ci/ServiceGate.php': '9d4fbb418914115ac4a2b61a192faf8293cf5924', 'apps/portal/labs/onepage-service-receipt-ci/ServiceHarness.php': '1503b636d6a1d9ceb108057c4271d2ce39319e6f', 'apps/portal/labs/onepage-service-receipt-ci/ServiceReceiptPgTest.php': 'a2c131b4e2a7cadf591795b1c3a44a7d9ed438a0', 'apps/portal/labs/onepage-service-receipt-ci/attest_service.py': 'de3828d0ad370210da48e7917cca4d3df92c2ca3'}
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
