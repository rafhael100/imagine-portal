"""Future private PG18 runner. No rollout; no outbound/provider paths."""
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile

sys.dont_write_bytecode=True
from check import app,here,source,lint
ROOT=app.parent.parent
BASE='42a3fe2e09463ffcf8d5010e2de4258cf19ffa0b'
BASE_TREE='cd07ea4c3feb3524692d4630d4afe659afd6c800'
PREP='c641b340d50658247f17ba6eb58bf5ac41474113'
PREP_TREE='32f9a6155537e0b67aa8ab5c961d03abe7d3f3d9'
PUBLIC_BASE='951e02e5d8eedee93851416778027cf07d105296'
WORKFLOW='.github/workflows/onepage-native-kernel-pg-lab.yml'
LAB_PATH='apps/portal/labs/onepage-native-kernel-private/'
LAB_FILES=tuple(LAB_PATH+n for n in ('README.md','check.py','kernel-check.php','run.py'))
ENV={'PATH':'/usr/bin:/bin','LC_ALL':'C'}
NATIVE=[
 'PASS NATIVE_KERNEL_UNLINKED_DENIED',
 'PASS NATIVE_KERNEL_PENDING_MFA_AND_CSRF_DENIED',
 'PASS NATIVE_KERNEL_PASSWORD_TOTP_ENCRYPTED_SESSION',
 'PASS NATIVE_KERNEL_WRONG_OWNER_OR_REVISION_NO_WRITE',
 'PASS NATIVE_KERNEL_EXACT_ACCEPTANCE_AND_REPLAY_LOGOUT_DENIED',
]
REQUIRED=['PASS ORDER_CLEAN_PHP_BEFORE_STARTUP',
 'PASS ORDER_INVALID_CONFIG_DENIED_BEFORE_SQL',
 'PASS ORDER_MAINTENANCE_PREFLIGHT_BEFORE_WRITES','PASS ORDER_BASE_IDENTITY_BEFORE_SCHEMA',
 'PASS ORDER_BASE_PREPARATION_ATTESTED',
 'PASS ORDER_PREFLIGHT_LARAVEL_SQL_ATTESTED',*NATIVE,
 'PASS ORDER_RUNNER_COMPLETE_NO_RELEASE','PASS ORDER_CLUSTER_STOPPED']


PHASES=frozenset(('HOST','TREE','TOOLING','LOOPBACK','ASSEMBLY','WORKER','TRANSCRIPT'))


class Diagnostic:
    phase='HOST'
    prefix=0
    cleanup='NOT_NEEDED'

    def render(self):
        phase=self.phase if self.phase in PHASES else 'UNKNOWN'
        count=self.prefix if type(self.prefix) is int and 0<=self.prefix<=len(REQUIRED) else 0
        cleanup=self.cleanup if self.cleanup in ('NOT_NEEDED','VERIFIED','FAILED') else 'FAILED'
        return f'NATIVE_KERNEL_RUN_REFUSED phase={phase} prefix={count} cleanup={cleanup}'


def verified_prefix(data):
    # Output is only a bounded count of the contiguous canonical marker prefix.
    # A trap can emit a real stop marker early; it must not advance this prefix.
    if len(data)>65536: return 0
    if not data.endswith(b"\n"): data=data[:data.rfind(b"\n")+1]
    try:
        records=[line for line in data.decode().splitlines() if line.startswith('PASS ')]
    except UnicodeError:
        return 0
    count=0
    for expected,observed in zip(REQUIRED,records):
        if expected!=observed: break
        count+=1
    return count


def call(args,env=ENV,seconds=30):
    result=subprocess.run([str(x) for x in args],env=env,capture_output=True,timeout=seconds,check=True)
    return result.stdout.decode('utf-8')


