"""Offline-only runner gates: no SQL, real PHP execution or PG startup."""
import sys
sys.dont_write_bytecode=True
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
here=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('atomic_runner',here/'run.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=0
with tempfile.TemporaryDirectory(prefix='atomic-offline-') as tmp:
    root=Path(tmp); marker=root/'php-invoked'; spy=root/'php'
    spy.write_text('#!/bin/sh\ntouch "'+str(marker)+'"\nexit 99\n');spy.chmod(0o700)
    for key in ['PGHOSTADDR','PGOPTIONS','PGSERVICE','PHPRC','APP_CONFIG_CACHE']:
        env={'PATH':'/usr/bin:/bin','LC_ALL':'C','PHP_BIN':str(spy),key:'/invalid-synthetic'}
        r=subprocess.run(['/usr/bin/python3',str(here/'run.py'),'--check'],env=env,capture_output=True)
        assert r.returncode==1 and not marker.exists();checks+=1
    old=m.PINS;m.PINS={'onepage-pending-order/prepare.php':'0'*64}
    try:
        try:m.compose(root);raise AssertionError('bad pin accepted')
        except ValueError as e:assert str(e)=='REFERENCE_HASH_CHANGED';checks+=1
    finally:m.PINS=old
complete=m.REQUIRED+['PASS ATOMIC_SQL_CASE: '+c for c in m.CASES]
m.verify_result(0,complete);checks+=1
for code,lines in [(1,complete),(0,[x for x in complete if x!='PASS ORDER_CLUSTER_STOPPED']),
                   (0,complete[:-1]),(0,complete+['PASS ATOMIC_SQL_CASE: other buyer denied'])]:
    try:m.verify_result(code,lines);raise AssertionError('incomplete accepted')
    except ValueError:checks+=1
print(f'PASS ATOMIC_RUNNER_OFFLINE: {checks}; no SQL/startup')
