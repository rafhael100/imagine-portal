#!/usr/bin/env python3
"""Compose the pinned #925 runner privately; --check never starts PostgreSQL."""
import hashlib
import importlib.util
import pathlib
import subprocess
import sys

sys.dont_write_bytecode = True
HERE = pathlib.Path(__file__).resolve().parent
PARENT = HERE.parent / 'onepage-atomic-read'
PINS = {
    'run.py': 'e8f64558f9892788902c85a466061daee11ce6c23cf558b3ed927e0e97a96f7e',
    'pg-checks.php': 'f7a8212d2b97c2b40bfd20cced22b6a61e466302e16c750bebc5d2465607d327',
    'reserve-checks.php': 'e7bd448a90988c9343366a55539f7458d9a07994d4ea0ca56db61200c3a82258',
    'reserve.sql': '580a90bf43cc226d860f3e8b2e09e83193274fdf70ae068b750854ba34805ed5',
}
MARKER = 'PASS BINDING_DURABLE: one exact link, rollback and revocation, no release'


def main():
    for name, expected in PINS.items():
        if hashlib.sha256((PARENT / name).read_bytes()).hexdigest() != expected:
            raise ValueError('PIN_REQUIRED')
    spec = importlib.util.spec_from_file_location('binding_parent', PARENT / 'run.py')
    parent = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(parent)
    prior = parent.compose

    def compose(tmp):
        fixture = prior(tmp)
        hook = tmp / 'atomic-plus-binding.php'
        # Only the scratch composition changes. Convert the pinned old __DIR__
        # to its original source dir before injecting the new hook.
        literal = lambda p: "'" + str(p).replace("\\", "\\\\").replace("'", "\\'") + "'"
        source = (PARENT / 'pg-checks.php').read_text().replace('__DIR__', literal(PARENT))
        after_reserve = ' $checkRevokedReservation=checkChargeReservation($db,$runtime,$identity,$pinned,$own,$sameTenantOther,$otherTenant,$first);'
        source = parent.replace_once(source, after_reserve, after_reserve + (
            '\n require ' + literal(HERE / 'binding-checks.php') + ';'
            '\n $bindingAfterRevocation=checkDurableFixtureBinding($db,$runtime,$identity,$pinned,$own,$sameTenantOther,$otherTenant,$first);'))
        source = parent.replace_once(source, ' $checkRevokedReservation();',
                                     ' $checkRevokedReservation();\n $bindingAfterRevocation();')
        hook.write_text(source)
        fixture.write_text(parent.replace_once(fixture.read_text(), literal(PARENT / 'pg-checks.php'), literal(hook)))
        import os
        for path in (hook, HERE / 'binding-checks.php'):
            subprocess.run([os.environ['PHP_BIN'], '-n', '-l', str(path)],
                           env={'PATH': '/usr/bin:/bin'}, check=True, capture_output=True)
        return fixture

    parent.compose = compose
    parent.REQUIRED = parent.REQUIRED + [MARKER]
    parent.main()


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('BINDING_RUN_FAILED: preflight, composition, SQL or cleanup incomplete', file=sys.stderr)
        sys.exit(1)
