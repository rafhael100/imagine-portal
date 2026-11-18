"""Full base/diff/worktree gate. Independent final bytes/tree are pinned by EXTERNAL review."""
import os,json,hashlib,subprocess
from pathlib import Path
BASE='0e0ccc06755b6ddd938635e5c4c3625ff6497e2c'
BASE_TREE='f283c46b804811771589d9a4d6358e3b68b28188'
REPO='rafhael100/imagine-portal'
BASE_BRANCH='feature/portal-native-onepage-sites-2026-11-18'
BRANCH='test/onepage-d0-two-rehearsals-v1'
FILES=['.github/workflows/onepage-d0-two-rehearsals.yml', 'apps/portal/app/Domain/Commerce/D0/EntryStore.php', 'apps/portal/app/Domain/Commerce/D0/FirstBriefingRelease.php', 'apps/portal/app/Domain/Commerce/D0/UnavailableEntryStore.php', 'apps/portal/app/Http/Controllers/OnepageFirstBriefingController.php', 'apps/portal/app/Infrastructure/Commerce/PostgresD0EntryStore.php', 'apps/portal/app/Providers/OnepageD0CandidateServiceProvider.php', 'apps/portal/bootstrap/providers.php', 'apps/portal/config/portal_onepage_d0.php', 'apps/portal/database/security/portal_onepage_d0_candidate.sql', 'apps/portal/labs/onepage-d0-admission/CiGate.php', 'apps/portal/labs/onepage-d0-admission/D0AdmissionPgTest.php', 'apps/portal/labs/onepage-d0-admission/Peer.php', 'apps/portal/labs/onepage-d0-admission/PgCases.php', 'apps/portal/labs/onepage-d0-admission/offline.php', 'apps/portal/labs/onepage-d0-admission/peer-worker.php', 'apps/portal/labs/onepage-d0-http-ci/CiGate.php', 'apps/portal/labs/onepage-d0-http-ci/Client.php', 'apps/portal/labs/onepage-d0-http-ci/NativeBriefingPgTest.php', 'apps/portal/labs/onepage-d0-http-ci/RouteRebinder.php', 'apps/portal/labs/onepage-d0-http-ci/RunnerBootstrap.php', 'apps/portal/labs/onepage-d0-http-ci/Scenario.php', 'apps/portal/labs/onepage-d0-http-ci/Trace.php', 'apps/portal/labs/onepage-d0-http-ci/TrackedReader.php', 'apps/portal/labs/onepage-d0-http-ci/launch.py', 'apps/portal/labs/onepage-d0-http-ci/preflight.py', 'apps/portal/labs/onepage-d0-http-ci/prepare.py', 'apps/portal/labs/onepage-d0-http-ci/probe.php', 'apps/portal/labs/onepage-d0-http-ci/processes.py', 'apps/portal/labs/onepage-d0-http-ci/root_wrapper.py', 'apps/portal/labs/onepage-d0-http-ci/scope.py', 'apps/portal/labs/onepage-d0-http-ci/worker.php', 'apps/portal/labs/onepage-d0-port-ci/CiGate.php', 'apps/portal/labs/onepage-d0-port-ci/NativeBriefingPgTest.php', 'apps/portal/labs/onepage-d0-port-ci/RunnerBootstrap.php', 'apps/portal/labs/onepage-d0-port-ci/Trace.php', 'apps/portal/labs/onepage-d0-port-ci/launch.py', 'apps/portal/labs/onepage-d0-port-ci/preflight.py', 'apps/portal/labs/onepage-d0-port-ci/prepare.py', 'apps/portal/labs/onepage-d0-port-ci/probe.php', 'apps/portal/labs/onepage-d0-port-ci/processes.py', 'apps/portal/labs/onepage-d0-port-ci/root_wrapper.py', 'apps/portal/labs/onepage-d0-port-ci/scope.py', 'apps/portal/labs/onepage-d0-port-ci/worker.php']
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
 need(f['changes']=={p:('M' if p=='apps/portal/bootstrap/providers.php' else 'A') for p in FILES})
def tree_check(base,head):
 need(set(head)==set(base)|set(FILES))
 for p,v in base.items():
  if p not in FILES:need(head[p]==v)
 for p in FILES:
  need(head[p][0]=='100644' and head[p][1]=='blob')
  if p!='apps/portal/bootstrap/providers.php':need(p not in base)
  else:need(p in base and head[p]!=base[p])
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
