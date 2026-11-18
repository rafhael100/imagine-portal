#!/usr/bin/env python3
"""Private composition only. --check never starts PostgreSQL."""
import hashlib,importlib.util,json,os,pathlib,shlex,subprocess,sys,tempfile
from bounded import run as bounded_run
sys.dont_write_bytecode=True
HERE=pathlib.Path(__file__).resolve().parent
PORTAL=HERE.parent.parent
MARKERS=['PASS RECOVERY_ABORT_BEFORE_SEND','PASS RECOVERY_LOST_RESPONSE_ONE_SEND',
 'PASS RECOVERY_ABSENT_AMBIGUOUS_CLOSED','PASS RECOVERY_BIND_ROLLBACK_INVISIBLE',
 'PASS RECOVERY_COMMIT_FRESH_REPLAY_CONFLICT','PASS RECOVERY_PENDING_NO_RELEASE']
REQUIRED=['PASS ORDER_CLEAN_PHP_BEFORE_STARTUP','PASS ORDER_INVALID_CONFIG_DENIED_BEFORE_SQL',
 'PASS ORDER_MAINTENANCE_PREFLIGHT_BEFORE_WRITES','PASS ORDER_BASE_IDENTITY_BEFORE_SCHEMA',
 'PASS ORDER_BASE_PREPARATION_ATTESTED','PASS ORDER_PREFLIGHT_LARAVEL_SQL_ATTESTED','PASS ATOMIC_NATIVE_FIXTURES']+MARKERS+[
 'PASS ORDER_RUNNER_COMPLETE_NO_RELEASE','PASS ORDER_CLUSTER_STOPPED']

def once(text,old,new):
    if text.count(old)!=1:raise ValueError('COMPOSITION_BOUNDARY_CHANGED')
    return text.replace(old,new)

def literal(p):return "'"+str(p).replace('\\','\\\\').replace("'","\\'")+"'"

def verify(code,lines):
    if code or [x for x in lines if x.startswith('PASS ')]!=REQUIRED:raise ValueError('SEQUENCE_REFUSED')

def parent_module():
    m=json.loads((HERE/'pins.json').read_text())
    for path,digest in m['sha256'].items():
        if hashlib.sha256((PORTAL/path).read_bytes()).hexdigest()!=digest:raise ValueError('SOURCE_PIN_DRIFT')
    source=HERE.parent/'onepage-atomic-read/run.py'
    spec=importlib.util.spec_from_file_location('recovery_parent',source)
    parent=importlib.util.module_from_spec(spec);spec.loader.exec_module(parent)
    return parent

def compose(tmp,parent):
    fixture=parent.compose(tmp)
    reserve=(HERE.parent/'onepage-atomic-read/reserve.sql').read_text()
    reserve=once(reserve,'\nCOMMIT;\n','\n'+(HERE/'attempt-read.sql.inc').read_text()+'\nCOMMIT;\n')
    binding=(HERE.parent/'onepage-binding-durable/binding.sql').read_text()
    binding=once(binding,' PRIMARY KEY(order_id,line),',' bound_at timestamptz NOT NULL DEFAULT clock_timestamp(),\n PRIMARY KEY(order_id,line),')
    binding=once(binding,'\nCOMMIT;\n','\n'+(HERE.parent/'onepage-composed-private/bound-read.sql.inc').read_text()+'\nCOMMIT;\n')
    (tmp/'recovery.sql').write_text(reserve+'\n'+binding)
    old=' require '+literal(HERE.parent/'onepage-atomic-read/pg-checks.php')+';\n'+(
     " $count=checkAtomicRead($db,$runtime,$argv[1],$argv[2],$own,requestOrder($sessions['same']),requestOrder($sessions['other']),requestOrder($expired),$order['id']);\n"
     " if($count!==16)throw new RuntimeException('ATOMIC_CASE_COUNT');\n"
     ' echo "PASS ATOMIC_SQL_COMPLETE: 16\\n";')
    new=' require '+literal(HERE/'cases.php')+';\n'+(
     " checkRecoveryComposition($db,$runtime,$owner,$identity,$barrier,$own,requestOrder($sessions['same']),$order['id'],$argv[1],$argv[2],")+literal(tmp/'recovery.sql')+');'
    fixture.write_text(once(fixture.read_text(),old,new))
    worker=tmp/'worker.sh';text=worker.read_text()
    if text.count('"${php_cmd[@]}"')!=5:raise ValueError('WORKER_BOUNDARY_CHANGED')
    text=text.replace('"${php_cmd[@]}"','/usr/bin/python3 '+shlex.quote(str(HERE/'bounded.py'))+' -- "${php_cmd[@]}"')
    worker.write_text(once(text,'trap cleanup EXIT',"trap cleanup EXIT\ntrap 'exit 124' TERM INT"))
    return fixture

