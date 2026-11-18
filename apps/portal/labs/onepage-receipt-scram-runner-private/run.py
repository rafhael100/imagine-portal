"""Private source draft: exact compose and an opt-in single SCRAM PG18 execution."""
import os,sys,tempfile,subprocess,shlex,socket,signal,re,hashlib,secrets,shutil,selectors,time
from pathlib import Path
sys.dont_write_bytecode=True
HERE=Path(__file__).resolve().parent
LABS=HERE.parent
PARENT=LABS/'onepage-bound-receipt-private'
PROFILE=LABS/'onepage-receipt-scram-profile-private'
ENV={'PATH':'/usr/bin:/bin','LC_ALL':'C'}
MARKER='PASS ONEPAGE_FIXTURE_RECEIPT_INPUT_APPEND_ONLY_NO_PAID_AT'
ROLES=['onepage_lab_bootstrap','portal_browser_runtime','onepage_buyer_runtime',
       'onepage_fixture_receipt_observer','onepage_fixture_receipt_writer']
PINS={
 'onepage-receipt-scram-profile-private/pg_hba.fixture.conf':'9d58831f1a729f0e07a2623be8a57d5c0f5d36bb1831f0df6ab88da8c960d19c',
 'onepage-receipt-scram-profile-private/check_profile.py':'08ac9e6674cd2c8c3c8d2347bc2d4518267edbcec01869ed2884c93999bf3a67',
 'onepage-bound-receipt-private/check.py':'3d97e41bc2e2140c88909982a05e45c6c5c3daf671e9f5ed45d96793fb4805f4',
 'onepage-bound-receipt-durable-private/receipt.sql.inc':'8314d0cfc3f68f77c64938b2f21270b64a3aab84f056d3447e2f2b3d8da8531d',
 'onepage-bound-receipt-durable-private/checkpoint.php':'ce002fe76a3b5956d2c40d5135abd8f9699c5a6ac4f39d08e4f33d25855e8928',
}


def call(args,env=ENV,deadline=30):
    return subprocess.run(list(map(str,args)),env=env,capture_output=True,timeout=deadline,check=True).stdout


def fix(text,old,new):
    if text.count(old)!=1:raise ValueError('EXACT_ANCHOR_REQUIRED')
    return text.replace(old,new)


def literal(path):
    return "'"+str(path).replace("\\","\\\\").replace("'","\\'")+"'"


def private(path,data):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as f:f.write(data)


def close_port():
    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as s:
        s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
        s.bind(('127.0.0.1',55439))


