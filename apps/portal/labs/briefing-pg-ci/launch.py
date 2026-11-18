"""Outer deadline is last resort, never evidence of privileged teardown."""
import os,sys,re
from pathlib import Path
from processes import Owned
from scope import exact,WORKER
HERE=Path(__file__).resolve().parent
SUCCESS=[b'PASS BRIEFING_UID_TOPOLOGY',b'PASS BRIEFING_OWNED_POSTMASTER_READY',*WORKER,
 b'PASS BRIEFING_OWNED_CHILDREN_STOPPED_AND_REMOVED',b'PASS BRIEFING_RUN_COMPLETE']
def refusal(raw):
 lines=raw.splitlines()
 if not lines or not raw.endswith(b'\n'):return False
 if re.fullmatch(rb'BRIEFING_RUN_REFUSED phase=(PREFLIGHT|INIT|START|WORKER) cleanup=(VERIFIED|UNVERIFIED)',lines[-1]) is None:return False
 preceding=lines[:-1]
 if lines[-1].endswith(b'cleanup=VERIFIED'):
  if not preceding or preceding[-1]!=SUCCESS[-2]:return False
  preceding=preceding[:-1]
 return len(preceding)<=6 and preceding==SUCCESS[:len(preceding)]
PARTIAL=[b'PASS BRIEFING_UID_TOPOLOGY',b'PASS BRIEFING_OWNED_CHILDREN_STOPPED_AND_REMOVED',b'BRIEFING_RUN_REFUSED phase=START cleanup=VERIFIED']
def partial_refused(code,ok,raw,clean):
 return code==1 and not ok and clean and exact(raw,PARTIAL)
def main():
 try:
  if sys.argv[1:]!=['--run'] or os.environ.get('GITHUB_ACTIONS')!='true' or os.geteuid()==0:raise ValueError()
  args=[str(os.getuid()),str(os.getgid()),os.environ['PHP_BIN'],os.environ['POSIX_EXTENSION'],os.environ['PG_BIN'],os.environ['ONEPAGE_PG_RUNTIME_LIB'],os.environ['BRIEFING_PHP_INI']]
  prefix=['/usr/bin/sudo','-n','/usr/bin/timeout','--signal=TERM','--kill-after=15s','220s','/usr/bin/python3','-I',str(HERE/'root_wrapper.py'),*args]
  # Separate fresh namespace/cluster: reject a deliberately invalid postmaster setting,
  # then require exact cleanup/reap before the functional briefing instance can start.
  fault=Owned(prefix+['PARTIAL_START'],{'PATH':'/usr/bin:/bin','LC_ALL':'C'})
  ok,raw,clean=fault.collect(240)
  if not partial_refused(fault.p.returncode,ok,raw,clean):raise ValueError()
  print('PASS BRIEFING_PARTIAL_START_REFUSED_AND_CLEANED')
  ok,raw,clean=Owned(prefix+['BRIEFING'],{'PATH':'/usr/bin:/bin','LC_ALL':'C'}).collect(240)
  if not ok or not exact(raw,SUCCESS):
   if clean and refusal(raw):
    for line in raw.splitlines():print(line.decode())
    return 1
   raise ValueError()
  for line in SUCCESS:print(line.decode())
  print('PASS BRIEFING_NAMESPACE_REAPED');return 0
 except BaseException:print('BRIEFING_LAUNCH_REFUSED cleanup=UNVERIFIED');return 1
if __name__=='__main__':sys.exit(main())