def offline(require_php=False):
    parent=parent_module()
    with tempfile.TemporaryDirectory(prefix='onepage-recovery-check-') as root:
        tmp=pathlib.Path(root);fixture=compose(tmp,parent)
        for name in ['launch.sh','worker.sh']:
            subprocess.run(['/bin/bash','-n',str(tmp/name)],check=True,capture_output=True,timeout=10)
        php=os.environ.get('PHP_BIN')
        if php:
            if not pathlib.Path(php).is_absolute():raise ValueError('PHP_REQUIRED')
            manifest=json.loads((HERE/'pins.json').read_text())
            paths={fixture,*HERE.glob('*.php'),*(PORTAL/p for p in manifest['sha256'] if p.endswith('.php'))}
            for path in paths:
                subprocess.run([php,'-n','-l',str(path)],env={'PATH':'/usr/bin:/bin'},check=True,capture_output=True,timeout=15)
            code,data=bounded_run([php,'-n',str(HERE/'child-check.php')],seconds=10,env={'PATH':'/usr/bin:/bin'})
            if code or data!=b'PASS RECOVERY_CHILD_OFFLINE\n':raise ValueError('CHILD_CHECK_REFUSED')
            print('PASS RECOVERY_PHP_LINT_AND_CHILD_CHECK')
        elif require_php:raise ValueError('PHP_REQUIRED')
        else:print('NOT_RUN PHP_LINT_AND_CHILD_CHECK: PHP_BIN unavailable')
    print('PASS RECOVERY_COMPOSITION_OFFLINE')

def main():
    if sys.argv[1:]==['--check']:offline();return
    if sys.argv[1:]!=['--run']:raise ValueError('MODE_REQUIRED')
    allowed={'PATH','LC_ALL','PWD','SHLVL','_','PHP_BIN','PG_BIN','ONEPAGE_PG_RUNTIME_LIB','ONEPAGE_ORDER_ALLOW_LOCAL_START'}
    if set(os.environ)-allowed or os.environ.get('LC_ALL')!='C' or os.environ.get('ONEPAGE_ORDER_ALLOW_LOCAL_START')!='SYNTHETIC_ONLY':raise ValueError('CLEAN_OPT_IN_REQUIRED')
    offline(require_php=True)
    with tempfile.TemporaryDirectory(prefix='onepage-recovery-run-') as root:
        tmp=pathlib.Path(root);compose(tmp,parent_module())
        env={k:v for k,v in os.environ.items() if k in allowed and k not in {'PWD','SHLVL','_'}}
        code,data=bounded_run(['/bin/bash','--noprofile','--norc',str(tmp/'launch.sh')],seconds=300,grace=180,cleanup_shell=True,env=env)
        verify(code,data.decode('utf-8',errors='replace').splitlines())
        for marker in REQUIRED:print(marker)
        print('PASS RECOVERY_RUN_COMPLETE_NO_RELEASE')
if __name__=='__main__':
    try:main()
    except Exception:
        print('RECOVERY_RUN_REFUSED: preflight, execution or cleanup unverified',file=sys.stderr);sys.exit(1)