def derive(parent,tmp,php):
    fixture=parent.compose(tmp)
    scratch=tmp/'pg'
    path_auth='require_once '+literal(HERE/'auth.php')+';\n'
    secret=lambda role: '\\Imagine\\Lab\\OnepageReceiptScram\\secret('+literal(tmp)+','+role+')'
    setup='config(["database.connections.pgsql.password"=>'+secret("'onepage_lab_bootstrap'")+']);'
    console='$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
    doc=fixture.read_text()
    doc=fix(doc,console,console+'\n'+path_auth+setup)
    doc=fix(doc,",$role,'',[PDO::ATTR_ERRMODE",",$role,"+secret('$role')+",[PDO::ATTR_ERRMODE")
    fixture.write_text(doc)

    # Native preflight run stays unchanged for the invalid-host path; the
    # only successful prepare path uses protected fixture config in memory.
    original=LABS/'onepage-pending-order/prepare.php'
    prep=original.read_text().replace('__DIR__',literal(original.parent))
    prep=fix(prep,console,console+'\n'+path_auth+setup)
    prep=fix(prep,"[$maintenance]=$verified('postgres');",
        "[$maintenance,$maintOwner]=$verified('postgres');\n    \\Imagine\\Lab\\OnepageReceiptScram\\verifyHba($maintOwner);")
    marker='    echo "PASS ORDER_BASE_PREPARATION_ATTESTED\\n";'
    arm=''.join('    $barrier(); \\Imagine\\Lab\\OnepageReceiptScram\\arm($bootstrap,'+literal(tmp)+','+literal(r)+');\n'
                for r in ROLES[1:3])
    prep=fix(prep,marker,arm+marker)
    generated=tmp/'prepare-scram.php';generated.write_text(prep)

    cas0=LABS/'onepage-atomic-read/reserve-checks.php';cas=tmp/'reserve-scram.php'
    doc=cas0.read_text().replace('__DIR__',literal(cas0.parent))
    doc=fix(doc,",'onepage_buyer_runtime','',",",'onepage_buyer_runtime',"+secret("'onepage_buyer_runtime'")+',')
    cas.write_text(doc)
    hook=tmp/'atomic-plus-binding.php'
    hook.write_text(fix(hook.read_text(),literal(cas0.parent)+".'/reserve-checks.php'",literal(cas)))
    binding=tmp/'bound-binding-checks.php';doc=binding.read_text()
    doc=fix(doc,",'onepage_buyer_runtime','',",",'onepage_buyer_runtime',"+secret("'onepage_buyer_runtime'")+',')
    sql0=LABS/'onepage-bound-receipt-durable-private/receipt.sql.inc';sql=tmp/'receipt-scram.sql'
    body=fix(sql0.read_text(),' evidence jsonb NOT NULL',' observed_at timestamptz NOT NULL,\n evidence jsonb NOT NULL')
    body=fix(body,' AND o.status=',""" AND s.observed_at>=b.bound_at AND s.observed_at<=statement_timestamp()
 AND s.observed_at>=statement_timestamp()-interval '60 seconds'
 AND o.status=""")
    sql.write_text(body)
    hook_marker='        $unknownSource,$readFixtureAt);'
    receipt='\n    \\Imagine\\Lab\\OnepageReceiptScram\\receipt($db,$owner,$identity,$pinned,'+literal(tmp)+','+literal(sql)+''',
       $order,$committedRead,$readFixtureAt,$receiptSource,$pendingSource,$reversedSource);'''
    binding.write_text(fix(doc,hook_marker,hook_marker+receipt))
    # Derived checkpoint adds one safe stale-time control before ANY append.
    checkpoint=LABS/'onepage-bound-receipt-durable-private/checkpoint.php'
    staged=tmp/'receipt-case.php';doc=checkpoint.read_text().replace('__DIR__',literal(checkpoint.parent))
    marker='    $one(3,$received);'
    stale="""    $one(6,$received);
    $late=$read();
    $test($interpret($late)===Observation::MATCHED_RECEIVED_FIXTURE
        &&$append($writer,$late['version'],$late['digest'])==='denied'&&$logState()===$before);
"""
    staged.write_text(fix(doc,marker,stale+marker))
    derived_auth=tmp/'receipt-auth.php'
    authority=fix((HERE/'auth.php').read_text(),"__DIR__.'/../onepage-bound-receipt-durable-private/checkpoint.php'",literal(staged))
    derived_auth.write_text(authority)
    for p in (fixture,generated):
        p.write_text(fix(p.read_text(),literal(HERE/'auth.php'),literal(derived_auth)))
    worker=tmp/'worker.sh';doc=worker.read_text()
    doc=fix(doc,'phar iconv sodium;','phar iconv sodium posix;')
    doc=fix(doc,'trap cleanup EXIT',"trap cleanup EXIT\ntrap 'exit 124' TERM INT HUP")
    doc=fix(doc,'# Never reuse an existing cluster',
            '\"${php_cmd[@]}\" -r \'exit(function_exists("posix_geteuid")?0:2);\'\n# Never reuse an existing cluster')
    doc=fix(doc,'--auth-local=trust --auth-host=trust --no-locale',
            '--auth-local=reject --auth-host=scram-sha-256 --no-locale --pwfile='+shlex.quote(str(tmp/'auth-onepage_lab_bootstrap')))
    hba=tmp/'verified-pg_hba.conf'
    private(hba,(PROFILE/'pg_hba.fixture.conf').read_text())
    start='"$PG_BIN/pg_ctl" -D "$cluster" -l'
    doc=fix(doc,start,'install -m 600 -- '+shlex.quote(str(hba))+' "$cluster/pg_hba.conf"\n'+start)
    doc=fix(doc,'-p 55439 -k $cluster_parent/socket','-p 55439 -k $cluster_parent/socket -c password_encryption=scram-sha-256 -c log_statement=none -c log_min_error_statement=PANIC')
    doc=fix(doc,' "$lab_source/prepare.php" "$cluster" "$PG_BIN/pg_controldata" | tee ',
            ' '+shlex.quote(str(generated))+' "$cluster" "$PG_BIN/pg_controldata" | tee ')
    # No raw PostgreSQL/prepare/worker log files. The single bounded
    # transcript stays in the parent process; the old invalid-host negative
    # keeps its grepable private file, under its own small file-size cap.
    doc=fix(doc,'> "$run_root/initdb.log"','> /dev/null')
    doc=fix(doc,'-l "$run_root/postgres.log"','-l /dev/null')
    for name in ('results.log','orders.log'):
        doc=fix(doc,' | tee "$run_root/'+name+'"','')
    cut='if DB_HOST=localhost '
    doc=fix(doc,cut,'if (ulimit -f 64; DB_HOST=localhost ')
    doc=fix(doc,'> "$run_root/invalid.log" 2>&1; then','> "$run_root/invalid.log" 2>&1); then')
    worker.write_text(doc)
    launch=tmp/'launch.sh'
    launch.write_text(fix(launch.read_text(),'private_root=$(mktemp -d /tmp/onepage-order-clean.XXXXXXXX)',
                           'private_root='+shlex.quote(str(scratch))+'; mkdir -m 700 -- "$private_root"'))
    for p in (fixture,generated,hook,cas,binding,staged,derived_auth):call([php,'-n','-l',p])
    for p in (launch,worker):call(['/bin/bash','-n',p])
    if 'auth-host=trust' in doc or 'auth-local=trust' in doc or doc.count('$cluster/pg_hba.conf')!=1:
        raise ValueError('NO_TRUST_FALLBACK_REQUIRED')
    return launch


