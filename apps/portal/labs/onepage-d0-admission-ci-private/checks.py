import copy,importlib.util,json,pathlib,sys
sys.dont_write_bytecode=True
HERE=pathlib.Path(__file__).resolve().parent

def load(name):
 spec=importlib.util.spec_from_file_location('ci_'+name,HERE/(name+'.py'));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
p=load('preflight');r=load('runner');count=0

def yes(ok):
 global count
 assert ok;count+=1

def refuses(fn):
 global count
 try:fn()
 except (ValueError,KeyError,TypeError):count+=1
 else:raise AssertionError('NEGATIVE_ACCEPTED')
e={'action':'opened','pull_request':{'draft':True,'commits':1,'head':{'sha':'a'*40,'ref':p.HEAD_BRANCH,'repo':{'full_name':p.REPOSITORY}},'base':{'sha':p.BASE,'ref':p.BASE_BRANCH,'repo':{'full_name':p.REPOSITORY}}}}
yes(p.validate_event(e,p.REPOSITORY,'pull_request')=='a'*40)
for field,value in [('draft',False),('commits',2)]:
 x=copy.deepcopy(e);x['pull_request'][field]=value;refuses(lambda:p.validate_event(x,p.REPOSITORY,'pull_request'))
for side,field,value in [('base','sha','b'*40),('base','ref','main'),('head','ref','unexpected'),('head','sha','not-sha')]:
 x=copy.deepcopy(e);x['pull_request'][side][field]=value;refuses(lambda:p.validate_event(x,p.REPOSITORY,'pull_request'))
x=copy.deepcopy(e);x['pull_request']['head']['repo']['full_name']='fork/repo';refuses(lambda:p.validate_event(x,p.REPOSITORY,'pull_request'))
x=copy.deepcopy(e);x['action']='synchronize';refuses(lambda:p.validate_event(x,p.REPOSITORY,'pull_request'))
refuses(lambda:p.validate_event(e,'wrong/repo','pull_request'));refuses(lambda:p.validate_event(e,p.REPOSITORY,'push'))
raw=''.join('A\0'+s+'\0' for s in sorted(p.ALLOWED)).encode();p.validate_diff(raw);yes(len(p.ALLOWED)==18)
for bad in [raw.replace(b'A\0',b'M\0',1),raw+b'A\0extra\0',raw[:-1],b'',raw.replace(next(iter(p.ALLOWED)).encode(),b'other')]:refuses(lambda:p.validate_diff(bad))
r.proof(0,r.REQUIRED);yes(True)
for i in range(len(r.REQUIRED)):
 refuses(lambda i=i:r.proof(0,r.REQUIRED[:i]+r.REQUIRED[i+1:]))
refuses(lambda:r.proof(1,r.REQUIRED));refuses(lambda:r.proof(0,list(reversed(r.REQUIRED))))
refuses(lambda:r.proof(0,r.REQUIRED+[r.REQUIRED[-1]]));refuses(lambda:r.proof(0,r.REQUIRED+['PASS UNEXPECTED']))
yes(r.diagnostic('WORKER',r.REQUIRED[:-1]).endswith('cleanup=UNVERIFIED'))
yes(r.diagnostic('WORKER',r.REQUIRED).endswith('cleanup=VERIFIED'))
yes('password' not in r.diagnostic('password',['PDO password secret SQL']))
print('PASS ADMISSION_CI_GATES_AND_SEQUENCE '+str(count))
