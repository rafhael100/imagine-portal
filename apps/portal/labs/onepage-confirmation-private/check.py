"""Offline source checks; --offline never starts PHP, PostgreSQL or a service."""
import ast
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
NATIVE = HERE.parent / 'onepage-native-kernel-private'


NATIVE_PROGRESS = ('PASS CONFIRMATION_NATIVE_ACCEPT_RETURNED',
                   'PASS CONFIRMATION_NATIVE_ACCEPTANCE_REPLAY_VERIFIED')
EXPECTED_CASE_MARKERS = ('PASS CONFIRMATION_HOOK_ENTERED', 'PASS CONFIRMATION_PRE_DDL_ATTESTED', 'PASS CONFIRMATION_DDL_RETURNED', 'PASS CONFIRMATION_DDL_REATTESTED', 'PASS CONFIRMATION_SECOND_ORDER_VERIFIED', 'PASS CONFIRMATION_WRITER_PROFILE_VERIFIED', 'PASS CONFIRMATION_SOURCE_STAGED_UNCOMMITTED', 'PASS CONFIRMATION_SOURCE_UNCOMMITTED_REFUSED', 'PASS CONFIRMATION_SOURCE_ROLLBACK_VERIFIED', 'PASS CONFIRMATION_SOURCE_COMMITTED_OBSERVED', 'PASS CONFIRMATION_SOURCE_COMMIT_VERSION_DIGEST_REQUIRED', 'PASS CONFIRMATION_DIVERGENT_UNKNOWN_DATES_DENIED', 'PASS CONFIRMATION_RUNTIME_PRIVILEGE_WALL', 'PASS CONFIRMATION_UNCOMMITTED_AND_ROLLBACK_CLOSED', 'PASS CONFIRMATION_COMMIT_FRESH_CONNECTION_OWN_ORDER_ONLY', 'PASS CONFIRMATION_SOURCE_EVENT_UNIQUE_NATIVE_BINDING', 'PASS CONFIRMATION_OLD_TRANSACTION_SNAPSHOT_REFUSED', 'PASS CONFIRMATION_FIRST_RELEASE_IN_GOOD_STANDING_ONLY', 'PASS CONFIRMATION_REVERSAL_TERMINAL_NO_ORDER_MUTATION')

DIAGNOSTIC_CASE_MARKERS = ('PASS CONFIRMATION_HOOK_ENTERED', 'PASS CONFIRMATION_PRE_DDL_ATTESTED', 'PASS CONFIRMATION_DDL_RETURNED', 'PASS CONFIRMATION_DDL_REATTESTED', 'PASS CONFIRMATION_SECOND_ORDER_VERIFIED', 'PASS CONFIRMATION_WRITER_PROFILE_VERIFIED', 'PASS CONFIRMATION_SOURCE_STAGED_UNCOMMITTED', 'PASS CONFIRMATION_SOURCE_UNCOMMITTED_REFUSED', 'PASS CONFIRMATION_SOURCE_ROLLBACK_VERIFIED', 'PASS CONFIRMATION_SOURCE_COMMITTED_OBSERVED')


def verify_sources():
    manifest = json.loads((HERE / 'sources.json').read_text())
    for name, expected in manifest['files'].items():
        path = ROOT / name
        raw = path.read_bytes()
        actual = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()
        if path.resolve() != path or actual != expected:
            raise ValueError('PINNED_SOURCE_CHANGED: ' + name)
    return manifest


def native_modules():
    # Preserve original import bindings; new check.py must not shadow native check.
    sys.path.insert(0, str(NATIVE))
    spec = importlib.util.spec_from_file_location('check', NATIVE / 'check.py')
    check = importlib.util.module_from_spec(spec)
    old = sys.modules.get('check')
    sys.modules['check'] = check
    spec.loader.exec_module(check)
    spec = importlib.util.spec_from_file_location('confirmation_native_runner', NATIVE / 'run.py')
    runner = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(runner)
    if old is not None:
        sys.modules['check'] = old
    else:
        sys.modules.pop('check', None)
    sys.path.pop(0)
    return check, runner


def once(text, old, new):
    if text.count(old) != 1:
        raise ValueError('EXACT_HOOK_REQUIRED')
    return text.replace(old, new)


def assemble(tmp):
    verify_sources()
    native, runner = native_modules()
    fixture = native.source(tmp)
    kernel = (NATIVE / 'kernel-check.php').read_text()
    # Resolve existing __DIR__ references before moving the unchanged kernel.
    kernel = kernel.replace('__DIR__', native.literal(NATIVE))
    accepted = "        $order=$orders->accept($resumed,'native-kernel-order',$id,$accept);"
    kernel = once(kernel, accepted, accepted + '\n        echo "' + NATIVE_PROGRESS[0] + '\\n";')
    marker = "        $expect($request('POST','logout',$buyer,[],$token($buyer))[0]===204);"
    kernel = once(kernel, marker, '        echo "' + NATIVE_PROGRESS[1] + '\\n";\n' + "        require " + native.literal(HERE / 'cases.php') + ";\n" + marker)
    hook = tmp / 'kernel-confirmation.php'
    hook.write_text(kernel)
    fixture.write_text(once(fixture.read_text(), native.literal(NATIVE / 'kernel-check.php'), native.literal(hook)))
    for name in ('launch.sh', 'worker.sh'):
        subprocess.run(['/bin/bash', '-n', str(tmp / name)], check=True, capture_output=True)
    return fixture


