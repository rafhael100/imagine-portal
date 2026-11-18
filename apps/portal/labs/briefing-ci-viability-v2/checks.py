"""Only synthetic events and owned harmless children; no namespace/PG."""
import copy,sys
from preflight import verify,BASE,REPO,BRANCH,BASE_BRANCH,FILES
from supervisor import bounded
p={'repo':{'full_name':REPO}}
e={'action':'opened','repository':{'full_name':REPO},'pull_request':{'draft':True,'head':p|{'sha':'a'*40,'ref':BRANCH},'base':p|{'sha':BASE,'ref':BASE_BRANCH}}}
f={'attempt':'1','head':'a'*40,'parents':[BASE],'clean':True,'changes':{n:'A' for n in FILES},'modes':{n:'100644' for n in FILES}}
assert verify(e,f)=='PASS VIABILITY_EVENT_SCOPE';n=1
def refuse(e,f):
 global n
 try:verify(e,f)
 except ValueError:n+=1
 else:raise AssertionError('MUST_REFUSE')
for key,v in [('attempt','2'),('head','b'*40),('parents',[BASE,BASE]),('clean',False),('changes',{}),('modes',{})]:refuse(e,f|{key:v})
for action in ('synchronize','reopened'):refuse(e|{'action':action},f)
x=copy.deepcopy(e);x['pull_request']['draft']=False;refuse(x,f)
x=copy.deepcopy(e);x['pull_request']['head']['repo']={'full_name':'other/repo'};refuse(x,f)
print('PASS EVENT_PREDICATES',n)
cases=[("print('PASS PHP_UID_NAMESPACE_FACTS')",True),
 ("print('RAW_UNEXPECTED')",False),("print('PASS PHP_UID_NAMESPACE_FACTS');print('PASS PHP_UID_NAMESPACE_FACTS')",False),
 ("raise SystemExit(1)",False),("import time;time.sleep(5)",False),("print('x'*8192)",False)]
for source,expected in cases:
 good,clean=bounded([sys.executable,'-c',source],seconds=.15)
 assert good is expected and clean
print('PASS CHILD_REFUSAL_REAP_CASES',len(cases),'NO_PRIVILEGED_CHILD')

# Deliberately inheritable pipe must not reach any child. No public/log FD is used.
import os
r,w=os.pipe()
try:
 os.set_inheritable(w,True)
 code="import os\ntry: os.write("+str(w)+",b'LEAK');raise SystemExit(1)\nexcept OSError: print('PASS PHP_UID_NAMESPACE_FACTS')"
 assert bounded([sys.executable,'-c',code])==(True,True)
finally:os.close(r);os.close(w)
print('PASS FD_INHERITANCE_CLOSED 1')

# Root-observed topology validation is pure here; no snapshot/pipe/exec is called.
from root_wrapper import validate,validate_stable,pipe_in
from unittest.mock import patch
from types import SimpleNamespace
b={'parent_net':'net:[1]','parent_mnt':'mnt:[1]','parent_pid':'pid:[1]','init_net':'net:[1]','init_pid':'pid:[1]'}
a=b|{'parent_net':'net:[2]','parent_mnt':'mnt:[2]'}
validate_stable(b,a,a);t=1
for obj,key,value in [('before','parent_pid','pid:[9]'),('before','init_pid','pid:[9]'),('after','init_net','net:[9]'),('after','parent_pid','pid:[2]'),('after','parent_net','net:[1]'),('after','parent_mnt','mnt:[1]'),('after','init_pid','pid:[2]'),('after','parent_net','invalid')]:
 bb=b.copy();aa=a.copy();(bb if obj=='before' else aa)[key]=value
 try:validate(bb,aa)
 except ValueError:t+=1
 else:raise AssertionError('TOPOLOGY_ACCEPTED')
try:validate_stable(b,a,a|{'parent_mnt':'mnt:[3]'})
except ValueError:t+=1
else:raise AssertionError('DRIFT_ACCEPTED')
for uid,mode in [(1001,0o010600),(0,0o100600)]:
 with patch('root_wrapper.os.fstat',return_value=SimpleNamespace(st_uid=uid,st_mode=mode)):
  try:pipe_in()
  except ValueError:t+=1
  else:raise AssertionError('UNTRUSTED_PIPE_ACCEPTED')
print('PASS ROOT_TOPOLOGY_PROVENANCE_MODELS',t,'NO_ROOT_OR_PROC_READ')
