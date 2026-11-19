"""Build trusted review inputs for the existing inbox transport. No sockets here."""
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import time
from inventories import compile_sources, plan, finance_manifest
from inbox_supervisor import LIBS, PipeChild, need, json_exact, one_listener, same_scope, regular, sha, store
from processes import Owned

def compose(args,env,pg,root,postmaster,approved,finance_sql):
    """
    approved is read from the independently reviewed immutable supervisor
    bundle, never from Laravel, a psql frame or staging. Does not invoke psql.
    Outer #962 scope owns root/postmaster/teardown.
    """
    need(set(approved)=={"source_bytes","pg_share_bytes","initdb","audit_source","audit_pins",
                         "observer_ini","observer_ini_sha","psql_sha","pg_control_sha","php_sha","ubuntu_profile"})
    root=Path(root);pg=Path(pg);php=Path(args[0]);ini=Path(approved["observer_ini"])
    audit=Path(approved["audit_source"])
    need(root.resolve()==root and ini.resolve()==ini and php.resolve()==php and audit.resolve()==audit)
    need(Path(approved["initdb"])==pg/"initdb")
    init=regular(pg/"initdb",33554432)
    expected=compile_sources(approved["source_bytes"],approved["pg_share_bytes"],init[1],approved["ubuntu_profile"])
    checked_binaries={}
    for name,file,limit in ("php",php,33554432),("psql",pg/"psql",33554432),("pg_control",pg/"pg_controldata",33554432),("observer_ini",ini,65536):
        need(file.resolve()==file)
        rec=regular(file,limit);need(sha(rec[1])==approved[name+"_sha"])
        checked_binaries[file]=rec
    penv={"PATH":"/usr/bin:/bin","LC_ALL":"C","PHP_INI_SCAN_DIR":"","LD_LIBRARY_PATH":env["LD_LIBRARY_PATH"]}
    need(env.get("PORTAL_NATIVE_ORDER_TEST")=="DISPOSABLE_PG18" and env.get("PORTAL_NATIVE_ORDER_TEST_PORT")=="55491"
         and env.get("GITHUB_ACTIONS")=="true" and Path(env["PORTAL_NATIVE_ORDER_TEST_DATA"])==root/"data")
    _,pidfile=regular(root/"data"/"postmaster.pid",4096,os.getuid())
    parts=pidfile.decode("ascii").splitlines();epoch=int(parts[2]);port=55491
    control=pg/"pg_controldata"
    ok,out,reaped=Owned([str(control),"-D",str(root/"data")],penv).collect(3,8192)
    match=re.findall(rb"^Database system identifier:[ \t]+([1-9][0-9]*)$",out,re.M)
    need(ok and reaped and len(match)==1 and regular(control,33554432)==checked_binaries[control])
    sid=match[0].decode()
    same_scope(postmaster,root,sid,epoch,port);one_listener(postmaster.pid,port)
    frozen=root/"observer-frozen";frozen.mkdir(mode=0o700)
    pins=approved["audit_pins"];need(set(pins)==LIBS|{"observer.php"})
    witnesses={}
    for name,pin in pins.items():
        _,b=regular(audit/name,262144);need(sha(b)==pin)
        witnesses[name]=store(frozen/name,b)
    # Freeze the exact reviewed local ini; no libpq env options.
    witnesses["observer.ini"]=store(frozen/"observer.ini",checked_binaries[ini][1])
    def files():
        for n,mark in witnesses.items():need(regular(frozen/n,262144,os.getuid())==mark)
        for file,mark in checked_binaries.items():need(regular(file,33554432)==mark)

    def attest_backend(pid):
        same_scope(postmaster,root,sid,epoch,port);one_listener(postmaster.pid,port)
        status=Path(f"/proc/{pid}/status").read_text()
        need(re.search(r"^PPid:\s+"+str(postmaster.pid)+r"$",status,re.M))
        for n in ("net","mnt","pid"):
            need(os.readlink(f"/proc/{pid}/ns/{n}")==os.readlink(f"/proc/self/ns/{n}"))
        return Path(f"/proc/{pid}/stat").read_text().rsplit(")",1)[1].split()[19]

    def observe(mode, model=None):
        files()
        same_scope(postmaster,root,sid,epoch,port);one_listener(postmaster.pid,port)
        salt=secrets.token_hex(32);deadline=time.monotonic()+6
        c={"mode":mode,"nonce":salt,"postmaster":postmaster.pid,"data":str(root/"data"),"epoch":epoch,
           "cluster":sid,**{n:os.readlink(f"/proc/self/ns/{n}") for n in ("net","mnt","pid")},"port":port}
        child=PipeChild([str(php),"-c",str(frozen/"observer.ini"),str(frozen/"observer.php")],penv,frozen)
        try:
            child.send(json.dumps(c).encode()+b"\n",deadline)
            proof=json_exact(child.line(deadline,4096));need(set(proof)=={"phase","nonce","identity"}
                and proof["phase"]=="identity" and proof["nonce"]==salt)
            r=proof["identity"];need(set(r)=={"backend","cluster","started","data","port","server","client","db",
                                             "login","actor","major","listen","sockets"})
            expect={"cluster":sid,"data":str(root/"data"),"port":port,"server":"127.0.0.1","client":"127.0.0.1",
                    "db":"postgres" if mode=="START" else "portal_asaas_ci","login":"portal_native_test_bootstrap",
                    "actor":"portal_native_test_bootstrap","major":18,"listen":"127.0.0.1","sockets":""}
            need(all(type(r[k]) is type(v) and r[k]==v for k,v in expect.items()))
            need(type(r["backend"]) is int and r["backend"]>1 and re.fullmatch(str(epoch)+r"\.[0-9]{6}",r["started"]))
            if model is not None:need(r["started"]==model["base"]["anchor"]["expected"]["started"])
            witness=attest_backend(r["backend"]);files()
            child.send(json.dumps({"permit":True,"nonce":salt,"backend":r["backend"],"model":model},
                                  separators=(",",":")).encode()+b"\n",deadline)
            ack=json_exact(child.line(deadline,4096))
            need(ack=={"phase":"accepted","nonce":salt,"backend":r["backend"]})
            need(attest_backend(r["backend"])==witness);files()
            child.finish(deadline)
            return r["started"]
        finally:
            child.clean()

    started=observe("START")
    model,bridge=plan(expected,dict(cluster=sid,started=started,pid=str(postmaster.pid),
                                    data=str(root/"data"),port=str(port)),secrets.token_hex(32))
    used=False
    def post_audit():
        nonlocal used
        need(not used);used=True
        return observe("POST",{"base":model,"finance":finance_manifest(model,finance_sql)})==started
    return dict(model=model,source=frozen,pins={n:pins[n] for n in LIBS},psql_sha=approved["psql_sha"],
                cluster_identity=sid,start_epoch=epoch,post_audit=post_audit,
                pg_control_sha=approved["pg_control_sha"],php_sha=approved["php_sha"]),bridge,finance_manifest(model,finance_sql)

