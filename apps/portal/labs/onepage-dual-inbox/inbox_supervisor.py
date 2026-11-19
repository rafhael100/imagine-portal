"""Owned synthetic PG18 transport; integrate only inside the existing #962 outer finally."""
import base64
import contextlib
import hashlib
import json
import os
import re
import selectors
import stat
import subprocess
import threading
import time
from pathlib import Path
from processes import OWNED, Owned

PIN_SQL = {
    "inbox.sql": "292b23f7416e34e9b6f6b8ebb9dff6d056426f4b",
    "lease.sql": "03da8af90eca8733fb1842498fd4ac6b50fe8d7d",
}
LIBS = {"protocol_worker.php", "DualDbGate.php", "InboxInstall.php", "Compare.php",
        "Audit.php", "catalog.sql", "F391.semantic.json", "F392.semantic.json"}
EXPECTED_MARK = b"PASS INBOX_INSTALLED\n"
TIMEOUT = 20
MAX_FRAME = 262144

def need(ok):
    if not ok:
        raise RuntimeError("INBOX_BRIDGE_REFUSED")

def sha(data):
    return hashlib.sha256(data).hexdigest()

def no_duplicates(pairs):
    out = {}
    for k, v in pairs:
        need(k not in out)
        out[k] = v
    return out

def json_exact(data):
    return json.loads(data, object_pairs_hook=no_duplicates,
                      parse_constant=lambda _: need(False))

def regular(path, maximum, owner=None):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK)
    try:
        s = os.fstat(fd)
        need(stat.S_ISREG(s.st_mode) and s.st_nlink == 1 and 0 < s.st_size <= maximum
             and (owner is None or s.st_uid == owner))
        with os.fdopen(fd, "rb", closefd=False) as stream:
            b = stream.read(maximum + 1)
        t = os.fstat(fd)
        need(len(b) == s.st_size and (s.st_dev,s.st_ino,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
             == (t.st_dev,t.st_ino,t.st_size,t.st_mtime_ns,t.st_ctime_ns))
        return (s.st_dev,s.st_ino), b
    finally:
        os.close(fd)

def store(path, data):
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW,0o400)
    with os.fdopen(fd,"wb") as f:
        need(f.write(data)==len(data))
    return regular(path,len(data),os.getuid())

class PipeChild(Owned):
    """Use #962 registry and termination; own all three pipes with bounded I/O."""
    def __init__(self, args, env, cwd):
        self.p = subprocess.Popen(args, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                  cwd=cwd, env=env, close_fds=True, start_new_session=True)
        self.pid=self.p.pid
        OWNED.append(self)
        for f in (self.p.stdin,self.p.stdout,self.p.stderr):
            os.set_blocking(f.fileno(),False)
        self.buf=bytearray()

    def _drive(self, deadline, mode, data=b"", limit=MAX_FRAME):
        need(time.monotonic()<deadline)
        sent=0
        with selectors.DefaultSelector() as sel:
            if not self.p.stderr.closed: sel.register(self.p.stderr,selectors.EVENT_READ,"err")
            if not self.p.stdout.closed: sel.register(self.p.stdout,selectors.EVENT_READ,"out")
            if mode=="write": sel.register(self.p.stdin,selectors.EVENT_WRITE,"in")
            while time.monotonic()<deadline:
                if mode=="read" and b"\n" in self.buf:
                    line,_,rest=self.buf.partition(b"\n");self.buf=bytearray(rest)
                    need(len(line)+1<=limit and not self.buf and b"\r" not in line)
                    return bytes(line)+b"\n"
                if mode=="write" and sent==len(data):return
                if mode=="finish" and not sel.get_map():
                    need(not self.buf and self.p.wait(timeout=max(.01,deadline-time.monotonic()))==0)
                    return
                events=sel.select(min(.05,max(0,deadline-time.monotonic())))
                for key,_ in events:
                    f=key.fileobj
                    if key.data=="in":
                        try: sent+=os.write(f.fileno(),data[sent:sent+4096])
                        except BlockingIOError:pass
                        continue
                    try:b=os.read(f.fileno(),4096)
                    except BlockingIOError:continue
                    if not b:
                        sel.unregister(f);f.close()
                        if key.data=="out" and mode!="finish":need(False)
                    elif key.data=="err" or mode=="finish":
                        need(False)   # never forward raw diagnostic output
                    else:
                        self.buf.extend(b);need(len(self.buf)<=limit)
        need(False)

    def send(self,data,deadline):
        need(0<len(data)<=500000)
        self._drive(deadline,"write",data,500000)

    def line(self,deadline,limit=MAX_FRAME):
        return self._drive(deadline,"read",limit=limit)

    def finish(self,deadline):
        self.p.stdin.close()
        self._drive(deadline,"finish")

    def clean(self):
        for f in (self.p.stdin,self.p.stdout,self.p.stderr):
            if not f.closed:f.close()
        need(self.stop()[0])

