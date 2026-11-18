"""Inert source/policy verification, not PostgreSQL HBA parsing or execution."""
from hashlib import sha256
from pathlib import Path
import sys

LABS=Path(__file__).resolve().parent.parent
DB='portal_onepage_buyer_ci'
BOOT='onepage_lab_bootstrap'
ROLES=(BOOT,'portal_browser_runtime','onepage_buyer_runtime',
       'onepage_fixture_receipt_observer','onepage_fixture_receipt_writer')
EXPECTED=(
    ('local','all','all','reject'),('local','replication','all','reject'),
    ('host','postgres',BOOT,'127.0.0.1/32','scram-sha-256'),
    *tuple(('host',DB,r,'127.0.0.1/32','scram-sha-256') for r in ROLES),
    ('host','replication','all','all','reject'),('host','all','all','all','reject'),
)
PIN={
    'onepage-pending-order/run-clean.sh':'97159aebd68f956d945c6be572f144a8eb4f20d97f04fd349b926f224906fe4c',
    'onepage-pending-order/pg-cases.php':'f57a0bcac5d5902a7d8a3a884064fa0bf5ef07aa39107b80158d5a3841f5c0f3',
    'onepage-pending-order/prepare.php':'580312a7a6308ad2a053cb14c0e0da489da1e3a9b5ecbb3be2da815cdfe7255e',
    'onepage-atomic-read/reserve-checks.php':'e7bd448a90988c9343366a55539f7458d9a07994d4ea0ca56db61200c3a82258',
    'onepage-binding-durable/binding-checks.php':'f524b853420062de197add3ba0903ecdd7e648d29e01c34d1c8f4bb993e009e2',
    'onepage-buyer/Guard.php':'8dd7884bc95337327a9d93cf047fea28f2fb14173cba238a0913b8fc3cc0ded1',
    'onepage-bound-receipt-durable-private/receipt.sql.inc':'8314d0cfc3f68f77c64938b2f21270b64a3aab84f056d3447e2f2b3d8da8531d',
    'onepage-bound-receipt-durable-private/checkpoint.php':'ce002fe76a3b5956d2c40d5135abd8f9699c5a6ac4f39d08e4f33d25855e8928',
}
ANCHORS={
    'onepage-pending-order/run-clean.sh':('--auth-local=trust --auth-host=trust',"DB_PASSWORD=''"),
    'onepage-pending-order/pg-cases.php':(",$role,'',[PDO::ATTR_ERRMODE",),
    'onepage-atomic-read/reserve-checks.php':(",'onepage_buyer_runtime','',",),
    'onepage-binding-durable/binding-checks.php':(",'onepage_buyer_runtime','',",),
}


def validate(rows):
    if rows!=EXPECTED:
        raise ValueError('EXACT_HBA_RULES_REQUIRED')


def auth_model(rows,transport,db,role,address):
    # Exhaustive for the exact grammar above; no PostgreSQL parser dispatch.
    for rule in rows:
        if rule[0]!=transport: continue
        if rule[1]!='all' and rule[1]!=db: continue
        if db=='replication' and rule[1]!='replication': continue
        if rule[2]!='all' and rule[2]!=role: continue
        if transport=='host' and rule[3]!='all' and address!='127.0.0.1': continue
        return rule[-1]
    return 'reject'


def main():
    if sys.argv[1:]!=['--check']:
        raise ValueError('OFFLINE_ONLY')
    for rel,digest in PIN.items():
        p=LABS/rel
        raw=p.read_bytes()
        if p.resolve()!=p or sha256(raw).hexdigest()!=digest:
            raise ValueError('EXACT_SOURCE_REQUIRED')
        if any(raw.decode().count(anchor)!=1 for anchor in ANCHORS.get(rel,())):
            raise ValueError('UNIQUE_DERIVATION_CUT_REQUIRED')
    rules=tuple(tuple(line.split()) for line in (Path(__file__).parent/'pg_hba.fixture.conf').read_text().splitlines()
                if line.strip() and not line.lstrip().startswith('#'))
    validate(rules)
    denial_variants=(
       rules[1:],rules[:-1],rules+(('host','all','all','all','trust'),),
       (('host','all','all','all','trust'),)+rules,
       tuple((row[:-1]+('trust',)) if row[-1]=='scram-sha-256' else row for row in rules),
    )
    for bad in denial_variants:
        try:validate(bad)
        except ValueError:continue
        raise ValueError('BAD_HBA_ACCEPTED')
    checks=0
    for channel in ('local','host'):
        for addr in ('127.0.0.1','127.0.0.2','::1','192.0.2.1'):
            for db in ('postgres',DB,'replication','unknown'):
                for role in (*ROLES,'unknown'):
                    allowed=channel=='host' and addr=='127.0.0.1' and (db==DB and role in ROLES or db=='postgres' and role==BOOT)
                    if auth_model(rules,channel,db,role,addr)!=('scram-sha-256' if allowed else 'reject'):
                        raise ValueError('HBA_MODEL_DENIAL_REQUIRED')
                    checks+=1
    print(f'PASS INERT SCRAM PROFILE: {checks} policy vectors; no auth, role, file installation, socket or SQL')


if __name__=='__main__':
    try:main()
    except Exception:
        print('INERT_SCRAM_PROFILE_REFUSED',file=sys.stderr)
        sys.exit(2)
