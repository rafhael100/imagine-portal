"""Full base/diff/worktree gate. Independent final bytes/tree are pinned by EXTERNAL review."""
import os,json,hashlib,subprocess
from pathlib import Path
BASE='d4884f8066d4d3f8c5ef9c872a2f2025136e10de'
BASE_TREE='0fc5f6c307e6598bcb6e023a4d3ef14639866b41'
REPO='rafhael100/imagine-portal'
BASE_BRANCH='test/onepage-d0-two-rehearsals-v1'
BRANCH='test/onepage-d0-account-epoch-v1'
FILES=['.github/workflows/onepage-d0-account-epoch.yml', 'apps/portal/database/security/portal_onepage_d0_account_epoch.sql', 'apps/portal/labs/onepage-d0-account-epoch-ci/launch.py', 'apps/portal/labs/onepage-d0-account-epoch-ci/preflight.py', 'apps/portal/labs/onepage-d0-account-epoch-ci/prepare.py', 'apps/portal/labs/onepage-d0-account-epoch-ci/probe.php', 'apps/portal/labs/onepage-d0-account-epoch-ci/processes.py', 'apps/portal/labs/onepage-d0-account-epoch-ci/root_wrapper.py', 'apps/portal/labs/onepage-d0-account-epoch-ci/scope.py', 'apps/portal/labs/onepage-d0-account-epoch/AccountEpochPgTest.php', 'apps/portal/labs/onepage-d0-account-epoch/Peer.php', 'apps/portal/labs/onepage-d0-account-epoch/PgCases.php', 'apps/portal/labs/onepage-d0-account-epoch/offline.php', 'apps/portal/labs/onepage-d0-account-epoch/operator-worker.php', 'apps/portal/labs/onepage-d0-account-epoch/worker.php']
ENV_KEYS=('GITHUB_ACTIONS','GITHUB_EVENT_NAME','GITHUB_REPOSITORY','GITHUB_EVENT_PATH','GITHUB_RUN_ATTEMPT')
def need(v):
 if not v:raise ValueError('D0_ENVELOPE_REFUSED')
def context(env):
 need(all(isinstance(env.get(k),str) and env[k] for k in ENV_KEYS))
 need(env['GITHUB_ACTIONS']=='true' and env['GITHUB_EVENT_NAME']=='pull_request' and env['GITHUB_REPOSITORY']==REPO and env['GITHUB_RUN_ATTEMPT']=='1')
 return {k:env[k] for k in ENV_KEYS}
def verify(e,f):
 p=e['pull_request'];need(e['action']=='opened' and e['repository']['full_name']==REPO and p['draft'] is True)
 need(p['base']['sha']==BASE and p['base']['ref']==BASE_BRANCH and p['head']['ref']==BRANCH)
 need(p['base']['repo']['full_name']==p['head']['repo']['full_name']==REPO)
 need(f['attempt']=='1' and f['head']==p['head']['sha'] and f['parents']==[BASE] and f['clean'] and f['base_tree']==BASE_TREE)
 need(f['changes']=={p:'A' for p in FILES})
def tree_check(base,head):
 need(set(head)==set(base)|set(FILES))
 for p,v in base.items():
  if p not in FILES:need(head[p]==v)
 for p in FILES:
  need(head[p][0]=='100644' and head[p][1]=='blob')
  need(p not in base)
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
 for p in ['.env','.env.testing','bootstrap/cache/config.php']:
  file=root/'apps/portal'/p;need(not file.exists() and not file.is_symlink())
if __name__=='__main__':
 try:check_checkout();print('PASS D0_ENVELOPE_SCOPE')
 except BaseException:print('D0_ENVELOPE_REFUSED');raise SystemExit(1)
