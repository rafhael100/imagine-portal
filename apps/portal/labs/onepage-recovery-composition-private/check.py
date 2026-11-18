import copy,os,pathlib,subprocess,sys,tempfile,unittest
sys.dont_write_bytecode=True
HERE=pathlib.Path(__file__).resolve().parent
sys.path.insert(0,str(HERE))
import run as r
import preflight as p

def event():
 return {'action':'opened','pull_request':{'draft':True,'commits':1,
  'head':{'sha':'1'*40,'ref':p.HEAD_BRANCH,'repo':{'full_name':p.REPOSITORY}},
  'base':{'sha':p.BASE,'ref':p.BASE_BRANCH,'repo':{'full_name':p.REPOSITORY}}}}
class Checks(unittest.TestCase):
 def test_sources_and_composition(self):
  with tempfile.TemporaryDirectory() as d:
   tmp=pathlib.Path(d);text=r.compose(tmp,r.parent_module()).read_text()
   self.assertEqual(text.count('checkRecoveryComposition('),1);self.assertNotIn('checkAtomicRead(',text)
   self.assertLess(text.index('Guard::configuration('),text.index('$db->unprepared('))
   self.assertLess(text.index('Guard::identity('),text.index('$db->unprepared('))
   sql=(tmp/'recovery.sql').read_text()
   for name in ['fixture_claim_charge','fixture_bind_charge','fixture_read_bound_charge']:
    self.assertEqual(sql.count('CREATE FUNCTION onepage_buyer_lab.'+name+'('),1)
   self.assertNotIn('confirmation',sql);self.assertNotIn('fixture_decision',sql)
   worker=(tmp/'worker.sh').read_text()
   self.assertEqual(worker.count('bounded.py'),5);self.assertIn('trap cleanup EXIT',worker)
   self.assertIn("trap 'exit 124' TERM INT",worker)
 def test_sequences(self):
  r.verify(0,r.REQUIRED)
  for lines in [r.REQUIRED[:-1],r.REQUIRED+[r.MARKERS[0]],list(reversed(r.REQUIRED)),r.REQUIRED[1:],r.REQUIRED+['PASS EXTRA']]:
   with self.assertRaises(ValueError):r.verify(0,lines)
  with self.assertRaises(ValueError):r.verify(1,r.REQUIRED)
 def test_boundary_refusal(self):
  for value in ['absent','anchor anchor']:
   with self.assertRaises(ValueError):r.once(value,'anchor','replacement')
 def test_fake_only(self):
  child=(HERE/'recover-worker.php').read_text()
  self.assertIn('Recovery::inspect($reserved,$reads)',child)
  for forbidden in ['SandboxHttpReads','curl_','https://','http://','fixture_bind_charge']:
   self.assertNotIn(forbidden,child)
 def test_event_refusals(self):
  e=event();p.validate_event(e,p.REPOSITORY,'pull_request')
  for mutate in [lambda e:e.update(action='synchronize'),lambda e:e['pull_request'].update(draft=False),
   lambda e:e['pull_request'].update(commits=2),lambda e:e['pull_request']['base'].update(sha='0'*40),
   lambda e:e['pull_request']['head'].update(ref='wrong'),lambda e:e['pull_request']['head']['repo'].update(full_name='fork/repo')]:
   bad=copy.deepcopy(e);mutate(bad)
   with self.assertRaises(ValueError):p.validate_event(bad,p.REPOSITORY,'pull_request')
  with self.assertRaises(ValueError):p.validate_event(e,p.REPOSITORY,'pull_request_target')
 def test_diff_refusals(self):
  good=''.join('A\0'+name+'\0' for name in sorted(p.ALLOWED)).encode();p.validate_diff(good)
  for bad in [good.replace(b'A\0',b'M\0',1),good+b'A\0extra\0',good[:-1],b'']:
   with self.assertRaises(ValueError):p.validate_diff(bad)
 def test_real_git_single_child_gate(self):
  # Synthetic local Git repository only; no provider/database/network.
  with tempfile.TemporaryDirectory() as d:
   old=os.getcwd();base=p.BASE
   try:
    os.chdir(d)
    def git(*args):return subprocess.check_output(['git','-c','user.name=Fixture','-c','user.email=fixture@example.invalid',*args],stderr=subprocess.DEVNULL).decode().strip()
    git('init');git('commit','--allow-empty','-m','base');p.BASE=git('rev-parse','HEAD')
    for name in p.ALLOWED:
     f=pathlib.Path(name);f.parent.mkdir(parents=True,exist_ok=True);f.write_text('fixture\n')
    git('add','.');git('commit','-m','child');e=event();e['pull_request']['head']['sha']=git('rev-parse','HEAD')
    p.verify(e,p.REPOSITORY,'pull_request')
    probe=pathlib.Path(sorted(p.ALLOWED)[0]);probe.write_text('dirty\n')
    with self.assertRaises(ValueError):p.verify(e,p.REPOSITORY,'pull_request')
    probe.write_text('fixture\n');probe.chmod(0o755);git('add','.');git('commit','--amend','--no-edit')
    e['pull_request']['head']['sha']=git('rev-parse','HEAD')
    with self.assertRaises(ValueError):p.verify(e,p.REPOSITORY,'pull_request')
    probe.chmod(0o644);git('add','.');git('commit','--amend','--no-edit')
    git('commit','--allow-empty','-m','second');e['pull_request']['head']['sha']=git('rev-parse','HEAD')
    with self.assertRaises(ValueError):p.verify(e,p.REPOSITORY,'pull_request')
   finally:p.BASE=base;os.chdir(old)
if __name__=='__main__':unittest.main()
