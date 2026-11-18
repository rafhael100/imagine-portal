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
    marker = "        $expect($request('POST','logout',$buyer,[],$token($buyer))[0]===204);"
    kernel = once(kernel, marker, "        require " + native.literal(HERE / 'cases.php') + ";\n" + marker)
    hook = tmp / 'kernel-confirmation.php'
    hook.write_text(kernel)
    fixture.write_text(once(fixture.read_text(), native.literal(NATIVE / 'kernel-check.php'), native.literal(hook)))
    for name in ('launch.sh', 'worker.sh'):
        subprocess.run(['/bin/bash', '-n', str(tmp / name)], check=True, capture_output=True)
    return fixture


def markers():
    return re.findall(r'echo "(PASS CONFIRMATION_[A-Z_]+)\\n";', (HERE / 'cases.php').read_text())


def offline():
    manifest = verify_sources()
    for name in ('check.py', 'run.py'):
        ast.parse((HERE / name).read_text())
    with tempfile.TemporaryDirectory(prefix='confirmation-source-') as directory:
        tmp = Path(directory)
        assemble(tmp)
        if len(markers()) != 9 or len(set(markers())) != 9:
            raise ValueError('EXACT_CONFIRMATION_CASE_MARKERS_REQUIRED')
        # Checks generated composition, not just input files; no PHP invocation.
        kernel = (tmp / 'kernel-confirmation.php').read_text()
        if kernel.count(str(HERE / 'cases.php')) != 1 or kernel.index(str(HERE / 'cases.php')) > kernel.index("$request('POST','logout'"):
            raise ValueError('ACCEPTANCE_BEFORE_LOGOUT_REQUIRED')
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
        for status, transcript in ((1, raw), (0, raw + b'PASS EXTRA\n'), (0, raw + raw)):
            try:
                runner.verify_transcript(status, transcript)
            except ValueError:
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
