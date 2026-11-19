"""Full base/diff/worktree gate. Independent final bytes/tree are pinned by EXTERNAL review."""
import os,json,hashlib,subprocess
from pathlib import Path
BASE='6b713932d90846fcdeacfccee1a4c6ddd1f69620'
BASE_TREE='64a54dfa31b977b3fa80ab6a7b3af14f0e74c373'
REPO='rafhael100/imagine-portal'
BASE_BRANCH='test/onepage-buyer-signal-pg-v1'
BRANCH='test/onepage-d0-finance-pg-v1'
CHANGES={'.github/workflows/onepage-d0-reader-pg.yml': 'M', 'apps/portal/labs/onepage-d0-reader-ci/preflight.py': 'M', 'apps/portal/labs/onepage-d0-reader-pg/ConfiguredReaderPgTest.php': 'M', 'apps/portal/labs/onepage-d0-reader-pg/PgCases.php': 'M', 'apps/portal/labs/onepage-finance-integrated/Financial.php': 'A', 'apps/portal/labs/onepage-finance-integrated/Harness.php': 'A', 'apps/portal/labs/onepage-finance-integrated/PdoFinancialPort.php': 'A', 'apps/portal/labs/onepage-finance-integrated/finance.sql': 'A', 'apps/portal/labs/onepage-finance-integrated/lease.sql': 'A'}
HARNESS_PINS={'apps/portal/labs/onepage-d0-reader-pg/ConfiguredReaderPgTest.php': '23e3663a2bb2fb6f779486fe4a0daf006bb7467c', 'apps/portal/labs/onepage-d0-reader-pg/PgCases.php': '1c13de26a90e62c5aafa57ca753d3fc2385b0511', 'apps/portal/labs/onepage-finance-integrated/Financial.php': 'df330f740adc9f4cb909b10e042735a1b5946610', 'apps/portal/labs/onepage-finance-integrated/Harness.php': 'd42f304d08901238a3a5c5621d5c4ca4ac24e04b', 'apps/portal/labs/onepage-finance-integrated/PdoFinancialPort.php': '3bdb21ca028d86f6e8fd870e460cd962aec54416', 'apps/portal/labs/onepage-finance-integrated/finance.sql': 'c97a37d181e620f8d532b41280b4744a897e984e', 'apps/portal/labs/onepage-finance-integrated/lease.sql': 'c700b43adab31d3da8f4ea62a6623b7a42fd85bc'}
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
 'clean':git('status','--porcelain')==b'','changes':changes,'base_tree':git('rev-parse',BASE+'^{tree}').decode().strip()}
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
