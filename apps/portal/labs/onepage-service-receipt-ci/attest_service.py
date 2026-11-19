"""Collect bounded facts for the single disposable GitHub-owned service only."""
import datetime as dt
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
import sys

DB = "portal_native_orders_ci"
BOOT = "portal_native_test_bootstrap"
DATA = "/var/lib/postgresql/18/docker"
PORT = 5432
RANGES = tuple(map(ipaddress.ip_network, ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")))
FACTS = """SELECT json_build_object('cluster',system_identifier::text,
'started',extract(epoch FROM pg_postmaster_start_time())::text,
'data',current_setting('data_directory'),'version',current_setting('server_version_num'),
'db',current_database(),'login',session_user,'actor',current_user,
'port',current_setting('port'))::text FROM pg_control_system()"""
FIELDS = '{"id":{{json .Id}},"image":{{json .Image}},"image_ref":{{json .Config.Image}},"running":{{json .State.Running}},"started":{{json .State.StartedAt}},"ports":{{json .NetworkSettings.Ports}},"networks":{{json .NetworkSettings.Networks}}}'
FIXED_ENV = {"PATH": "/usr/bin:/bin", "LC_ALL": "C"}

def need(ok):
    if not ok:
        raise ValueError("SERVICE_ATTESTATION_REFUSED")

def call(args, env=FIXED_ENV):
    p = subprocess.run(args, env=env, stdin=subprocess.DEVNULL, capture_output=True, timeout=12)
    need(p.returncode == 0 and p.stderr == b"" and 0 < len(p.stdout) <= 32768)
    return p.stdout

def inspect(obj, cid):
    need(set(obj) == {"id","image","image_ref","running","started","ports","networks"})
    need(re.fullmatch(r"[0-9a-f]{64}",cid) is not None and obj["id"] == cid)
    need(re.fullmatch(r"sha256:[0-9a-f]{64}", obj["image"]) is not None and obj["image_ref"] == "postgres:18-alpine" and obj["running"] is True)
    need(obj["ports"] == {"5432/tcp":[{"HostIp":"127.0.0.1","HostPort":"5432"}]})
    need(isinstance(obj["networks"],dict) and len(obj["networks"]) == 1)
    net = next(iter(obj["networks"].values()))
    addr = ipaddress.ip_address(net["IPAddress"])
    need(addr.version == 4 and any(addr in r for r in RANGES) and not net.get("GlobalIPv6Address"))
    started = dt.datetime.fromisoformat(obj["started"].replace("Z","+00:00"))
    need(started.tzinfo is not None)
    return str(addr), started.timestamp()

def validate_sql(f, docker_start, now):
    need(set(f)=={"cluster","started","data","version","db","login","actor","port"})
    for k,v in {"data":DATA,"db":DB,"login":BOOT,"actor":BOOT,"port":str(PORT)}.items():
        need(f[k] == v)
    need(re.fullmatch(r"[1-9][0-9]{15,19}",f["cluster"]) is not None)
    need(re.fullmatch(r"[1-9][0-9]*\.[0-9]{6}",f["started"]) is not None)
    need(re.fullmatch(r"18[0-9]{4}",f["version"]) is not None)
    need(docker_start - 1 <= float(f["started"]) <= now + 1 and 0 <= now - docker_start <= 900)
    need(docker_start - 2 <= int(f["cluster"]) >> 32 <= now + 1)

def main():
    need(len(sys.argv)==2 and os.environ.get("GITHUB_ACTIONS") == "true")
    cid=os.environ.get("PORTAL_TEST_PG_SERVICE_ID","")
    need(re.fullmatch(r"[0-9a-f]{64}",cid) is not None)
    root=Path(os.environ["RUNNER_TEMP"])
    out=Path(sys.argv[1]);need(root.is_absolute() and root.resolve()==root and out.is_absolute() and out.parent==root and re.fullmatch(r"onepage-service-[a-zA-Z0-9_-]+\.json",out.name) is not None)
    args=["/usr/bin/docker","inspect","--type","container","--format",FIELDS,cid]
    raw=call(args); obj=json.loads(raw);addr,started=inspect(obj,cid)
    # TCP to the single inspected private container address (not a Unix socket;
    # Docker's health check can succeed during an init-only loopback-less server).
    f=json.loads(call(["/usr/bin/docker","exec",cid,"psql","-X","-w","-q","-A","-t","-h",addr,"-p",str(PORT),"-U",BOOT,"-d",DB,"-v","ON_ERROR_STOP=1","-c","BEGIN READ ONLY; "+FACTS+"; COMMIT;"]))
    now=dt.datetime.now(dt.timezone.utc).timestamp();validate_sql(f,started,now)
    need(json.loads(call(args))==obj) # same process/image/address before and after SQL
    php=Path(os.environ["PORTAL_TEST_PHP_BIN"])
    need(php.is_absolute() and php.is_file() and os.access(php,os.X_OK))
    version=call([str(php),"-n","-r","echo PHP_VERSION;"]).decode("ascii")
    need(re.fullmatch(r"8\.4\.[0-9]+",version) is not None)
    data=json.dumps({"service":cid,"image":obj["image"],"container_started":obj["started"],
      "address":addr,"sql":f,"observed":int(now),"php":version},separators=(",",":")).encode()+b"\n"
    fd=os.open(out,os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_WRONLY|os.O_CLOEXEC,0o600)
    with os.fdopen(fd,"wb") as w:need(w.write(data)==len(data))
    print(hashlib.sha256(data).hexdigest())

if __name__=="__main__":
    try:main()
    except Exception:
        print("SERVICE_ATTESTATION_REFUSED",file=sys.stderr);raise SystemExit(1)
