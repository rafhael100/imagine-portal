"""Assemble an isolated source candidate only; never starts PostgreSQL or HTTP."""
import hashlib
import importlib.util
import os
import shlex
import re
from pathlib import Path
import subprocess
import sys
import tempfile

sys.dont_write_bytecode=True
here=Path(__file__).resolve().parent
app=here.parent.parent
paths={
    'app/Providers/PortalDevelopmentAuthServiceProvider.php':'96bdf48a995b7c9c9953bfc7260f4df1e5fae70c',
    'app/Http/Controllers/PortalBrowserAuthController.php':'71df8907f88f4b884ab81343227cac130a35d288',
    'app/Http/Middleware/EnsurePortalDevelopmentAuthEnabled.php':'3324642ff89846a07c2644dd9c59e9fd815590d9',
    'app/Http/Middleware/PortalBrowserAuthGuard.php':'451d0f974ceecc4c12f660cd61eef92b86ae15d9',
    'app/Infrastructure/Identity/PortalBrowserPrivateRuntime.php':'35b064340d41d75c271e452eff13a7eb54690713',
    'app/Infrastructure/Identity/PortalBrowserSecretFile.php':'b6434a077e8a19fe42e6c902eafe9ce195bfd1de',
    'database/security/portal_browser_envelopes.sql':'b39bbcdb9156ffe884d21d1b208d842f8cb7942d',
    'tests/Integration/PortalBrowserAuthenticationPg18Test.php':'16d7b478b5120af9ea8ad413841b257522e92843',
    'labs/onepage-atomic-read/fixture-tail.php.inc':'86594ff780d8dd1044f4eedf75e18e7f24bc9d20',
    'labs/onepage-atomic-read/pg-checks.php':'d2180e25074e14201e57f3e9fdcb07d49c0abb57',
    'labs/onepage-atomic-read/run.py':'f6213d8b5759409eb93973dac232a9126db1d2f8',
    'labs/onepage-buyer/BuyerQuoteAdapter.php':'d7b9053f2f5fdbae3fba9f9cb6866457194c2c34',
    'labs/onepage-pending-order/Adapter.php':'43830be9048cee2482fae9b226a064cfd4cb5fe9',
}


def lint(argv, env=None):
    subprocess.run([str(p) for p in argv],check=True,capture_output=True,timeout=30,
                   env=env if env is not None else {'PATH':'/usr/bin:/bin','LC_ALL':'C'})


def source(tmp):
    for rel,want in paths.items():
        path=app/rel
        raw=path.read_bytes()
        got=hashlib.sha1(('blob '+str(len(raw))+'\0').encode()+raw).hexdigest()
        if path.resolve()!=path or got!=want:
            raise ValueError('EXACT_SOURCE_REQUIRED')
    src=app/'labs/onepage-atomic-read/run.py'
    spec=importlib.util.spec_from_file_location('atomic_native_source',src)
    atomic=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(atomic)
    fixture=atomic.compose(tmp)
    text=fixture.read_text()
    marker='// Appended inside the pinned #923 preparation try block; no old acceptance suite.'
    if text.count(marker)!=1:
        raise ValueError('EXACT_TAIL_BOUNDARY_REQUIRED')
    pos=text.index(marker)
    hook=str(here/'kernel-check.php').replace('\\','\\\\').replace("'","\\'")
    fixture.write_text(text[:pos]+f"""require '{hook}';
nativeCookieToOrder($app,$db,$owner,$identity,$barrier,$connect,$proof,$key,$hash,$cipher,$password,__DIR__);
}} catch (Throwable $e) {{ fwrite(STDERR,"NATIVE_KERNEL_ONEPAGE_FIXTURE_FAILED\\n"); exit(1); }}
""")
    launch=tmp/'launch.sh'
    launch.write_text(atomic.replace_once(launch.read_text(),
        'private_root=$(mktemp -d /tmp/onepage-order-clean.XXXXXXXX)',
        'private_root='+shlex.quote(str(tmp/'portal-run'))+'; mkdir -m 700 -- "$private_root"'))
    worker=tmp/'worker.sh'
    worker.write_text(atomic.replace_once(atomic.replace_once(worker.read_text(),
        'phar iconv sodium;','phar iconv sodium posix;'),
        'trap cleanup EXIT',"trap cleanup EXIT\ntrap 'exit 124' TERM INT HUP"))
    return fixture


def verify_manifest(tmp,fixture):
    from run import verify_transcript
    def echoes(path):
        return [m[1] for m in re.findall(r"""echo\s+(['"])(PASS [A-Z_]+)(?:\\n)?\1""",path.read_text())]
    worker=echoes(tmp/'worker.sh')
    if len(worker)!=4 or not worker[1].endswith('CLUSTER_STOPPED'):
        raise ValueError('WORKER_SOURCE_EMISSIONS_CHANGED')
    # Lexical cleanup echo appears inside the EXIT trap before main code;
    # execution writes it after the worker completion echo. Prepare stdout
    # goes through tee, including its maintenance/config/identity checks.
    ordered=[worker[0],worker[2],*echoes(app/'labs/onepage-pending-order/prepare.php'),
             *echoes(fixture),*echoes(here/'kernel-check.php'),worker[3],worker[1]]
    def digest(lines):
        return ('\n'.join(lines)+'\n').encode()
    verify_transcript(0,digest(ordered))
    verify_transcript(0,digest(ordered[:3]+['diagnostic line (not a marker)']+ordered[3:]))
    reordered=ordered[:]
    reordered[2],reordered[3]=reordered[3],reordered[2]
    denied=[(0,digest(ordered[:i]+ordered[i+1:])) for i in range(len(ordered))]
    denied += [(0,digest(reordered)),(0,digest(ordered+['PASS UNEXPECTED'])),
               (0,digest(ordered+[ordered[-1]])),(1,digest(ordered)),
               (0,digest(ordered)+b'x'*65536)]
    for code,raw in denied:
        try: verify_transcript(code,raw)
        except ValueError: continue
        raise ValueError('TRANSCRIPT_NEGATIVE_NOT_REJECTED')


def main():
    if sys.argv[1:]!=['--check'] or set(os.environ)-{'PATH','LC_ALL','PHP_BIN','PWD','_','SHLVL'} \
       or os.environ.get('LC_ALL')!='C':
        raise ValueError('OFFLINE_CHECK_ONLY')
    php=Path(os.environ.get('PHP_BIN',''))
    if not php.is_absolute() or not php.is_file():
        raise ValueError('PIN_PHP_BINARY')
    with tempfile.TemporaryDirectory(prefix='native-kernel-onepage-source-') as d:
        tmp=Path(d)
        fixture=source(tmp)
        verify_manifest(tmp,fixture)
        for file in (fixture,here/'kernel-check.php'):
            lint([php,'-n','-l',file])
        for name in ('launch.sh','worker.sh'):
            lint(['/bin/bash','-n',tmp/name])
    print('PASS NATIVE_KERNEL_ONEPAGE_SOURCE_ONLY; no SQL, HTTP or fixture execution')


if __name__=='__main__':
    try:
        main()
    except Exception:
        print('NATIVE_KERNEL_ONEPAGE_SOURCE_REFUSED',file=sys.stderr)
        sys.exit(2)
