"""Reviewed root topology observer only. No DB, ptrace, capabilities for PHP."""
import os,sys,json,re,stat,subprocess
from pathlib import Path
FD=9
KEYS={'parent_net','parent_mnt','parent_pid','init_net','init_pid'}
def need(v):
 if not v:raise ValueError('ROOT_TOPOLOGY_REFUSED')
def snapshot():
 return {'parent_'+k:os.readlink('/proc/self/ns/'+k) for k in ('net','mnt','pid')}|{'init_'+k:os.readlink('/proc/1/ns/'+k) for k in ('net','pid')}
def validate(before,now):
 need(set(before)==KEYS and set(now)==KEYS)
 for f in (before,now):
  for k,v in f.items():need(isinstance(v,str) and re.fullmatch(k.rsplit('_',1)[1]+r':\[[0-9]+\]',v) is not None)
 need(before['parent_pid']==before['init_pid']==now['parent_pid']==now['init_pid'])
 need(before['init_net']==now['init_net'])
 need(before['parent_net']!=now['parent_net'] and before['init_net']!=now['parent_net'])
 need(before['parent_mnt']!=now['parent_mnt'])
def validate_stable(before,now,after):
 validate(before,now);validate(before,after);need(after==now)
def pipe_out(data):
 # Root-created anonymous pipe: read end only, no file/path/env supplied by PHP.
 raw=json.dumps(data,separators=(',',':')).encode();need(len(raw)<2048)
 r,w=os.pipe()
 try:need(os.write(w,raw)==len(raw))
 finally:os.close(w)
 if r!=FD:os.dup2(r,FD,inheritable=True);os.close(r)
 os.set_inheritable(FD,True)
def pipe_in():
 s=os.fstat(FD);need(s.st_uid==0 and stat.S_ISFIFO(s.st_mode))
 with os.fdopen(FD,'rb') as f:raw=f.read(2049)
 need(len(raw)<2048);return json.loads(raw)
def call(args):
 subprocess.run(args,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,close_fds=True,timeout=3,check=True)
def main():
 need(os.geteuid()==0)
 inside=sys.argv[1:2]==['--inside'];args=sys.argv[2:] if inside else sys.argv[1:]
 need(len(args)==8);uid,gid,php,extension,pgbin,library,ini,mode=args
 need(mode in ('PARTIAL_START','BRIEFING'))
 need(uid.isdecimal() and gid.isdecimal() and int(uid)>0 and int(gid)>0)
 need(os.environ.get('SUDO_UID')==uid and os.environ.get('SUDO_GID')==gid)
 need(Path(php).is_absolute() and Path(php).is_file())
 need(extension=='builtin' or (Path(extension).is_absolute() and Path(extension).is_file()))
 here=Path(__file__).resolve().parent
 if not inside:
  before=snapshot();need(before['parent_pid']==before['init_pid']);pipe_out(before)
  env={'PATH':'/usr/bin:/bin','LC_ALL':'C','SUDO_UID':uid,'SUDO_GID':gid}
  os.execve('/usr/bin/unshare',['unshare','--net','--mount','--propagation','private','--','/usr/bin/python3','-I',str(here/'root_wrapper.py'),'--inside',*args],env)
 before=pipe_in();now=snapshot();validate(before,now)
 call(['/usr/bin/mount','--make-rprivate','/'])
 call(['/usr/sbin/ip','link','set','lo','up'])
 call(['/usr/bin/umount','--recursive','/sys'])
 call(['/usr/bin/mount','-t','sysfs','-o','ro,nosuid,nodev,noexec','sysfs','/sys'])
 after=snapshot();validate_stable(before,now,after)
 topology=before|{'child_'+k:after['parent_'+k] for k in ('net','mnt','pid')};pipe_out(topology)
 need(Path(pgbin).is_absolute() and (Path(pgbin)/'postgres').is_file())
 need(Path(library).is_absolute() and Path(library).is_dir())
 need(Path(ini).is_absolute() and Path(ini).is_file())
 cmd=['/usr/bin/python3','-I',str(here/'scope.py'),uid,gid,php,extension,pgbin,library,ini,mode]
 env={'PATH':'/usr/bin:/bin','LC_ALL':'C','GITHUB_ACTIONS':'true','BRIEFING_SCOPE':'BRIEFING_VIABILITY_ONLY'}
 os.execve('/usr/bin/setpriv',['setpriv','--reuid='+uid,'--regid='+gid,'--clear-groups','--bounding-set=-all','--inh-caps=-all','--ambient-caps=-all','--no-new-privs',*cmd],env)
if __name__=='__main__':
 try:main()
 except Exception:os.write(2,b'ROOT_TOPOLOGY_REFUSED\n');sys.exit(1)
