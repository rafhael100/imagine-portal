"""Bounded direct-child I/O. The PostgreSQL cleanup shell is never SIGKILLed."""
import os
import selectors
import subprocess
import sys
import time
class Refused(RuntimeError):
    pass

def run(command, *, seconds, grace=.3, cleanup_shell=False, env=None, limit=1048576):
    p=subprocess.Popen(command,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,env=env)
    stream=p.stdout; os.set_blocking(stream.fileno(),False)
    poll=selectors.DefaultSelector();poll.register(stream,selectors.EVENT_READ)
    end=time.monotonic()+seconds; stopped=None; rejected=False; output=bytearray()
    try:
        while poll.get_map() or p.poll() is None:
            now=time.monotonic()
            if stopped is None and (now>=end or rejected):
                rejected=True
                if p.poll() is None:p.terminate()
                stopped=now
            if stopped is not None and now>=stopped+grace:
                if p.poll() is None:
                    if cleanup_shell:raise Refused('CLEANUP_UNVERIFIED_EXTERNAL_JOB_LIMIT_REQUIRED')
                    p.kill();p.wait(timeout=2)
                break
            for key,_ in poll.select(.02):
                chunk=os.read(key.fd,8192)
                if not chunk:poll.unregister(key.fileobj)
                elif len(output)+len(chunk)>limit:rejected=True
                elif not rejected:output.extend(chunk)
        code=p.wait(timeout=1)
        if rejected:raise Refused('CHILD_DEADLINE_OR_OUTPUT_LIMIT')
        return code,bytes(output)
    finally:
        poll.close();stream.close()
        if p.poll() is None and not cleanup_shell:p.kill();p.wait(timeout=2)

if __name__=='__main__':
    try:
        if len(sys.argv)<4 or sys.argv[1]!='--':raise Refused('ARGUMENTS')
        code,data=run(sys.argv[2:],seconds=120)
        sys.stdout.buffer.write(data) # private parent log only
        sys.exit(code)
    except Exception:
        print('RECOVERY_OWNED_PROCESS_REFUSED',file=sys.stderr);sys.exit(1)