def command_frame(pipe, expected, deadline):
    r=json_exact(pipe.line(deadline,500000))
    need(set(r)=={"type","bytes"} and r["type"]==expected and isinstance(r["bytes"],str))
    b=base64.b64decode(r["bytes"],validate=True)
    need(0<len(b)<=MAX_FRAME and b.endswith(b"\n"))
    # Defense in depth. Reviewed semantic worker may emit only its staged command.
    includes=[s.strip() for s in b.splitlines() if s.lstrip().startswith(b"\\i ")]
    target={"begin":[],"include_inbox":[b"\\i inbox.sql"],"include_lease":[b"\\i lease.sql"],"final":[]}
    need(includes==target[expected])
    if expected=="final":need(b==b"\\echo PASS INBOX_INSTALLED\n")
    return b

def protocol(sql, semantic, model, independent_process_check, verify_files, deadline):
    """Only local pipes. Caller owns immutable source setup and independent runtime anchor."""
    semantic.send(json.dumps(model,separators=(",",":")).encode()+b"\n",deadline)
    verify_files()
    sql.send(command_frame(semantic,"begin",deadline),deadline)
    for phase,next_cmd in (("BEFORE","include_inbox"),("F391","include_lease"),("F392","final")):
        frame=sql.line(deadline)
        doc=json_exact(frame)
        need(doc.get("phase")==phase and doc.get("nonce")==model["anchor"]["nonce"])
        need(type(doc.get("backend")) is int and doc["backend"]>1)
        independent_process_check(doc["backend"],phase)
        semantic.send(json.dumps({"frame":base64.b64encode(frame).decode()}).encode()+b"\n",deadline)
        nxt=command_frame(semantic,next_cmd,deadline)  # no valid response, no next input
        verify_files()
        sql.send(nxt,deadline)
    need(sql.line(deadline,128)==EXPECTED_MARK)
    sql.finish(deadline)
    semantic.finish(deadline)
    verify_files()

def same_scope(postmaster, root, cluster_id, epoch, port):
    need(postmaster.p.poll() is None and postmaster.pid>1)
    pid=postmaster.pid
    data=root/"data"
    s=root.lstat();need(stat.S_ISDIR(s.st_mode) and s.st_uid==os.getuid() and data.resolve()==data)
    _,raw=regular(data/"postmaster.pid",4096,os.getuid())
    fields=raw.decode("ascii").splitlines()
    need(len(fields)>=8 and fields[0]==str(pid) and fields[1]==str(data) and fields[2]==str(epoch)
         and fields[3]==str(port) and fields[5]=="127.0.0.1" and fields[7].strip()=="ready")
    for ns in ("net","mnt","pid"):
        need(os.readlink(f"/proc/{pid}/ns/{ns}")==os.readlink(f"/proc/self/ns/{ns}"))
    need(re.fullmatch(r"[1-9][0-9]+",cluster_id))

def one_listener(pid,port):
    """Owned postmaster sockets only; exactly one TCP loopback listener, none in tcp6."""
    listening=[]
    for name in ("tcp","tcp6"):
        with open(f"/proc/self/net/{name}",encoding="ascii") as f:
            for line in list(f)[1:]:
                row=line.split()
                if row[3]=="0A" and int(row[1].split(":")[1],16)==port:
                    listening.append((name,row[1],row[9]))
    need(len(listening)==1 and listening[0][:2]==("tcp",f"0100007F:{port:04X}"))
    socket="socket:["+listening[0][2]+"]"
    owners=[]
    for p in Path(f"/proc/{pid}/fd").iterdir():
        try:owners.append(os.readlink(p))
        except FileNotFoundError:pass
    need(socket in owners)

def verify_library_set(source, pins, frozen):
    need(set(pins)==LIBS)
    records={}
    for name,value in pins.items():
        _,b=regular(source/name,MAX_FRAME)
        need(sha(b)==value)
        records[name]=store(frozen/name,b)
    return records

