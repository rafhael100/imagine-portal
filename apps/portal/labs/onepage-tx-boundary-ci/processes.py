"""Bounded owned children only. No global PID discovery, pg_ctl, or shared service."""
import os,selectors,signal,subprocess,time
OWNED=[]
class Owned:
 def __init__(self,args,env,pass_fds=(),capture=True):
  self.p=subprocess.Popen(args,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
    stderr=subprocess.STDOUT if capture else subprocess.DEVNULL,env=env,close_fds=True,pass_fds=pass_fds,start_new_session=True)
  self.pid=self.p.pid
  OWNED.append(self)
 def group_absent(self):
  try:os.killpg(self.pid,0);return False
  except ProcessLookupError:return True
  except PermissionError:return False
 def stop(self,grace=5):
  # Popen retains the direct child's identity until wait/poll; session belongs solely to it.
  if self.p.poll() is not None:
   return self.group_absent(),True  # Never signal a group after reaping its leader.
  self.p.send_signal(signal.SIGINT)
  graceful=True
  try:self.p.wait(timeout=grace)
  except subprocess.TimeoutExpired:
   graceful=False
   # Leader has not been reaped: its PID/session cannot be reused by an unrelated process.
   try:os.killpg(self.pid,signal.SIGKILL)
   except ProcessLookupError:pass
   try:self.p.wait(timeout=2)
   except subprocess.TimeoutExpired:return False,False
  deadline=time.monotonic()+2
  while not self.group_absent() and time.monotonic()<deadline:time.sleep(.02)
  return self.group_absent(),graceful
 def collect(self,seconds=10,limit=4096):
  data=bytearray();sel=selectors.DefaultSelector();sel.register(self.p.stdout,selectors.EVENT_READ)
  deadline=time.monotonic()+seconds;ok=True
  try:
   while sel.get_map():
    if time.monotonic()>=deadline:ok=False;break
    for key,_ in sel.select(.05):
     b=os.read(key.fileobj.fileno(),min(4096,limit+1))
     if not b:sel.unregister(key.fileobj);continue
     data.extend(b)
     if len(data)>limit:ok=False;break
    if not ok:break
   if ok:
    try:ok=self.p.wait(timeout=max(.01,deadline-time.monotonic()))==0
    except subprocess.TimeoutExpired:ok=False
   clean,_=self.stop()
   return ok and clean,bytes(data[:limit]),clean
  finally:
   sel.close();self.p.stdout.close()
   clean,_=self.stop()
   if not clean:raise RuntimeError('OWNED_CLEANUP_UNVERIFIED')
