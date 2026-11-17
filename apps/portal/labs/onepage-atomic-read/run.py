#!/usr/bin/env python3
"""Compose pinned #923 lifecycle without editing it. No startup in --check mode."""
import hashlib, json, os, pathlib, shlex, subprocess, sys, tempfile
from collections import Counter
PINS = {'onepage-pending-order/start-isolated.sh': 'e6eef5e17ba9c7a13b7b6ce761938657c3c138979cfd27f929f50c15554692e8', 'onepage-pending-order/run-clean.sh': '97159aebd68f956d945c6be572f144a8eb4f20d97f04fd349b926f224906fe4c', 'onepage-pending-order/prepare.php': '580312a7a6308ad2a053cb14c0e0da489da1e3a9b5ecbb3be2da815cdfe7255e', 'onepage-pending-order/ProcessPreflight.php': '1392b92499b84c8040af9d4df321921121ce7492dcdcc532a76b4527d0d5ec51', 'onepage-pending-order/pg-cases.php': 'f57a0bcac5d5902a7d8a3a884064fa0bf5ef07aa39107b80158d5a3841f5c0f3', 'onepage-pending-order/store.sql': 'c63c04de2fffa4e75fa63fe5c079190783ba16af0a722e8e118944885e376adf', 'onepage-pending-order/Adapter.php': '6d6ed8e3a98502578c5d08270498289bcbfeaabe1636905cfd2b2f23cf2462dd', 'onepage-pending-order/ProofChecks.php': 'e485e20b3880decc418fdcd3a04c57c74bfbfdf85ac749613459c60e8912c9d3', 'onepage-buyer/Guard.php': '8dd7884bc95337327a9d93cf047fea28f2fb14173cba238a0913b8fc3cc0ded1', 'onepage-buyer/store.sql': 'b4d727242d70a66106b61f21b85d7a3853607d45b0f44a700bc15c2661f9d9dd', 'onepage-buyer/BuyerQuoteAdapter.php': '5d6f233f6f436896d01442be358e7918488d19e2eef9339699b89479f3ee5b92'}
HERE = pathlib.Path(__file__).resolve().parent
BASE = HERE.parent / 'onepage-pending-order'

def replace_once(text, old, new):
    if text.count(old) != 1:
        raise ValueError('REFERENCE_SHAPE_CHANGED')
    return text.replace(old, new)

def compose(tmp):
    for name, digest in PINS.items():
        if hashlib.sha256((HERE.parent / name).read_bytes()).hexdigest() != digest:
            raise ValueError('REFERENCE_HASH_CHANGED')
    # Only the preparation prefix, before the original cases/identities.
    original = (BASE / 'pg-cases.php').read_text()
    boundary = ' $companies=[];'
    if original.count(boundary) != 1:
        raise ValueError('REFERENCE_BOUNDARY_CHANGED')
    prefix = original.split(boundary)[0]
    # PHP literal paths are generated, never published or logged.
    literal = lambda value: "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"
    prefix = prefix.replace('__DIR__', literal(BASE))
    tail = (HERE / 'fixture-tail.php.inc').read_text().replace('__ATOMIC_HOOK__', literal(HERE / 'pg-checks.php'))
    fixture = tmp / 'fixture.php'
    fixture.write_text(prefix + tail)
    worker = (BASE / 'run-clean.sh').read_text()
    worker = replace_once(worker, '"$lab_source/pg-cases.php"', shlex.quote(str(fixture)))
    (tmp / 'worker.sh').write_text(worker)
    launcher = (BASE / 'start-isolated.sh').read_text()
    launcher = replace_once(launcher, 'lab_source=$(cd -- "$(dirname -- "$0")" && pwd)', 'lab_source=' + shlex.quote(str(BASE)))
    launcher = replace_once(launcher, '"$lab_source/run-clean.sh"', shlex.quote(str(tmp / 'worker.sh')))
    (tmp / 'launch.sh').write_text(launcher)
    return fixture

