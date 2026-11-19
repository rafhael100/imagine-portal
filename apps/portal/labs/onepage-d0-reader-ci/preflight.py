"""Full base/diff/worktree gate. Independent final bytes/tree are pinned by EXTERNAL review."""
import os,json,hashlib,subprocess
from pathlib import Path
BASE='e95baafac952e5fb8577bd4407de76263d11500e'
BASE_TREE='0b22a8efeb696bb38e6494fedd85a35381f4fe3f'
REPO='rafhael100/imagine-portal'
BASE_BRANCH='test/onepage-d0-finance-pg-v2'
BRANCH='test/onepage-d0-composed-pg-v1'
CHANGES={'apps/portal/labs/onepage-dual-inbox/inbox.sql': 'A', 'apps/portal/labs/onepage-dual-inbox/protocol_worker.php': 'A', 'apps/portal/labs/onepage-dual-inbox/SetupBridge.php': 'A', 'apps/portal/labs/onepage-dual-inbox/observer.php': 'A', 'apps/portal/labs/onepage-dual-inbox/F392.semantic.json': 'A', 'apps/portal/labs/onepage-dual-inbox/inventories.py': 'A', 'apps/portal/labs/onepage-dual-inbox/bundle_inputs.py': 'A', 'apps/portal/labs/onepage-dual-inbox/composer.py': 'A', 'apps/portal/labs/onepage-dual-inbox/lease.sql': 'A', 'apps/portal/labs/onepage-dual-inbox/catalog.sql': 'A', 'apps/portal/labs/onepage-dual-inbox/F391.semantic.json': 'A', 'apps/portal/labs/onepage-dual-inbox/InboxInstall.php': 'A', 'apps/portal/labs/onepage-dual-inbox/Compare.php': 'A', 'apps/portal/labs/onepage-dual-inbox/qualified-ports.php': 'A', 'apps/portal/labs/onepage-dual-inbox/Cases.php': 'A', 'apps/portal/labs/onepage-dual-inbox/PgFixture.php': 'A', 'apps/portal/labs/onepage-dual-inbox/Consumer.php': 'A', 'apps/portal/labs/onepage-dual-inbox/qualification.php': 'A', 'apps/portal/labs/onepage-dual-inbox/cases.php': 'A', 'apps/portal/labs/onepage-dual-inbox/Audit.php': 'A', 'apps/portal/labs/onepage-dual-inbox/inbox_supervisor.py': 'A', 'apps/portal/labs/onepage-dual-inbox/DualDbGate.php': 'A', 'apps/portal/labs/onepage-d0-reader-pg/ConfiguredReaderPgTest.php': 'M', 'apps/portal/labs/onepage-d0-reader-pg/BindingFixture.php': 'A', 'apps/portal/labs/onepage-dual-inbox/approved-ubuntu-receipt.json': 'A', 'apps/portal/labs/onepage-dual-inbox/verified-noble-18.7-receipt.json': 'A', 'apps/portal/labs/onepage-d0-reader-ci/scope.py': 'M', 'apps/portal/labs/onepage-d0-reader-ci/reviewed-php-runtime.json': 'A', 'apps/portal/labs/onepage-d0-reader-ci/reviewed-php.ini': 'A', 'apps/portal/labs/onepage-d0-reader-ci/runtime.py': 'A', 'apps/portal/labs/onepage-d0-reader-ci/prepare.py': 'M', 'apps/portal/labs/onepage-d0-reader-ci/preflight.py': 'M', '.github/workflows/onepage-d0-composed-pg.yml': 'A'}
HARNESS_PINS={'apps/portal/labs/onepage-dual-inbox/inbox.sql': '292b23f7416e34e9b6f6b8ebb9dff6d056426f4b', 'apps/portal/labs/onepage-dual-inbox/protocol_worker.php': '81c05eba491e4b7e546304174f442bab898f1a47', 'apps/portal/labs/onepage-dual-inbox/SetupBridge.php': '443c59556056eb41961b36da504cf9696b96e80b', 'apps/portal/labs/onepage-dual-inbox/observer.php': '81a3a20b0b5e6df1f30865b7bc96fb030a634e09', 'apps/portal/labs/onepage-dual-inbox/F392.semantic.json': '84afb878ec470a7a519cb3e015618eea32ea6c2a', 'apps/portal/labs/onepage-dual-inbox/inventories.py': '40cee9efd47e2de4a5b3d5a135ee4c91be01b20c', 'apps/portal/labs/onepage-dual-inbox/bundle_inputs.py': '32089da0c29e2ac05aa455c1cd1e10f521f95590', 'apps/portal/labs/onepage-dual-inbox/composer.py': 'bd9a9f6b182cf680aa6c201c9cb32c5fe9e17613', 'apps/portal/labs/onepage-dual-inbox/lease.sql': '03da8af90eca8733fb1842498fd4ac6b50fe8d7d', 'apps/portal/labs/onepage-dual-inbox/catalog.sql': '4da3fc01fccd780d4fb9020921f5aa773bd2d511', 'apps/portal/labs/onepage-dual-inbox/F391.semantic.json': '289d896702cffbb0de113134f5abcb9d54510aaa', 'apps/portal/labs/onepage-dual-inbox/InboxInstall.php': '7387def68bea18504e62f4bffc3dca45f6c085a0', 'apps/portal/labs/onepage-dual-inbox/Compare.php': '51c294d3b5b8c976dac34bdefe91eadcacc4fc98', 'apps/portal/labs/onepage-dual-inbox/qualified-ports.php': 'b4cf906b74710a5de0294c680592c08c37b9fd64', 'apps/portal/labs/onepage-dual-inbox/Cases.php': '4be15b94c94ac920a510fd3e011f2fef39d69040', 'apps/portal/labs/onepage-dual-inbox/PgFixture.php': '1a569f9038ca327e8a443229cc36e0ade93e84df', 'apps/portal/labs/onepage-dual-inbox/Consumer.php': '57ce83cd8b4a15a1f20799b1b69cf3cec19435d2', 'apps/portal/labs/onepage-dual-inbox/qualification.php': '2ec87aa09042a09cc32f1291ed3e7d63e306edab', 'apps/portal/labs/onepage-dual-inbox/cases.php': 'fbe3990e2f8cbb2923ba23849d6fcae5fdb8179b', 'apps/portal/labs/onepage-dual-inbox/Audit.php': 'f953fdb54998c5e34ab4528ad29de2460d53fe0b', 'apps/portal/labs/onepage-dual-inbox/inbox_supervisor.py': '9d76a3d4e7334057f3892efc6f7dca8316dd503c', 'apps/portal/labs/onepage-dual-inbox/DualDbGate.php': '53feb506a45859aaa29cf21a7d904b1a511a25fb', 'apps/portal/labs/onepage-d0-reader-pg/ConfiguredReaderPgTest.php': '70da1778cf9cd1e40bfcbaf88df03f96a948db50', 'apps/portal/labs/onepage-d0-reader-pg/BindingFixture.php': 'bd7e7865dd22b558c8038ace14d150370cd9f04e', 'apps/portal/labs/onepage-dual-inbox/approved-ubuntu-receipt.json': '33508d36b68f2c759d13e193035d6a4952284579', 'apps/portal/labs/onepage-dual-inbox/verified-noble-18.7-receipt.json': '5485642b6ff5b620e3b60f0c62e043d54446bfb2', 'apps/portal/labs/onepage-d0-reader-ci/scope.py': 'dfd60803f7463c6c488549c8b55d91d980b6ff14', 'apps/portal/labs/onepage-d0-reader-ci/reviewed-php-runtime.json': '98a5759b391b4a55a8489075406c46d385e5fd11', 'apps/portal/labs/onepage-d0-reader-ci/reviewed-php.ini': '8efa76c157f1319b2928702cbd6ac5e24d719d2b', 'apps/portal/labs/onepage-d0-reader-ci/runtime.py': 'f2d5eb98b75ef68668e0c1c066616450c508de72', 'apps/portal/labs/onepage-d0-reader-ci/prepare.py': 'a7a68303e26b58cd3a1de06a07b7932c53508212'}
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
