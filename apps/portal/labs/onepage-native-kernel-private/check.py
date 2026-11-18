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
paths = {'app/Providers/PortalDevelopmentAuthServiceProvider.php': '96bdf48a995b7c9c9953bfc7260f4df1e5fae70c', 'app/Http/Controllers/PortalBrowserAuthController.php': '71df8907f88f4b884ab81343227cac130a35d288', 'app/Http/Middleware/EnsurePortalDevelopmentAuthEnabled.php': '3324642ff89846a07c2644dd9c59e9fd815590d9', 'app/Http/Middleware/PortalBrowserAuthGuard.php': '451d0f974ceecc4c12f660cd61eef92b86ae15d9', 'app/Infrastructure/Identity/PortalBrowserPrivateRuntime.php': '35b064340d41d75c271e452eff13a7eb54690713', 'app/Infrastructure/Identity/PortalBrowserSecretFile.php': 'b6434a077e8a19fe42e6c902eafe9ce195bfd1de', 'database/security/portal_browser_envelopes.sql': 'b39bbcdb9156ffe884d21d1b208d842f8cb7942d', 'tests/Integration/PortalBrowserAuthenticationPg18Test.php': '16d7b478b5120af9ea8ad413841b257522e92843', 'labs/onepage-atomic-read/fixture-tail.php.inc': '86594ff780d8dd1044f4eedf75e18e7f24bc9d20', 'labs/onepage-atomic-read/pg-checks.php': 'e28fa85a1a8aaea66d60ab2e974e335d3d7378ad', 'labs/onepage-atomic-read/run.py': '44b3d5b83b93cfbe6838a763fea0cb9e5de208bd', 'labs/onepage-buyer/store.sql': 'e165fd8a1f9bd7a1f95ac0dc2ce7fe6ef00fa82e', 'labs/onepage-buyer/BuyerQuoteAdapter.php': 'd7b9053f2f5fdbae3fba9f9cb6866457194c2c34', 'labs/onepage-pending-order/Adapter.php': '3db52d7a6c1eaf649062addd86cc37e3c612ed02', 'database/security/portal_onepage_orders.sql': '93fc7987ed7b1902817af95092fe195c7cfc6722', 'labs/onepage-buyer/CoreSchema.php': 'd564321a3e9c9cab6a25fab30b878e48b786e253', 'app/Infrastructure/Commerce/OnepageOrderCommands.php': '779d395699915907903c96fa71e3dbd40bfa0a75'}


def lint(argv, env=None):
    subprocess.run([str(p) for p in argv],check=True,capture_output=True,timeout=30,
                   env=env if env is not None else {'PATH':'/usr/bin:/bin','LC_ALL':'C'})


def literal(path):
    return "'" + str(path).replace("\\","\\\\").replace("'","\\'") + "'"


