"""Restricted source-only preflight of the dormant receipt checkpoint."""
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys

here=Path(__file__).resolve().parent
parent=here.parent/'onepage-bound-receipt-private'
pins={'check.py':'3d97e41bc2e2140c88909982a05e45c6c5c3daf671e9f5ed45d96793fb4805f4',
      'receipt-check.php':'25cce5c6a17a858bce9837e79a0ce95d11d5b9650d399e9b90dbb491a2fa92a0'}


def main():
    if sys.argv[1:]!=['--check'] or set(os.environ)-{'PATH','LC_ALL','PHP_BIN','PWD','SHLVL','_'}        or os.environ.get('LC_ALL')!='C' or not Path(os.environ.get('PHP_BIN','')).is_absolute():
        raise ValueError('OFFLINE_ONLY')
    for name,digest in pins.items():
        f=parent/name
        if f.resolve()!=f or hashlib.sha256(f.read_bytes()).hexdigest()!=digest:
            raise ValueError('EXACT_RECEIPT_PARENT_REQUIRED')
    sql=(here/'receipt.sql.inc').read_text()
    # Shape checks only, not a PG parser or claims of successful SQL.
    if len(re.findall(r'CREATE ROLE [a-z_]+ NOLOGIN ',sql))!=2 or sql.count('FORCE ROW LEVEL SECURITY')!=2        or sql.count('SECURITY DEFINER')!=2 or len(re.findall(r'GRANT EXECUTE ON FUNCTION ',sql))!=2        or re.search(r'\b(ALTER ROLE|GRANT UPDATE|GRANT DELETE|SET ROLE)\b',sql)        or sql.index('DO $guard$')>=sql.index('CREATE ROLE') or sql.count(' AS MATERIALIZED (')!=1:
        raise ValueError('DORMANT_SQL_PROFILE_REQUIRED')
    args=[os.environ['PHP_BIN'],'-n','-l',str(here/'checkpoint.php')]
    subprocess.run(args,capture_output=True,check=True,timeout=30,env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
    subprocess.run(['/usr/bin/python3',str(parent/'check.py'),'--check'],check=True,timeout=60)
    print('PASS PRIVATE RECEIPT DURABILITY SOURCE ONLY; no SQL, login, provider or runner execution')


if __name__=='__main__':
    try: main()
    except Exception:
        print('PRIVATE_DURABLE_RECEIPT_SOURCE_REFUSED',file=sys.stderr)
        sys.exit(2)