def verify_tree(expected,ci_source_tree=None):
    hex40=lambda v: isinstance(v,str) and re.fullmatch('[a-f0-9]{40}',v) is not None
    if not hex40(expected):
        raise ValueError('EXACT_REVIEWED_HEAD_REQUIRED')
    def git(*args):
        return call(['/usr/bin/git','-C',ROOT,*args],ENV|{'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null',
                                                    'GIT_OPTIONAL_LOCKS':'0'}).strip()
    if git('rev-parse','HEAD')!=expected or git('status','--porcelain','--untracked-files=all'):
        raise ValueError('REVIEWED_CLEAN_TREES_REQUIRED')
    if ci_source_tree is None:
        # Preserve the original exact private review stack and its tree pins.
        if git('rev-parse','HEAD^')!=PREP or git('rev-parse',BASE+'^{tree}')!=BASE_TREE \
           or git('rev-parse',PREP+'^{tree}')!=PREP_TREE:
            raise ValueError('REVIEWED_LOCAL_HISTORY_REQUIRED')
        base=BASE
        paths=LAB_FILES
    else:
        # Event gate + immutable source tree from reviewed workflow; no
        # workflow self-hash and no mutable branch as a trust source.
        parent=git('show','-s','--format=%P','HEAD')
        if not hex40(ci_source_tree) or not hex40(parent) or git('show','-s','--format=%P',parent)!=PUBLIC_BASE \
           or git('rev-parse',PUBLIC_BASE+'^{tree}')!=BASE_TREE or git('rev-parse',parent+'^{tree}')!=ci_source_tree \
           or git('diff','--name-status','--no-renames',parent,'HEAD')!='A\t'+WORKFLOW:
            raise ValueError('REVIEWED_CI_HISTORY_REQUIRED')
        base=PUBLIC_BASE
        paths=(*LAB_FILES,WORKFLOW)
    expected_status=['A\t'+n for n in sorted(paths)]
    if git('diff','--name-status','--no-renames',base,'HEAD').splitlines()!=expected_status:
        raise ValueError('DERIVATIVE_SCOPE_REQUIRED')
    for name in paths:
        if not re.fullmatch(r'100644 blob [a-f0-9]{40}\t'+re.escape(name),git('ls-tree','HEAD','--',name)):
            raise ValueError('DERIVATIVE_FILE_MODE_REQUIRED')


def port_free():
    with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as sock:
        sock.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
        sock.bind(('127.0.0.1',55439))


def tooling(php,pg,lib):
    for p in (php,pg,lib):
        if not p.is_absolute() or p.resolve()!=p or not p.exists() or ':' in str(p) or not os.access(p,os.X_OK):
            raise ValueError('CANONICAL_TOOL_PATH_REQUIRED')
    if not php.is_file() or not pg.is_dir() or not lib.is_dir(): raise ValueError('TOOL_TYPE_REQUIRED')
    env=ENV|{'LD_LIBRARY_PATH':str(lib)}
    versions=[]
    for name in ('initdb','pg_ctl','postgres','pg_controldata'):
        b=pg/name
        if not b.is_file() or not os.access(b,os.X_OK) or b.resolve()!=b: raise ValueError('PG_TOOL_REQUIRED')
        m=re.fullmatch(r'.+ \(PostgreSQL\) (18\.[0-9]+)(?:\s+.*)?',call([b,'--version'],env).strip())
        if m is None: raise ValueError('PG18_REQUIRED')
        versions.append(m[1])
    if len(set(versions))!=1: raise ValueError('PG_BUILD_MISMATCH')
    ext=Path(call([php,'-n','-r','echo ini_get("extension_dir");'],env))
    if not ext.is_absolute() or not ext.is_dir() or ext.resolve()!=ext:
        raise ValueError('PHP_EXTENSION_ROOT_REQUIRED')
    args=[php,'-n']
    for module in ('pdo','pdo_pgsql','posix'):
        p=ext/(module+'.so')
        if p.exists():
            if not p.is_file() or p.resolve()!=p: raise ValueError('MODULE_PIN_REQUIRED')
            args+=['-d','extension='+str(p)]
    call([*args,'-r','exit(PHP_VERSION_ID>=80400&&PHP_VERSION_ID<80500&&function_exists("posix_geteuid")&&extension_loaded("pdo_pgsql")?0:2);'],env)
    for name in ('.env','.env.testing','bootstrap/cache/config.php'):
        p=app/name
        if p.exists() or p.is_symlink(): raise ValueError('AMBIENT_CONFIG_REFUSED')
    vendor=app/'vendor/autoload.php'
    if not vendor.is_file() or vendor.resolve()!=vendor:
        raise ValueError('LOCK_DERIVED_PRIVATE_VENDOR_REQUIRED')
    return env