def private_draft_store(tmp,atomic):
    # Deliberately retain company-wide key uniqueness. A collision owned by
    # another buyer must not return a draft or become a second insertion.
    wrapper=(app/'labs/onepage-buyer/store.sql').read_text()
    core=(app/'database/security/portal_onepage_orders.sql').read_text()
    start='-- ONEPAGE_STAGE_BUYER_BEGIN';end='-- ONEPAGE_STAGE_BUYER_END'
    if core.count(start)!=1 or core.count(end)!=1 or wrapper.count('-- @portal-native-core BUYER')!=1:
        raise ValueError('CANONICAL_BUYER_STAGE_REQUIRED')
    stage=core.split(start)[1].split(end)[0]
    for old,new in [('portal_onepage_owner','onepage_buyer_owner'),('portal_onepage_runtime','onepage_buyer_runtime'),
                    ('portal_onepage_publisher','onepage_buyer_publisher'),('portal_onepage.','onepage_buyer_lab.'),
                    ('SCHEMA portal_onepage;','SCHEMA onepage_buyer_lab;'),('SCHEMA portal_onepage FROM','SCHEMA onepage_buyer_lab FROM'),
                    ('SCHEMA portal_onepage TO','SCHEMA onepage_buyer_lab TO')]: stage=stage.replace(old,new)
    candidate=wrapper.replace('-- @portal-native-core BUYER',stage)
    if candidate.count('buyer=actor.identity_id')!=2 \
       or candidate.count('UNIQUE(company,request_hash)')!=1 \
       or candidate.count('ON CONFLICT(company,request_hash) DO NOTHING;')!=1:
        raise ValueError('EXACT_OWN_BUYER_DERIVATION_REQUIRED')
    generated=tmp/'store-own-buyer.sql'
    generated.write_text(candidate)
    return generated

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
    sql=private_draft_store(tmp,atomic)
    prep=app/'labs/onepage-pending-order/prepare.php'
    prep_source=prep.read_text().replace('__DIR__',literal(prep.parent))
    prep_source=atomic.replace_once(prep_source,"'labs/onepage-buyer/store.sql'",
                                    literal(sql))
    prep_source=atomic.replace_once(prep_source,'        $db->unprepared(\\App\\Labs\\OnepageBuyer\\CoreSchema::read(base_path($file)));',
        '        $db->unprepared(\\App\\Labs\\OnepageBuyer\\CoreSchema::read($file==='+literal(sql)+' ? $file : base_path($file)));')
    derived_prep=tmp/'prepare-own-buyer.php'
    derived_prep.write_text(prep_source)

    worker=tmp/'worker.sh'
    text=atomic.replace_once(atomic.replace_once(worker.read_text(),
        'phar iconv sodium;','phar iconv sodium posix;'),
        'trap cleanup EXIT',"trap cleanup EXIT\ntrap 'exit 124' TERM INT HUP")
    # The invalid-config negative must still use the untouched native prepare.
    text=atomic.replace_once(text,' "$lab_source/prepare.php" "$cluster" "$PG_BIN/pg_controldata" | tee ',
        ' '+shlex.quote(str(derived_prep))+' "$cluster" "$PG_BIN/pg_controldata" | tee ')
    worker.write_text(text)
    return fixture


def verify_manifest(tmp,fixture):
    from run import Diagnostic, verified_prefix, verify_transcript
    def echoes(path):
        return [m[1] for m in re.findall(r"""echo\s+(['"])(PASS [A-Z_]+)(?:\\n)?\1""",path.read_text())]
    worker=echoes(tmp/'worker.sh')
    if len(worker)!=4 or not worker[1].endswith('CLUSTER_STOPPED'):
        raise ValueError('WORKER_SOURCE_EMISSIONS_CHANGED')
    # Lexical cleanup echo appears inside the EXIT trap before main code;
    # execution writes it after the worker completion echo. Prepare stdout
    # goes through tee, including its maintenance/config/identity checks.
    ordered=[worker[0],worker[2],*echoes(tmp/'prepare-own-buyer.php'),
             *echoes(fixture),*echoes(here/'kernel-check.php'),worker[3],worker[1]]
    def digest(lines):
        return ('\n'.join(lines)+'\n').encode()
    verify_transcript(0,digest(ordered))
    if verified_prefix(digest(ordered))!=13:
        raise ValueError('FULL_PREFIX_REQUIRED')
    for index in range(12):
        partial=digest(ordered[:index]+['private diagnostic /tmp/secret UNKNOWN',ordered[-1]])
        if verified_prefix(partial)!=index:
            raise ValueError('EARLY_TRAP_MUST_NOT_ADVANCE_PREFIX')
    if verified_prefix(digest(ordered)[:-1])!=12:
        raise ValueError('INCOMPLETE_LINE_MUST_NOT_COUNT')
    if verified_prefix(b'x'*65537) or verified_prefix(b'\xff\n'):
        raise ValueError('MALFORMED_TRANSCRIPT_PREFIX_REQUIRED')
    diagnosis=Diagnostic()
    diagnosis.prefix=5
    diagnosis.phase='WORKER'
    diagnosis.cleanup='FAILED'
    if diagnosis.render()!='NATIVE_KERNEL_RUN_REFUSED phase=WORKER prefix=5 cleanup=FAILED':
        raise ValueError('CLEANUP_FAILURE_DISTINCTION_REQUIRED')
    diagnosis.phase='/tmp/private'
    diagnosis.prefix='secret'
    diagnosis.cleanup='raw output'
    if diagnosis.render()!='NATIVE_KERNEL_RUN_REFUSED phase=UNKNOWN prefix=0 cleanup=FAILED':
        raise ValueError('DIAGNOSTIC_ALLOWLIST_REQUIRED')
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
        for file in (fixture,tmp/'prepare-own-buyer.php',here/'kernel-check.php'):
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
