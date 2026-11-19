"""One alternate #966 worker. Retains inherited namespace/ownership cleanup."""
import hashlib, json, os, re, time
from pathlib import Path
from inbox_supervisor import PipeChild, same_scope, one_listener, regular, need, json_exact
from processes import Owned
CONTROL="c56df04491bb6f067adae6ec86e65132828da2fe82340370d5a9770488fbf2f9"
class AtomicRefused(ValueError):
    pass
PHASES=("ATOMIC_BOOTSTRAP","ATOMIC_DDL","ATOMIC_CATALOG","ATOMIC_ROLLBACK","ATOMIC_COMMIT_LOSS","ATOMIC_DUPLICATE","ATOMIC_CAPTURE_FIRST","ATOMIC_SCOPE","ATOMIC_REVOKE_FIRST")
ASSETS={'Fixture.php': '09adf7b8f20b627c1de8888bba9f82a257ea47888be5ddcc20e5e9a8d8d32328', 'harness.php': '1e479048527859ffde7703e1a2f054ee4f95c41fcd395a7638da3fff9b7bb6c3', 'AtomicInboxPort.php': '615152bfc015b04c39fe23a0109eba51c8f6ba0bb6c24f68d0e6fb63c448e612', 'Ingress.php': 'ebc88f46e49cd3b60195631332afa2e71823782066b4404c9f26bac657d2d8c8', 'atomic-capture.candidate.sql': '69252f1e50e1a20a2f1aa1d9e9d9985de3adab63742f7d27419035387a713775', 'pre.sql': '22f6a7db366e69d2a3d37216f38f088ea3ff747799aeb587b131f323c1b8d76c', 'post.sql': '8545612528bd0fdbac3a01de62f7675ec71ad04a7d047a431e9ec6f6cfefb999'}  # sealed offline by assemble.py

def run(folder,php,ini,pg,root,cluster,env):
    port=55491
    _, pidfile=regular(root/"data"/"postmaster.pid",4096,os.getuid())
    epoch=int(pidfile.decode().splitlines()[2])
    control=Path(pg)/"pg_controldata"
    rec=regular(control,1048576)
    need(hashlib.sha256(rec[1]).hexdigest()==CONTROL)
    penv={k:env[k] for k in ("PATH","LC_ALL","LD_LIBRARY_PATH")}
    ok,out,reaped=Owned([str(control),"-D",str(root/"data")],penv).collect(3,8192)
    ids=re.findall(rb"^Database system identifier:[ \t]+([1-9][0-9]+)$",out,re.M)
    need(ok and reaped and len(ids)==1)
    sid=ids[0].decode()
    def owned():
        same_scope(cluster,root,sid,epoch,port);one_listener(cluster.pid,port)
        need(regular(control,1048576)==rec)
    owned()
    records={}
    folder=Path(folder)
    for n,h in ASSETS.items():
        rec2=regular(folder/n,262144)
        need(hashlib.sha256(rec2[1]).hexdigest()==h)
        records[n]=rec2
    deadline=time.monotonic()+80
    ch=PipeChild([php,"-c",ini,str(folder/"harness.php"),"central"],env,folder)
    witnesses={}
    try:
        ch.send(json.dumps({"cluster":sid,"pid":cluster.pid,"epoch":epoch,"data":str(root/"data"),"ini":ini}).encode()+b"\n",deadline)
        for _ in range(256):
            row=json_exact(ch.line(deadline,4096));owned()
            need(all(regular(folder/n,262144)==rec2 for n,rec2 in records.items()))
            if set(row)=={"refused"} and row["refused"] in PHASES:
                raise AtomicRefused(row["refused"])
            if row=={"done":["ACL","ROLLBACK","COMMIT_LOSS","DUPLICATE","CAPTURE_FIRST","REVOKE_FIRST","SCOPE"]}:
                ch.finish(deadline);return True
            if row!={"check":"owned"}:
                need(set(row)=={"check","backend"} and row["check"]=="backend" and type(row["backend"]) is int and row["backend"]>1)
                backend=row["backend"]
                stat=Path(f"/proc/{backend}/stat").read_text().rsplit(")",1)[1].split()
                need(int(stat[1])==cluster.pid and all(os.readlink(f"/proc/{backend}/ns/{ns}")==os.readlink(f"/proc/self/ns/{ns}") for ns in ("net","mnt","pid")))
                need(witnesses.setdefault(backend,stat[19])==stat[19])
            ch.send(b'{"permit":true}\n',deadline)
        raise ValueError("ATOMIC_PROTOCOL_REFUSED")
    finally:
        ch.clean()