def signal_group(pid,signum):
    try: os.killpg(pid,signum)
    except ProcessLookupError: return False
    return True


def run_worker(launch,tmp,php,pg,lib,diagnostic):
    env=ENV|{'PHP_BIN':str(php),'PG_BIN':str(pg),'ONEPAGE_PG_RUNTIME_LIB':str(lib),
             'ONEPAGE_ORDER_ALLOW_LOCAL_START':'SYNTHETIC_ONLY'}
    with tempfile.TemporaryFile(dir=tmp) as log:
        try:
            child=subprocess.Popen(['/bin/bash','--noprofile','--norc',str(launch)],
                                   env=env,stdout=log,stderr=log,start_new_session=True)
            try:
                code=child.wait(timeout=480)
            finally:
                if child.poll() is None or signal_group(child.pid,0):
                    signal_group(child.pid,signal.SIGTERM)
                    try: child.wait(timeout=20)
                    finally:
                        if signal_group(child.pid,signal.SIGKILL):
                            child.wait(timeout=5)
                        raise ValueError('WORKER_TERMINATION_OR_DESCENDANTS')
        finally:
            log.seek(0)
            output=log.read(65537)
            diagnostic.prefix=verified_prefix(output)
    if code:
        raise ValueError('WORKER_INCOMPLETE')
    diagnostic.phase='TRANSCRIPT'
    verify_transcript(code,output)


def verify_transcript(code,output):
    if code or len(output)>65536: raise ValueError('WORKER_INCOMPLETE')
    transcript=output.decode().splitlines()
    observed=[line for line in transcript if line.startswith('PASS ')]
    if observed!=REQUIRED: raise ValueError('EXACT_STAGE_MARKERS_REQUIRED')


def assert_shutdown(root,pg,env,completion_required):
    if not root.exists() and not root.is_symlink():
        if completion_required: raise ValueError('COMPLETED_CLUSTER_ROOT_MISSING')
        return
    # bba90807 pattern: bounded process group, then owned fixed scratch,
    # backend identity/clean control state, and an independent port check.
    def owned(p):
        return p.is_dir() and p.resolve()==p and p.stat().st_uid==os.geteuid() and p.stat().st_mode&0o777==0o700
    if not owned(root): raise ValueError('CLEANUP_ROOT_REQUIRED')
    data=root/'run/onepage-pg-lab/data'
    if not data.exists() and not data.is_symlink() and completion_required:
        raise ValueError('COMPLETED_CLUSTER_DATA_MISSING')
    if data.exists() or data.is_symlink():
        if not owned(data): raise ValueError('CLEANUP_DATA_REQUIRED')
        pidfile=data/'postmaster.pid'
        if pidfile.exists() or pidfile.is_symlink():
            if not pidfile.is_file() or pidfile.resolve()!=pidfile or pidfile.stat().st_size>4096:
                raise ValueError('CLEANUP_PIDFILE_REQUIRED')
            fields=pidfile.read_text().splitlines()
            if len(fields)<4 or not re.fullmatch('[1-9][0-9]*',fields[0]) or fields[1]!=str(data) or fields[3]!='55439':
                raise ValueError('CLEANUP_PID_IDENTITY_REQUIRED')
            p=Path('/proc')/fields[0]
            if p.stat().st_uid!=os.geteuid() or (p/'exe').resolve()!=pg/'postgres' or (p/'cwd').resolve()!=data:
                raise ValueError('CLEANUP_PROCESS_IDENTITY_REQUIRED')
            call([pg/'pg_ctl','-D',data,'-m','fast','-w','stop'],env,30)
        control=call([pg/'pg_controldata',data],env)
        if pidfile.exists() or pidfile.is_symlink() or re.findall(
                r'^Database cluster state:\s*(.+?)\s*$',control,re.M)!=['shut down']:
            raise ValueError('CLEANUP_ATTESTATION_REQUIRED')
    port_free()
    shutil.rmtree(root)
    if root.exists() or root.is_symlink(): raise ValueError('CLEANUP_SCRATCH_REQUIRED')


