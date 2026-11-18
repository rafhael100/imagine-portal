"""No PG. Bounded child, exact output, process-group termination evidence."""
import os,sys,time,signal,selectors,subprocess
from pathlib import Path
SUCCESS=b'PASS PHP_UID_NAMESPACE_FACTS\n'
def alive(pid):
 try:os.killpg(pid,0);return True
 except ProcessLookupError:return False
 except PermissionError:return True

def bounded(command,seconds=30,limit=4096):
 child=subprocess.Popen(command,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,
   env={'PATH':'/usr/bin:/bin','LC_ALL':'C'},close_fds=True,start_new_session=True)
 data=bytearray();failure=False;deadline=time.monotonic()+seconds
 sel=selectors.DefaultSelector();sel.register(child.stdout,selectors.EVENT_READ)
 try:
  while sel.get_map():
   if time.monotonic()>deadline:failure=True;break
   for key,_ in sel.select(0.05):
    chunk=os.read(key.fileobj.fileno(),4097)
    if not chunk:sel.unregister(key.fileobj);continue
    data.extend(chunk)
    if len(data)>limit:failure=True;break
   if failure:break
  if failure:
   try:os.killpg(child.pid,signal.SIGTERM)
   except (ProcessLookupError,PermissionError):pass
  try:code=child.wait(timeout=3)
  except subprocess.TimeoutExpired:
   try:os.killpg(child.pid,signal.SIGKILL)
   except (ProcessLookupError,PermissionError):pass
   try:child.wait(timeout=2)
   except subprocess.TimeoutExpired:pass
   code=1;failure=True
  clean=child.poll() is not None and not alive(child.pid)
  return (not failure and code==0 and bytes(data)==SUCCESS and clean),clean
 finally:sel.close();child.stdout.close()

def main():
 try:
  if sys.argv[1:]!=['--ci-probe'] or os.environ.get('GITHUB_ACTIONS')!='true' or os.geteuid()==0:raise ValueError()
  here=Path(__file__).resolve().parent;php=Path(os.environ['PHP_BIN']).resolve();extension=os.environ['POSIX_EXTENSION']
  if not php.is_file() or not php.is_absolute() or (extension!='builtin' and not Path(extension).is_file()):raise ValueError()
  # Root wrapper alone observes PID1, on both sides of unshare. PHP receives a root pipe.
  command=['/usr/bin/sudo','-n','/usr/bin/timeout','--signal=TERM','--kill-after=2s','20s',
   '/usr/bin/python3','-I',str(here/'root_wrapper.py'),str(os.getuid()),str(os.getgid()),str(php),extension]
  good,clean=bounded(command)
  if not good:
   # Privileged wrapper failure cannot prove all root descendants gone from this UID.
   print('VIABILITY_REFUSED cleanup=UNVERIFIED');return 1
  print('PASS PHP_UID_NAMESPACE_FACTS');print('PASS NAMESPACE_CHILD_REAPED');print('PASS VIABILITY_ONLY_NO_DATABASE');return 0
 except Exception:print('VIABILITY_REFUSED cleanup=UNVERIFIED');return 1
if __name__=='__main__':sys.exit(main())
