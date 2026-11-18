"""Event/tree admission before provisioning. No remote Git operations."""
import json,os,pathlib,re,subprocess,sys
BASE='4464e3220e077118f05928ee20a48f33ea6440ec'
REPOSITORY='rafhael100/imagine-portal'
BASE_BRANCH='test/onepage-confirmation-pg-lab'
HEAD_BRANCH='test/onepage-recovery-loss-composition-v1'
LAB='apps/portal/labs/onepage-recovery-composition-private/'
FILES={'README.md','Recovery.php','attempt-read.sql.inc','cases.php','check.py','first-attempt.php','pins.json',
 'recover-worker.php','run.py','ChildProcess.php','child-check.php','bounded.py','bounded-check.py','preflight.py'}
ALLOWED={LAB+x for x in FILES}|{'.github/workflows/onepage-recovery-loss-pg-lab.yml'}

def validate_event(event,repository,event_name):
    p=event['pull_request'];head=p['head']['sha']
    if(repository!=REPOSITORY or event_name!='pull_request' or event['action']!='opened'
      or p['draft'] is not True or p['commits']!=1
      or p['head']['repo']['full_name']!=REPOSITORY or p['base']['repo']['full_name']!=REPOSITORY
      or p['head']['ref']!=HEAD_BRANCH or p['base']['ref']!=BASE_BRANCH
      or p['base']['sha']!=BASE or re.fullmatch('[0-9a-f]{40}',head) is None):raise ValueError('EVENT_REFUSED')
    return head

def validate_diff(raw):
    chunks=raw.decode().split('\0')
    if chunks[-1]!='':raise ValueError('DIFF_REFUSED')
    chunks=chunks[:-1]
    if len(chunks)!=2*len(ALLOWED) or set(zip(chunks[::2],chunks[1::2]))!={('A',p) for p in ALLOWED}:raise ValueError('ADDITIONS_REFUSED')

def verify(event,repository,event_name):
    head=validate_event(event,repository,event_name)
    git=lambda *args:subprocess.check_output(['git',*args],stderr=subprocess.DEVNULL,timeout=10).decode().strip()
    if git('rev-parse','HEAD')!=head or git('rev-list','--parents','-n','1',head)!=head+' '+BASE:raise ValueError('LINEAGE_REFUSED')
    validate_diff(subprocess.check_output(['git','diff','--name-status','-z',BASE,head],timeout=10))
    for path in ALLOWED:
        if not git('ls-tree',head,'--',path).startswith('100644 blob '):raise ValueError('MODE_REFUSED')
    if git('status','--porcelain','--untracked-files=all'):raise ValueError('DIRTY_REFUSED')
if __name__=='__main__':
    try:
        verify(json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_text()),os.environ['GITHUB_REPOSITORY'],os.environ['GITHUB_EVENT_NAME'])
        print('PASS RECOVERY_EXACT_DRAFT_ADDITIONS')
    except Exception:
        print('RECOVERY_PREFLIGHT_REFUSED',file=sys.stderr);sys.exit(1)