def main(diagnostic):
    allowed=set(ENV)|{'PWD','_','SHLVL','PHP_BIN','PG_BIN','ONEPAGE_PG_RUNTIME_LIB',
                       'GITHUB_ACTIONS','NATIVE_KERNEL_TEST','NATIVE_KERNEL_EXPECTED_HEAD','NATIVE_KERNEL_CI_SOURCE_TREE'}
    if sys.argv[1:]!=['--run'] or set(os.environ)-allowed or os.environ.get('LC_ALL')!='C' \
       or os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('NATIVE_KERNEL_TEST')!='APPROVED_SYNTHETIC_ONLY' \
       or sys.platform!='linux' or os.geteuid()==0:
        raise ValueError('DISPOSABLE_HOST_GATE')
    diagnostic.phase='TREE'
    verify_tree(os.environ.get('NATIVE_KERNEL_EXPECTED_HEAD',''),os.environ.get('NATIVE_KERNEL_CI_SOURCE_TREE'))
    diagnostic.phase='TOOLING'
    php,pg,lib=(Path(os.environ.get(k,'')) for k in ('PHP_BIN','PG_BIN','ONEPAGE_PG_RUNTIME_LIB'))
    env=tooling(php,pg,lib)
    diagnostic.phase='LOOPBACK'
    port_free()
    diagnostic.phase='ASSEMBLY'
    tmp=Path(tempfile.mkdtemp(prefix='onepage-native-kernel-'))
    retain=False
    try:
        fixture=source(tmp)
        for path in (fixture,tmp/'prepare-own-buyer.php',here/'kernel-check.php'): lint([php,'-n','-l',path],env)
        for name in ('launch.sh','worker.sh'): lint(['/bin/bash','-n',tmp/name])
        def cancel(_signum,_frame):
            signal.signal(signal.SIGTERM,signal.SIG_IGN);signal.signal(signal.SIGINT,signal.SIG_IGN)
            raise ValueError('HOST_CANCELLED')
        signal.signal(signal.SIGTERM,cancel);signal.signal(signal.SIGINT,cancel)
        retain=True
        diagnostic.phase='WORKER'
        completed=False
        try:
            run_worker(tmp/'launch.sh',tmp,php,pg,lib,diagnostic)
            completed=True
        finally:
            diagnostic.cleanup='FAILED'
            assert_shutdown(tmp/'portal-run',pg,env,completed)
            port_free()
            retain=False
    finally:
        if not retain:
            diagnostic.cleanup='FAILED'
            shutil.rmtree(tmp)
            diagnostic.cleanup='VERIFIED'
    for line in NATIVE: print(line)
    print('PASS NATIVE_KERNEL_ONEPAGE_AND_ATTESTED_TEARDOWN; synthetic only')


if __name__=='__main__':
    diagnostic=Diagnostic()
    try: main(diagnostic)
    except Exception:
        print(diagnostic.render(),file=sys.stderr)
        sys.exit(2)