def runtime_preflight(php):
    root=LABS.parent.parent.parent
    expected=os.environ.get('SCRAM_REVIEWED_HEAD','')
    if not re.fullmatch('[a-f0-9]{40}',expected) or os.geteuid()==0 or sys.platform!='linux' \
       or os.environ.get('SCRAM_DISPOSABLE_PROFILE')!='APPROVED_SINGLE_CLUSTER_SYNTHETIC' \
       or os.environ.get('GITHUB_ACTIONS')!='true':
        raise ValueError('APPROVED_DISPOSABLE_RUNNER_REQUIRED')
    def git(*a):
        return call(['/usr/bin/git','-C',root,*a],ENV|{'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null'})
    if git('rev-parse','HEAD').strip()!=expected.encode() or git('status','--porcelain','--untracked-files=all'):
        raise ValueError('EXACT_REVIEWED_CLEAN_HEAD_REQUIRED')
    links=call(['/usr/sbin/ip','-o','link','show']).decode().splitlines()
    if len(links)!=1 or links[0].split(':')[1].strip()!='lo' or any(
        call(['/usr/sbin/ip',f'-{family}','route','show','default']).strip() for family in (4,6)):
        raise ValueError('EXCLUSIVE_LOOPBACK_NAMESPACE_REQUIRED')
    pg=Path(os.environ.get('PG_BIN',''));lib=Path(os.environ.get('ONEPAGE_PG_RUNTIME_LIB',''))
    if any(not x.is_absolute() or x.resolve()!=x or not x.exists() or ':' in str(x) for x in (pg,lib,php)) \
       or not pg.is_dir() or not lib.is_dir() or not php.is_file():
        raise ValueError('PINNED_TOOLS_REQUIRED')
    versions=set()
    e=ENV|{'LD_LIBRARY_PATH':str(lib)}
    for tool in ('initdb','postgres','pg_ctl','pg_controldata'):
        p=pg/tool
        if not p.is_file() or p.resolve()!=p:raise ValueError('PINNED_TOOLS_REQUIRED')
        m=re.fullmatch(r'.+ \(PostgreSQL\) (18\.[0-9]+)(?:\s+.*)?',call([p,'--version'],e).decode().strip())
        if not m:raise ValueError('PG18_TOOL_REQUIRED')
        versions.add(m[1])
    if len(versions)!=1:raise ValueError('SINGLE_PG18_BUILD_REQUIRED')
    for rel in ('.env','.env.testing','bootstrap/cache/config.php'):
        p=LABS.parent/rel
        if p.exists() or p.is_symlink():raise ValueError('APP_AMBIENT_CONFIG_REFUSED')
    if not (LABS.parent/'vendor/autoload.php').is_file():raise ValueError('LOCK_DERIVED_VENDOR_REQUIRED')
    close_port()
    return pg,lib,e


