"""Dormant Actions runner: preserve native preflight, loopback and teardown guards."""
import os
from pathlib import Path
import re
import sys
sys.dont_write_bytecode = True
from check import HERE, ROOT, assemble, markers, native_modules, verify_sources

LAB = 'apps/portal/labs/onepage-confirmation-private/'
WORKFLOW = '.github/workflows/onepage-confirmation-pg-lab.yml'
FILES = ('NextStep.php', 'README.md', 'cases.php', 'check.py', 'confirmation.sql', 'run.py', 'sources.json')


def verify_tree(expected, unused=None):
    manifest = verify_sources()
    _, native = native_modules()
    def git(*args):
        return native.call(['/usr/bin/git', '-C', ROOT, *args], native.ENV | {
            'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_OPTIONAL_LOCKS': '0'}).strip()
    if not re.fullmatch('[a-f0-9]{40}', expected) or git('rev-parse', 'HEAD') != expected:
        raise ValueError('EXACT_COORDINATION_REVIEWED_HEAD_REQUIRED')
    if git('status', '--porcelain', '--untracked-files=all'):
        raise ValueError('CLEAN_SOURCE_REQUIRED')
    # Intended publication composition: #932 + four EXACT #937 lab blobs + this lab.
    # Local equivalent history is deliberately NOT accepted as published #932.
    base = manifest['candidate']
    git('merge-base', '--is-ancestor', base, 'HEAD')
    additions = [WORKFLOW] + [LAB + n for n in FILES]
    additions += ['apps/portal/labs/onepage-native-kernel-private/' + n for n in ('README.md', 'check.py', 'kernel-check.php', 'run.py')]
    if git('diff', '--name-status', '--no-renames', base, 'HEAD').splitlines() != ['A\t' + p for p in sorted(additions)]:
        raise ValueError('EXACT_ADDITIVE_SCOPE_REQUIRED')
    for name in additions:
        if not re.fullmatch(r'100644 blob [a-f0-9]{40}\t' + re.escape(name), git('ls-tree', 'HEAD', '--', name)):
            raise ValueError('PLAIN_REVIEWED_SOURCE_REQUIRED')


def main():
    _, native = native_modules()
    native.verify_tree = verify_tree
    def source(tmp):
        fixture = assemble(tmp)
        for path in (tmp / 'kernel-confirmation.php', HERE / 'cases.php', HERE / 'NextStep.php'):
            native.lint([Path(os.environ['PHP_BIN']), '-n', '-l', path])
        return fixture
    native.source = source
    native.REQUIRED = native.REQUIRED[:-3] + markers() + native.REQUIRED[-3:]
    native.NATIVE = native.NATIVE[:-1] + markers() + native.NATIVE[-1:]
    diagnostic = native.Diagnostic()
    try:
        native.main(diagnostic)
    except Exception:
        print(diagnostic.render(), file=sys.stderr)
        raise SystemExit(2)


if __name__ == '__main__':
    main()