def markers():
    observed = re.findall(r'echo "(PASS CONFIRMATION_[A-Z_]+)\\n";', (HERE / 'cases.php').read_text())
    if tuple(observed) != EXPECTED_CASE_MARKERS:
        raise ValueError('CANONICAL_CASE_MARKER_ORDER_REQUIRED')
    return list(NATIVE_PROGRESS) + observed


def offline():
    manifest = verify_sources()
    # Strip only added fixed echoes, then require exact #939 case source.
    cases = (HERE / 'cases.php').read_text()
    for marker in DIAGNOSTIC_CASE_MARKERS:
        cases = once(cases, 'echo "' + marker + '\\n";\n', '')
    raw_cases = cases.encode()
    if hashlib.sha1(b'blob ' + str(len(raw_cases)).encode() + b'\0' + raw_cases).hexdigest() != '06282477d95955f2970a3364fb3a27655ee15e12':
        raise ValueError('PINNED_CASE_ASSERTIONS_CHANGED')
    for name in ('check.py', 'run.py'):
        ast.parse((HERE / name).read_text())
    with tempfile.TemporaryDirectory(prefix='confirmation-source-') as directory:
        tmp = Path(directory)
        assemble(tmp)
        if len(markers()) != len(set(markers())):
            raise ValueError('EXACT_CONFIRMATION_CASE_MARKERS_REQUIRED')
        # Checks generated composition, not just input files; no PHP invocation.
        kernel = (tmp / 'kernel-confirmation.php').read_text()
        if kernel.count(str(HERE / 'cases.php')) != 1 or kernel.index(str(HERE / 'cases.php')) > kernel.index("$request('POST','logout'"):
            raise ValueError('ACCEPTANCE_BEFORE_LOGOUT_REQUIRED')
        # Removing ONLY the two generated diagnostic echoes must recover the
        # exact pre-diagnostic native composition. Assertions stay byte-identical.
        native, _ = native_modules()
        original = (NATIVE / 'kernel-check.php').read_text().replace('__DIR__', native.literal(NATIVE))
        logout = "        $expect($request('POST','logout',$buyer,[],$token($buyer))[0]===204);"
        original = once(original, logout, "        require " + native.literal(HERE / 'cases.php') + ";\n" + logout)
        stripped = kernel
        for marker in NATIVE_PROGRESS:
            stripped = once(stripped, '        echo "' + marker + '\\n";\n', '')
        if stripped != original:
            raise ValueError('NATIVE_ASSERTIONS_CHANGED')
        accept_at = kernel.index("$order=$orders->accept($resumed,'native-kernel-order'")
        assertion_at = kernel.index("$expect(($order['status']??null)==='pending_payment'")
        if not (accept_at < kernel.index(NATIVE_PROGRESS[0]) < assertion_at
                < kernel.index(NATIVE_PROGRESS[1]) < kernel.index(str(HERE / 'cases.php'))):
            raise ValueError('NATIVE_PROGRESS_BOUNDARY_REQUIRED')
        _, runner = native_modules()
        expected = runner.REQUIRED[:-3] + markers() + runner.REQUIRED[-3:]
        runner.REQUIRED = expected
        raw = ('\n'.join(expected) + '\n').encode()
        runner.verify_transcript(0, raw)
        for i in range(len(expected)):
            try:
                runner.verify_transcript(0, ('\n'.join(expected[:i] + expected[i+1:]) + '\n').encode())
            except ValueError:
                continue
            raise ValueError('MISSING_MARKER_ACCEPTED')
        # Adjacent swaps, each duplicate and every truncated sequence must fail.
        invalid = []
        for i in range(len(expected)-1):
            swapped = expected[:]
            swapped[i], swapped[i+1] = swapped[i+1], swapped[i]
            invalid.append((0, ('\n'.join(swapped)+'\n').encode()))
        for i in range(len(expected)):
            invalid.append((0, ('\n'.join(expected[:i]+[expected[i]]+expected[i:])+'\n').encode()))
            invalid.append((0, ('\n'.join(expected[:i])+'\n').encode()))
            prefix = ('\n'.join(expected[:i])+'\n').encode()
            if runner.verified_prefix(prefix) != i:
                raise ValueError('DIAGNOSTIC_PREFIX_CHANGED')
            # Early cleanup cannot make a partial prefix look complete.
            if i < len(expected)-1 and runner.verified_prefix(prefix + (expected[-1]+'\n').encode()) != i:
                raise ValueError('EARLY_CLEANUP_ADVANCED_PREFIX')
        invalid += [(1, raw), (0, raw + b'PASS EXTRA\n'), (0, raw + raw),
                    (0, b'\xff'), (0, raw+b'x'*65537)]
        for status, transcript in invalid:
            try:
                runner.verify_transcript(status, transcript)
            except (ValueError, UnicodeError):
                continue
            raise ValueError('INVALID_TRANSCRIPT_ACCEPTED')
        if sys.argv[1:] == ['--check']:
            php = Path(os.environ.get('PHP_BIN', ''))
            if not php.is_absolute() or not php.is_file():
                raise ValueError('PHP_REQUIRED_FOR_LINT')
            for path in (tmp / 'kernel-confirmation.php', tmp / 'prepare-own-buyer.php', tmp / 'fixture.php', HERE / 'cases.php', HERE / 'NextStep.php'):
                subprocess.run([str(php), '-n', '-l', str(path)], check=True, capture_output=True)
    print('PASS CONFIRMATION_OFFLINE_SOURCE %d blobs; composition/bash/marker checks only' % len(manifest['files']))


if __name__ == '__main__':
    if sys.argv[1:] not in (['--offline'], ['--check']):
        raise SystemExit('OFFLINE_MODE_REQUIRED')
    offline()
