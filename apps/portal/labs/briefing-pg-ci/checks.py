"""New lifecycle/sequence tests; harmless owned children, no namespace/PG."""
import os,sys,tempfile,copy
from pathlib import Path
from processes import Owned
from scope import erase,exact,WORKER
from launch import SUCCESS
from preflight import verify,BASE,REPO,BRANCH,BASE_BRANCH,FILES
ENV={'PATH':'/usr/bin:/bin','LC_ALL':'C'}
for code,expected in [("print('OK')",True),("raise SystemExit(1)",False),("import time;time.sleep(3)",False),("print('x'*8192)",False)]:
 ok,raw,clean=Owned([sys.executable,'-c',code],ENV).collect(.1)
 assert ok==expected and clean
# Partial init/start failures: exact owned directory removed only after owned children reaped.
for code in ['raise SystemExit(1)','import time;time.sleep(3)']:
 root=Path(tempfile.mkdtemp(prefix='briefing-harmless-'));s=root.lstat();identity=(s.st_dev,s.st_ino,s.st_uid)
 (root/'partial').write_text('synthetic')
 child=Owned([sys.executable,'-c',code],ENV);ok,_,clean=child.collect(.1)
 assert not ok and clean and erase(root,identity)
# Stop the owned child; an unrelated sibling must remain alive.
 sibling=Owned([sys.executable,'-c','import time;time.sleep(3)'],ENV,capture=False)
 target=Owned([sys.executable,'-c','import time;time.sleep(3)'],ENV,capture=False)
 assert target.stop(.2)[0] and sibling.p.poll() is None
 assert sibling.stop(.2)[0]
print('PASS OWNED_LIFECYCLE_CASES 8; NO_PG')
assert exact(b'\n'.join(SUCCESS)+b'\n',SUCCESS)
for lines in [SUCCESS[:-1],SUCCESS[::-1],SUCCESS+[SUCCESS[-1]],[b'RAW']+SUCCESS]:assert not exact(b'\n'.join(lines)+b'\n',SUCCESS)
print('PASS EXACT_SEQUENCE_CASES 5')
p={'repo':{'full_name':REPO}}
e={'action':'opened','repository':{'full_name':REPO},'pull_request':{'draft':True,'head':p|{'sha':'a'*40,'ref':BRANCH},'base':p|{'sha':BASE,'ref':BASE_BRANCH}}}
f={'attempt':'1','head':'a'*40,'parents':[BASE],'clean':True,'changes':{n:'A' for n in FILES},'modes':{n:'100644' for n in FILES}}
assert verify(e,f)=='PASS BRIEFING_EVENT_SCOPE';n=1
for key,value in [('attempt','2'),('head','b'*40),('parents',[]),('changes',{}),('modes',{}),('clean',False)]:
 try:verify(e,f|{key:value})
 except ValueError:n+=1
 else:raise AssertionError()
for action in ['synchronize','reopened']:
 try:verify(e|{'action':action},f)
 except ValueError:n+=1
 else:raise AssertionError()
print('PASS NEW_EVENT_CASES',n)

# Refusal before sudo/namespace/initdb: no child may be constructed.
from unittest.mock import patch
import contextlib,io,launch
with patch.dict(os.environ,{},clear=True),patch.object(sys,'argv',['launch.py','--run']),patch.object(launch,'Owned',side_effect=AssertionError('CHILD_FORBIDDEN')):
 out=io.StringIO()
 with contextlib.redirect_stdout(out):assert launch.main()==1
 assert out.getvalue()=='BRIEFING_LAUNCH_REFUSED cleanup=UNVERIFIED\n'
print('PASS LAUNCH_PREFLIGHT_NO_CHILD 1')
r,w=os.pipe()
try:
 os.set_inheritable(w,True)
 code="import os\ntry: os.write("+str(w)+",b'LEAK');raise SystemExit(1)\nexcept OSError: print('CLOSED')"
 ok,raw,clean=Owned([sys.executable,'-c',code],ENV).collect(2)
 assert ok and clean and raw==b'CLOSED\n'
finally:os.close(r);os.close(w)
print('PASS SUPERVISOR_PRIVATE_FD_CLOSED 1')

from launch import refusal
valid=b'PASS BRIEFING_UID_TOPOLOGY\nPASS BRIEFING_OWNED_CHILDREN_STOPPED_AND_REMOVED\nBRIEFING_RUN_REFUSED phase=START cleanup=VERIFIED\n'
assert refusal(valid)
for raw in [valid+b'RAW\n',valid.replace(b'phase=START',b'phase=SECRET'),valid.replace(b'PASS BRIEFING_UID_TOPOLOGY\n',b'PASS BRIEFING_OWNED_POSTMASTER_READY\n'),valid.replace(b'PASS BRIEFING_OWNED_CHILDREN_STOPPED_AND_REMOVED\n',b'')]:assert not refusal(raw)
print('PASS SANITIZED_FAILURE_SEQUENCE 5')

from launch import partial_refused,PARTIAL
raw=b'\n'.join(PARTIAL)+b'\n'
assert partial_refused(1,False,raw,True)
for args in [(0,False,raw,True),(1,True,raw,True),(1,False,raw,False),(1,False,raw.replace(b'phase=START',b'phase=INIT'),True),(1,False,raw+b'RAW\n',True)]:assert not partial_refused(*args)
print('PASS PARTIAL_START_ACCEPTANCE_MODELS 6; NO_PG')

# Static composition/order: both complete attestations precede migrations/direct DDL.
p=Path(__file__).resolve().parent
s=(p/'NativeBriefingPgTest.php').read_text()
a=s.index("$attestation=\\NativeBriefingFixture\\CiGate::beforeWrites('portal_native_orders_ci')")
b=s.index('$this->admin=\\NativeBriefingFixture\\CiGate::adminBeforeWrites($admin,$attestation)')
c=s.index("Artisan::call('migrate'");d=s.index('$this->admin->exec(')
assert a<b<c<d
assert s.count('$this->admin=')==1 and 'private readonly PDO $admin;' in s
assert s.index('CiGate::sameConnection(',b)<c
w=(p/'worker.php').read_text()
assert w.index("CiGate::beforeWrites('postgres')")<w.index('CiGate::sameConnection(')<w.index("$attestation['pdo']->exec(")
g=(p/'CiGate.php').read_text()
assert 'self::attestSql($pdo,$database)' in g and "self::attestSql($admin,$attestation['database'])" in g
print('PASS ADMIN_GATE_COMPOSITION_ORDER 5; NO_DATABASE')
