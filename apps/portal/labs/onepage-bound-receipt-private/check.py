"""Pin and assemble a one-checkpoint receipt fixture in tmp. Never runs PostgreSQL."""
import hashlib
import os
from pathlib import Path
import subprocess
import sys

sys.dont_write_bytecode=True
here=Path(__file__).resolve().parent
labs=here.parent
pins={
 'onepage-composed-private/run.py':'b0bbb40c0a6d5d8c4c06efdd4772f70e9c1050cc1f2c07d300a3115e87c75445',
 'onepage-composed-private/bound-checkpoint.php':'461107f5fc158fe91071ca452e652488c4f2ffee3386710a8ec8c795c5f7e77f',
 'onepage-composed-private/bound-read.sql.inc':'f18f9d97d55dfa0f29be1f752be6660012c68d1262e85dd4d6c546666f7970e4',
 'onepage-payment-observation/Observation.php':'600fec83e91c8badd2b4bce15b4471b50394ac46253c517f714460bf3f94e3ea',
 'onepage-binding-durable/binding-checks.php':'f524b853420062de197add3ba0903ecdd7e648d29e01c34d1c8f4bb993e009e2',
}
MARKER='PASS BOUND_RECEIPT_INPUT_FIXTURE_MATCH_ONLY'


def once(source,old,new):
    if source.count(old)!=1: raise ValueError('EXACT_HARNESS_CUT_REQUIRED')
    return source.replace(old,new)


def literal(path):
    return "'"+str(path).replace("\\","\\\\").replace("'","\\'")+"'"


def attach_receipt(tmp):
    path=tmp/'bound-binding-checks.php'
    body=path.read_text()
    anchor="    $committedRead=readComposedBinding($b,$proof,$order,'setup-1');"
    plan="    $plan=ProducerContract::prepare"
    if body.index(plan)>=body.index(anchor) or body.rfind('$runtime->commit();',0,body.index(anchor))<0:
        raise ValueError('POST_COMMIT_BOUNDARY_REQUIRED')
    # Explicit fixture values from the existing plan and sentinel are staged
    # independently before the buyer's readback. Native ref never enters the ledger.
    seam="""    require_once """+literal(here/'receipt-check.php')+""";
    $readFixtureAt=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $receiptSource=\\Imagine\\Lab\\OnepageBoundReceipt\\FixtureLedger::stage($plan,$readFixtureAt,'RECEIVED','fixture_verified_clear');
    $pendingSource=\\Imagine\\Lab\\OnepageBoundReceipt\\FixtureLedger::stage($plan,$readFixtureAt,'PENDING','fixture_verified_clear');
    $reversedSource=\\Imagine\\Lab\\OnepageBoundReceipt\\FixtureLedger::stage($plan,$readFixtureAt,'RECEIVED','fixture_reversed');
    $unknownSource=\\Imagine\\Lab\\OnepageBoundReceipt\\FixtureLedger::stage($plan,$readFixtureAt,'RECEIVED','unknown');
"""
    verify="""    \\Imagine\\Lab\\OnepageBoundReceipt\\check($committedRead,$receiptSource,$pendingSource,$reversedSource,
        $unknownSource,$readFixtureAt);
"""
    candidate=once(body,anchor,seam+anchor+'\n'+verify.rstrip())
    if once(candidate,seam+anchor+'\n'+verify.rstrip(),anchor)!=body:
        raise ValueError('EXACT_HARNESS_DERIVATION_REQUIRED')
    path.write_text(candidate)
    lint(path)


def lint(p):
    subprocess.run([os.environ['PHP_BIN'],'-n','-l',str(p)],env={'PATH':'/usr/bin:/bin','LC_ALL':'C'},
                   timeout=30,capture_output=True,check=True)


def main():
    if sys.argv[1:]!=['--check'] or set(os.environ)-{'PATH','LC_ALL','PHP_BIN','PWD','SHLVL','_'} \
       or os.environ.get('LC_ALL')!='C' or not Path(os.environ.get('PHP_BIN','')).is_absolute():
        raise ValueError('OFFLINE_ONLY')
    for rel,want in pins.items():
        p=labs/rel
        if p.resolve()!=p or hashlib.sha256(p.read_bytes()).hexdigest()!=want:
            raise ValueError('EXACT_PARENT_SOURCE_REQUIRED')
    lint(here/'receipt-check.php')
    parent=labs/'onepage-composed-private/run.py'
    code=once(parent.read_text(),'        extend_binding(tmp)','        extend_binding(tmp)\n        attach_receipt(tmp)')
    code=once(code,'[MARKER, BOUND_MARKER, DECISION_MARKER]','[MARKER, BOUND_MARKER, DECISION_MARKER, RECEIPT_MARKER]')
    scope={'__name__':'private_receipt_compose','__file__':str(parent),'attach_receipt':attach_receipt,'RECEIPT_MARKER':MARKER}
    exec(compile(code,str(parent),'exec'),scope)
    scope['main']()
    print('PASS PRIVATE RECEIPT SOURCE COMPOSITION ONLY; no PG or provider read')


if __name__=='__main__':
    try: main()
    except Exception:
        print('PRIVATE_RECEIPT_SOURCE_REFUSED',file=sys.stderr)
        sys.exit(2)