def collect_with_inbox(args,env,pg,root,postmaster,review,bridge,finance):
    """
    review is supplied by #962 supervisor wiring from an independent, reviewed
    phase inventory and owned pg_controldata/postmaster observations; never from
    Laravel stage files or the psql frames. No implicit/default trust.
    """
    need(set(review)=={"model","source","pins","psql_sha","cluster_identity","start_epoch",
                       "post_audit","pg_control_sha","php_sha"} and callable(review["post_audit"]))
    root=Path(root);pg=Path(pg);source=Path(review["source"])
    need(env.get("GITHUB_ACTIONS")=="true" and env.get("PORTAL_NATIVE_ORDER_TEST")=="DISPOSABLE_PG18"
         and env.get("PORTAL_NATIVE_ORDER_TEST_PORT")=="55491" and Path(env["PORTAL_NATIVE_ORDER_TEST_DATA"])==root/"data")
    model=review["model"]
    e=model["anchor"]["expected"]
    port=55491
    need(e["cluster"]==review["cluster_identity"] and e["pid"]==str(postmaster.pid)
         and e["data"]==str(root/"data") and e["port"]==str(port)
         and e["started"].split(".")[0]==str(review["start_epoch"]))
    same_scope(postmaster,root,review["cluster_identity"],review["start_epoch"],port)
    one_listener(postmaster.pid,port)
    control=pg/"pg_controldata"
    need(control.resolve()==control)
    control_record=regular(control,33554432)
    need(sha(control_record[1])==review["pg_control_sha"])
    cenv={"PATH":"/usr/bin:/bin","LC_ALL":"C","LD_LIBRARY_PATH":env["LD_LIBRARY_PATH"]}
    ok,raw,reaped=Owned([str(control),"-D",str(root/"data")],cenv).collect(3,8192)
    records_id=re.findall(rb"^Database system identifier:[ \t]+([1-9][0-9]*)$",raw,re.M)
    need(ok and reaped and records_id==[review["cluster_identity"].encode("ascii")]
         and regular(control,33554432)==control_record)
    psql=pg/"psql";php=Path(args[0])
    need(psql.resolve()==psql and php.resolve()==php)
    psql_record=regular(psql,33554432);need(sha(psql_record[1])==review["psql_sha"])
    php_record=regular(php,33554432);need(sha(php_record[1])==review["php_sha"])
    stage=root/"inbox-bridge";stage.mkdir(mode=0o700)
    frozen=root/"inbox-frozen";frozen.mkdir(mode=0o700)
    records=verify_library_set(source,review["pins"],frozen)
    witness=json.dumps({"model":model,"bridge":bridge,"finance":finance},separators=(",",":")).encode()+b"\n"
    need(len(witness)<=262144)
    witness_pin=store(stage/"supervisor-review.json",witness)
    child=Owned(args,dict(env,DUAL_INBOX_STAGE=str(stage),DUAL_INBOX_REVIEW_SHA=sha(witness)))
    result=[];errors=[]
    def collect():
        try:result.append(child.collect(120))
        except BaseException as exc:errors.append(type(exc).__name__)
    t=threading.Thread(target=collect)
    t.start()
    sql=semantic=None
    deadline=time.monotonic()+100
    try:
        while not (stage/"ready").exists():
            need(not result and not errors and time.monotonic()<deadline)
            time.sleep(.02)
        _,ready=regular(stage/"ready",4096,os.getuid());request=json_exact(ready)
        need(set(request)=={"files"} and set(request["files"])==set(PIN_SQL))
        for name,pin in PIN_SQL.items():
            _,b=regular(stage/name,1048576,os.getuid())
            need(sha(b)==request["files"][name]
                 and hashlib.sha1(b"blob "+str(len(b)).encode()+b"\0"+b).hexdigest()==pin)
            records[name]=store(frozen/name,b)
        def files():
            need(regular(stage/"supervisor-review.json",262144,os.getuid())==witness_pin)
            for n,witness in records.items():
                need(regular(frozen/n,1048576,os.getuid())==witness)
            need(regular(psql,33554432)==psql_record and regular(php,33554432)==php_record)
        observed=[]
        def process_check(backend,phase):
            same_scope(postmaster,root,review["cluster_identity"],review["start_epoch"],port)
            one_listener(postmaster.pid,port)
            status=Path(f"/proc/{backend}/status").read_text()
            need(re.search(r"^PPid:\s+"+str(postmaster.pid)+r"$",status,re.M))
            for ns in ("net","mnt","pid"):
                need(os.readlink(f"/proc/{backend}/ns/{ns}")==os.readlink(f"/proc/self/ns/{ns}"))
            statline=Path(f"/proc/{backend}/stat").read_text()
            start=statline.rsplit(")",1)[1].split()[19] # proc field 22
            witness=(backend,start)
            need(not observed or all(witness==x for x in observed))
            observed.append(witness)
        files()
        penv={"PATH":"/usr/bin:/bin","LC_ALL":"C","LD_LIBRARY_PATH":env["LD_LIBRARY_PATH"],"PHP_INI_SCAN_DIR":""}
        semantic=PipeChild([str(php),"-n","-d","display_errors=0","-d","log_errors=0",
                            str(frozen/"protocol_worker.php")],penv,frozen)
        sql=PipeChild([str(psql),"-X","-w","-qAt","--host=127.0.0.1","--port=55491","--dbname=portal_asaas_ci",
                       "--username=portal_native_test_bootstrap","--file=-"],penv,frozen)
        protocol(sql,semantic,model,process_check,files,min(deadline,time.monotonic()+TIMEOUT))
        sql.clean();semantic.clean()
        need(review["post_audit"]() is True)
        store(stage/"ack.tmp",b"INSTALLED\n")
        os.replace(stage/"ack.tmp",stage/"ack")
        t.join(max(.01,deadline-time.monotonic()))
        need(not t.is_alive() and not errors and len(result)==1)
        return result[0]
    finally:
        cleanup=True
        for ch in (sql,semantic):
            if ch is not None:
                try:ch.clean()
                except BaseException:cleanup=False
        # Laravel child collector owns concurrent stop/wait. #962 outer finally
        # independently verifies all registered children and destroys only its root.
        t.join(130)
        need(cleanup and not t.is_alive())
