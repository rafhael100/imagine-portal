import os,pathlib,sys,tempfile,time,unittest
sys.dont_write_bytecode=True
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parent))
from bounded import run,Refused
class Deadline(unittest.TestCase):
 def test_success(self):
  self.assertEqual(run([sys.executable,'-c','print("fixture")'],seconds=2),(0,b'fixture\n'))
 def test_timeout_reaped(self):
  start=time.monotonic()
  with self.assertRaises(Refused):run([sys.executable,'-c','import time;time.sleep(9)'],seconds=.08)
  self.assertLess(time.monotonic()-start,2)
  with self.assertRaises(ChildProcessError):os.waitpid(-1,os.WNOHANG)
 def test_overflow(self):
  with self.assertRaises(Refused):run([sys.executable,'-c','print("x"*90000)'],seconds=2,limit=1024)
 def test_term_ignored(self):
  with self.assertRaises(Refused):run([sys.executable,'-c','import signal,time;signal.signal(signal.SIGTERM,signal.SIG_IGN);time.sleep(9)'],seconds=.15)
  with self.assertRaises(ChildProcessError):os.waitpid(-1,os.WNOHANG)
 def test_cleanup_trap_completed(self):
  with tempfile.TemporaryDirectory() as d:
   marker=pathlib.Path(d)/'cleanup'
   script='trap "exit 124" TERM; trap \'echo FIXTURE_CLEANUP > "$1"\' EXIT; while :; do :; done'
   with self.assertRaises(Refused):run(['/bin/bash','-c',script,'fixture',str(marker)],seconds=.08,grace=2,cleanup_shell=True)
   self.assertEqual(marker.read_text(),'FIXTURE_CLEANUP\n')
   with self.assertRaises(ChildProcessError):os.waitpid(-1,os.WNOHANG)
if __name__=='__main__':unittest.main()