def stopped(tmp,pg,env,completed):
    root=tmp/'pg';data=root/'run/onepage-pg-lab/data'
    if root.exists() or root.is_symlink():
        for path in (root,data):
            if path.exists() or path.is_symlink():
                if not path.is_dir() or path.resolve()!=path or path.stat().st_uid!=os.geteuid() or path.stat().st_mode&0o777!=0o700:
                    raise ValueError('OWNED_SCRATCH_REQUIRED')
    if not data.is_dir():
        if completed:raise ValueError('MISSING_COMPLETE_CLUSTER')
        close_port()
        return
    pid=data/'postmaster.pid'
    if pid.exists() or pid.is_symlink():
        if not pid.is_file() or pid.resolve()!=pid or pid.stat().st_size>4096:
            raise ValueError('OWNED_PID_REQUIRED')
        lines=pid.read_text().splitlines()
        if len(lines)<4 or not re.fullmatch('[1-9][0-9]*',lines[0]) or lines[1]!=str(data) or lines[3]!='55439':
            raise ValueError('OWNED_PID_REQUIRED')
        proc=Path('/proc')/lines[0]
        if proc.stat().st_uid!=os.geteuid() or (proc/'exe').resolve()!=pg/'postgres' or (proc/'cwd').resolve()!=data:
            raise ValueError('OWNED_PID_REQUIRED')
        call([pg/'pg_ctl','-D',data,'-m','fast','-w','stop'],env)
    ctrl=call([pg/'pg_controldata',data],env).decode()
    if pid.exists() or pid.is_symlink() or re.findall(r'^Database cluster state:\s*(.+?)\s*$',ctrl,re.M)!=['shut down']:
        raise ValueError('CLEAN_SHUTDOWN_REQUIRED')
    close_port()


def run_worker(parent,tmp,launch,php,pg,lib):
    env=ENV|{'PHP_BIN':str(php),'PG_BIN':str(pg),'ONEPAGE_PG_RUNTIME_LIB':str(lib),
             'ONEPAGE_ORDER_ALLOW_LOCAL_START':'SYNTHETIC_ONLY'}
    worker=subprocess.Popen(['/bin/bash','--noprofile','--norc',str(launch)],env=env,stdout=subprocess.PIPE,
                            stderr=subprocess.STDOUT,start_new_session=True)
    try:
        raw=bytearray()
        limit=time.monotonic()+480
        with selectors.DefaultSelector() as events:
            events.register(worker.stdout,selectors.EVENT_READ)
            while True:
                left=limit-time.monotonic()
                if left<=0 or not events.select(left):raise ValueError('PRIVATE_WORKER_DEADLINE')
                piece=os.read(worker.stdout.fileno(),min(4096,65537-len(raw)))
                if not piece:break
                raw.extend(piece)
                if len(raw)>65536:raise ValueError('PRIVATE_OUTPUT_LIMIT')
        result=worker.wait(timeout=max(0.05,limit-time.monotonic()))
        try:os.killpg(worker.pid,0)
        except ProcessLookupError:pass
        else:raise ValueError('REMAINING_WORKER_PROCESSES')
    except BaseException:
        try:os.killpg(worker.pid,signal.SIGTERM)
        except ProcessLookupError:pass
        try:worker.wait(timeout=20)
        finally:
            try:os.killpg(worker.pid,signal.SIGKILL)
            except ProcessLookupError:pass
            if worker.poll() is None:worker.wait(timeout=5)
        raise
    finally:
        worker.stdout.close()
    lines=raw.decode().splitlines()
    if lines.count(MARKER)!=1:raise ValueError('RECEIPT_CHECKPOINT_REQUIRED')
    parent.verify_result(result,lines)


