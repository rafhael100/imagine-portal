"""Event/tree admission before provisioning. No remote Git operations."""
import json,os,pathlib,re,subprocess,sys
BASE='1b394f9826f623dec02beffcd2528b6a17d0c51e'
REPOSITORY='rafhael100/imagine-portal'
BASE_BRANCH='test/onepage-recovery-loss-composition-v1'
HEAD_BRANCH='test/onepage-d0-anchored-admission-pg-v3'
LAB='apps/portal/labs/onepage-evidence-admission-v3-private/'
CI='apps/portal/labs/onepage-d0-admission-ci-private/'
FILES={'AnchorDDL.php','ChildSession.php','Concurrency.php','ProducerAttestation.php','README.md','admission.sql.in','attestation-check.php','base-pins.json','cases.php','compose.py','concurrent-worker.php','offline.php','setup.php.inc'}
ALLOWED={LAB+x for x in FILES}|{CI+x for x in ['preflight.py','runner.py','checks.py','v3-pins.json']}|{'.github/workflows/onepage-d0-anchored-admission-pg-lab.yml'}

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
    expected=json.loads((pathlib.Path(__file__).parent/'v3-pins.json').read_text())
    if set(expected)!=FILES:raise ValueError('PIN_SET_REFUSED')
    import hashlib
    for name,digest in expected.items():
        if hashlib.sha256(pathlib.Path(LAB+name).read_bytes()).hexdigest()!=digest:raise ValueError('V3_HASH_REFUSED')
    if git('status','--porcelain','--untracked-files=all'):raise ValueError('DIRTY_REFUSED')
if __name__=='__main__':
    try:
        verify(json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_text()),os.environ['GITHUB_REPOSITORY'],os.environ['GITHUB_EVENT_NAME'])
        print('PASS ADMISSION_EXACT_DRAFT_ADDITIONS')
    except Exception:
        print('ADMISSION_PREFLIGHT_REFUSED',file=sys.stderr);sys.exit(1)
