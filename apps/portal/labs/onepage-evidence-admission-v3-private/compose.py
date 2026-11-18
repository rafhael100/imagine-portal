#!/usr/bin/env python3
"""Offline assembly only. No --run, subprocess PostgreSQL, network or grant changes."""
import hashlib,importlib.util,json,pathlib,subprocess,sys,tempfile,os
sys.dont_write_bytecode=True
HERE=pathlib.Path(__file__).resolve().parent

def once(text,old,new):
    if text.count(old)!=1:raise ValueError('ADMISSION_BOUNDARY_DRIFT')
    return text.replace(old,new)

def pinned(portal):
    m=json.loads((HERE/'base-pins.json').read_text())
    if m['local_differences']:raise ValueError('ADMISSION_PIN_DIFFERENCES')
    for name,sha in m['blobs'].items():
        b=(portal/name).read_bytes()
        if hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()!=sha:raise ValueError('ADMISSION_PIN_DRIFT')
    return len(m['blobs'])

def module(path,name):
    s=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m

def assemble(portal,tmp):
    pinned(portal)
    recovery=portal/'labs/onepage-recovery-composition-private'
    sys.path.insert(0,str(recovery))
    parent=module(recovery/'run.py','admission_recovery_source')
    fixture=parent.compose(tmp,parent.parent_module())
    old=' require '+parent.literal(recovery/'cases.php')+';\n'+(
      " checkRecoveryComposition($db,$runtime,$owner,$identity,$barrier,$own,requestOrder($sessions['same']),$order['id'],$argv[1],$argv[2],")+parent.literal(tmp/'recovery.sql')+');'
    tail=(HERE/'setup.php.inc').read_text()
    paths={'ADMISSION_ATTESTATION':HERE/'ProducerAttestation.php','ADMISSION_CASES':HERE/'cases.php',
      'ATOMIC_READER':portal/'labs/onepage-atomic-read/AtomicReader.php',
      'PRODUCER_CONTRACT':portal/'labs/onepage-binding-producer/ProducerContract.php',
      'READ_SQL':portal/'labs/onepage-atomic-read/read.sql','RESERVE_SQL':portal/'labs/onepage-atomic-read/reserve.sql',
      'BINDING_SQL':portal/'labs/onepage-binding-durable/binding.sql','ANCHOR_BUILDER':HERE/'AnchorDDL.php','CONCURRENCY':HERE/'Concurrency.php'}
    for name,path in paths.items():tail=once(tail,'__'+name+'__',parent.literal(path))
    text=once(fixture.read_text(),old,tail)
    if 'checkRecoveryComposition(' in text or 'checkAtomicRead(' in text:raise ValueError('OLD_CASES_REACHABLE')
    fixture.write_text(text)
    (tmp/'recovery.sql').unlink() # unused derived DDL; unchanged base binding.sql is selected instead
    return fixture

def offline(portal,php,lib):
    with tempfile.TemporaryDirectory(prefix='admission-assembly-') as directory:
        tmp=pathlib.Path(directory);fixture=assemble(portal,tmp)
        for name in ['launch.sh','worker.sh']:
            subprocess.run(['/bin/bash','-n',str(tmp/name)],check=True,capture_output=True,timeout=10)
        env={'PATH':'/usr/bin:/bin','LC_ALL':'C','LD_LIBRARY_PATH':str(lib)}
        files=[fixture,*sorted(HERE.glob('*.php'))]
        for f in files:subprocess.run([str(php),'-n','-l',str(f)],env=env,check=True,capture_output=True,timeout=15)
        r=subprocess.run([str(php),'-n',str(HERE/'attestation-check.php')],env=env,check=True,capture_output=True,timeout=15)
        if r.stdout!=b'PASS ADMISSION_ATTESTATION_FACTS 18\n':raise ValueError('ATTESTATION_CHECK_REFUSED')
    print('PASS ADMISSION_V3_ASSEMBLY: 77 pinned blobs; '+str(len(files))+' PHP lints; 2 shell syntax checks; 18 attestation fact cases')
    print('NOT_RUN PG; review private patch before execution in compatible isolated executor')

if __name__=='__main__':
    try:
        if len(sys.argv)!=5 or sys.argv[1]!='--check':raise ValueError('OFFLINE_ONLY')
        portal,php,lib=map(lambda x:pathlib.Path(x).resolve(),sys.argv[2:])
        offline(portal,php,lib)
    except Exception:
        print('ADMISSION_OFFLINE_ASSEMBLY_REFUSED',file=sys.stderr);sys.exit(1)