def run_parent(parent,php,mode):
    if mode=='--run':
        pg,lib,env=runtime_preflight(php)
    tmp=Path(tempfile.mkdtemp(prefix='onepage-scram-',dir='/tmp'))
    safe=True
    try:
        launch=derive(parent,tmp,php)
        if mode=='--check':return
        values=[secrets.token_hex(32) for _ in ROLES]
        if len(set(values))!=len(ROLES):raise ValueError('INDEPENDENT_FIXTURE_VALUES_REQUIRED')
        for role,value in zip(ROLES,values):private(tmp/('auth-'+role),value+'\n')
        del values
        def cancel(_signum,_frame):
            signal.signal(signal.SIGTERM,signal.SIG_IGN);signal.signal(signal.SIGINT,signal.SIG_IGN)
            raise ValueError('LIMITED_HOST_CANCEL')
        signal.signal(signal.SIGTERM,cancel);signal.signal(signal.SIGINT,cancel)
        completed=False
        safe=False
        try:
            run_worker(parent,tmp,launch,php,pg,lib)
            completed=True
        finally:
            stopped(tmp,pg,env,completed)
            safe=True
    finally:
        # Always erase owned synthetic credential files, including when
        # uncertain PGDATA is retained for disposable host destruction.
        for role in ROLES:
            p=tmp/('auth-'+role)
            if p.exists() or p.is_symlink():p.unlink()
        if safe:shutil.rmtree(tmp)


def verify_epoch(php):
    expression='extract(epoch FROM pg_postmaster_start_time())::bigint started'
    if (LABS/'onepage-buyer/Guard.php').read_text().count(expression)!=2 or (HERE/'auth.php').read_text().count(expression)!=1:
        raise ValueError('EXACT_GUARD_EPOCH_REPRESENTATION_REQUIRED')
    probe=r"""
require $argv[1];
$f=\Imagine\Lab\OnepageReceiptScram\postmasterEpoch(...);
if ($f(1731980457)!=='1731980457'||$f('1731980457')!==$f(1731980457)||$f('1731980458')===$f(1731980457)
    ||$f('9223372036854775807')!=='9223372036854775807') exit(2);
foreach ([null,false,0,-1,1.5,'','01731980457','1e9','1731980457.0',
          '2026-11-18 00:00:00+00','9223372036854775808'] as $bad) if ($f($bad)!==null) exit(2);
"""
    call([php,'-n','-r',probe,HERE/'auth.php'])


def main():
    args=sys.argv[1:]
    allow={'PATH','LC_ALL','PHP_BIN','PWD','_','SHLVL'}
    if args==['--run']:allow|={'GITHUB_ACTIONS','SCRAM_REVIEWED_HEAD','SCRAM_DISPOSABLE_PROFILE','PG_BIN','ONEPAGE_PG_RUNTIME_LIB'}
    if args not in (['--check'],['--run']) or set(os.environ)-allow or os.environ.get('LC_ALL')!='C':
        raise ValueError('EXPLICIT_MODE_REQUIRED')
    php=Path(os.environ.get('PHP_BIN',''))
    if not php.is_absolute() or not php.is_file():raise ValueError('PHP_REQUIRED')
    for rel,digest in PINS.items():
        p=LABS/rel
        if p.resolve()!=p or hashlib.sha256(p.read_bytes()).hexdigest()!=digest:
            raise ValueError('EXACT_SOURCE_REQUIRED')
    call(['/usr/bin/python3',PROFILE/'check_profile.py','--check'])
    if args==['--check']: verify_epoch(php)
    receipt_code=(PARENT/'check.py').read_text()
    begin=receipt_code.split('\ndef main():')[0]
    rc={'__file__':str(PARENT/'check.py'),'__name__':'compose_parent_receipt'}
    exec(compile(begin,str(PARENT/'check.py'),'exec'),rc)
    core=LABS/'onepage-composed-private/run.py'
    source=fix(core.read_text(),'        extend_binding(tmp)','        extend_binding(tmp)\n        add_receipt(tmp)')
    source=fix(source,"'    attach(parent)\\n    parent.main()'","'    attach(parent)\\n    supersede(parent)'")
    source=fix(source,"'attach': attach}","'attach': attach, 'supersede': supersede}")
    ns={'__file__':str(core),'__name__':'scram_compose_parent','add_receipt':rc['attach_receipt'],
        'supersede':lambda parent:run_parent(parent,php,args[0])}
    exec(compile(source,str(core),'exec'),ns)
    ns['main']()
    print('PASS PRIVATE SCRAM SOURCE ONLY; no SQL or credentials' if args==['--check']
          else 'PASS SYNTHETIC RECEIPT INPUT ONLY; SCRAM fixture and cleanup, no paid_at')


if __name__=='__main__':
    try:main()
    except Exception:
        print('PRIVATE_SCRAM_FIXTURE_REFUSED',file=sys.stderr);sys.exit(2)