REQUIRED = ['PASS ORDER_CLEAN_PHP_BEFORE_STARTUP','PASS ORDER_INVALID_CONFIG_DENIED_BEFORE_SQL',
    'PASS ORDER_BASE_PREPARATION_ATTESTED','PASS ORDER_PREFLIGHT_LARAVEL_SQL_ATTESTED',
    'PASS ATOMIC_NATIVE_FIXTURES','PASS RESERVATION_CAS: durable single attempt, guarded scope, no release',
    'PASS ATOMIC_SQL_COMPLETE: 16',
    'PASS ORDER_RUNNER_COMPLETE_NO_RELEASE','PASS ORDER_CLUSTER_STOPPED']
CASES = ['own pending only','complete persisted snapshot positive','catalog owner and exact ACL',
    'same tenant different active buyer control','different tenant active control','different tenant SQL null',
    'other buyer denied','missing generic denial','idempotent read no writes',
    'direct access specifically denied','direct access specifically denied',
    'expired same buyer fixture control','expired native authority denied',
    'membership revocation active control','membership revocation persisted','revoked no order writes']

def verify_result(code, lines):
    actual = [line for line in lines if line.startswith('PASS ATOMIC_SQL_CASE: ')]
    expected = ['PASS ATOMIC_SQL_CASE: ' + case for case in CASES]
    if code or any(lines.count(marker) != 1 for marker in REQUIRED) or Counter(actual) != Counter(expected):
        raise ValueError('SQL_OR_CLEANUP_INCOMPLETE')

def main():
    if sys.argv[1:] not in (['--check'], ['--run']):
        raise ValueError('MODE_REQUIRED')
    allowed={'PATH','LC_ALL','PWD','SHLVL','_','PHP_BIN','PG_BIN','ONEPAGE_PG_RUNTIME_LIB','ONEPAGE_ORDER_ALLOW_LOCAL_START'}
    if set(os.environ)-allowed:
        raise ValueError('AMBIENT_ENVIRONMENT_REFUSED')
    if os.environ.get('LC_ALL') != 'C':
        raise ValueError('LOCALE_C_REQUIRED')
    php=os.environ.get('PHP_BIN','')
    if not pathlib.Path(php).is_absolute():
        raise ValueError('PHP_REQUIRED')
    with tempfile.TemporaryDirectory(prefix='atomic-read-composition-') as root:
        tmp=pathlib.Path(root)
        fixture=compose(tmp)
        for name in ['launch.sh','worker.sh']:
            subprocess.run(['/bin/bash','-n',str(tmp/name)],check=True,capture_output=True)
        # Lint without inherited ini; no execution, autoload, PDO or PostgreSQL.
        subprocess.run([php,'-n','-l',str(fixture)],env={'PATH':'/usr/bin:/bin'},check=True,capture_output=True)
        print('PASS ATOMIC_COMPOSITION_OFFLINE',flush=True)
        if sys.argv[1]=='--check':
            return
        if os.environ.get('ONEPAGE_ORDER_ALLOW_LOCAL_START')!='SYNTHETIC_ONLY':
            raise ValueError('OPT_IN_REQUIRED')
        env={k:v for k,v in os.environ.items() if k in allowed and k not in {'PWD','SHLVL','_'}}
        # Unfiltered server/tool/PHP output remains private and is discarded.
        with tempfile.TemporaryFile() as log:
            result=subprocess.run(['/bin/bash','--noprofile','--norc',str(tmp/'launch.sh')],env=env,stdout=log,stderr=log)
            log.seek(0);lines=log.read().decode('utf-8',errors='replace').splitlines()
        # Emit only a complete validated set, never raw output or partial success.
        verify_result(result.returncode, lines)
        for marker in REQUIRED: print(marker)
        for case in CASES: print('PASS ATOMIC_SQL_CASE: ' + case)
        print('PASS ATOMIC_SQL_CASES_AND_NEGATIVES: 16')
        print('PASS ATOMIC_RUN_COMPLETE_NO_RELEASE')

if __name__=='__main__':
    try: main()
    except Exception:
        print('ATOMIC_RUN_FAILED: preflight, SQL or cleanup incomplete',file=sys.stderr)
        sys.exit(1)
