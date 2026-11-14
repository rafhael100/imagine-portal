#!/usr/bin/env python3
"""Read-only Portal staging preflight. Checks Docker Compose JSON and file stat
metadata only. Never accesses secrets, network, services, or customer records.
"""
import argparse
from hashlib import sha256
import json
from pathlib import Path
import stat
import sys

EXPECTED_ENV = {
    "APP_ENV": "staging",
    "APP_DEBUG": "false",
    "APP_URL": "https://portal-dev.imaginecomunica.com.br",
    "PORTAL_PURCHASE_PREVIEW_ENABLED": "1",
    "PORTAL_DEVELOPMENT_AUTH_ENABLED": "false",
}

def check_compose(doc):
    if not isinstance(doc, dict) or not isinstance(doc.get("services"), dict):
        raise ValueError("Expected structured Docker Compose JSON.")
    services=doc["services"]
    if set(services)!={"database","application","gateway"}:
        raise ValueError("Unexpected public or missing service.")
    networks=doc.get("networks")
    if not isinstance(networks,dict) or set(networks)!={"private","host_access"}:
        raise ValueError("Unexpected Docker network.")
    if networks["private"].get("internal") is not True:
        raise ValueError("Database/private application network not internal.")
    for name,svc in services.items():
        if svc.get("privileged",False) or svc.get("network_mode")=="host":
            raise ValueError("Host networking or privileges forbidden.")
        actual=svc.get("networks")
        expected={"private","host_access"} if name=="gateway" else {"private"}
        if not isinstance(actual,dict) or set(actual)!=expected:
            raise ValueError("Unexpected service network exposure.")
        if name!="gateway" and svc.get("ports",[]):
            raise ValueError("Database or Laravel host port exposed.")
    gateway=services["gateway"]
    ports=gateway.get("ports",[])
    if not isinstance(ports,list) or len(ports)!=1 or not isinstance(ports[0],dict):
        raise ValueError("One private gateway port required.")
    p=ports[0]
    if p.get("host_ip")!="127.0.0.1" or str(p.get("published"))!="18083" or str(p.get("target"))!="8080" or p.get("protocol","tcp")!="tcp":
        raise ValueError("Private preview MUST bind 127.0.0.1:18083.")
    for name in ("gateway","application"):
        svc=services[name]
        if svc.get("read_only") is not True or "ALL" not in svc.get("cap_drop",[]):
            raise ValueError("App and gateway must be read-only/cap-drop ALL.")
        if not any(str(v).startswith("no-new-privileges") for v in svc.get("security_opt",[])):
            raise ValueError("no-new-privileges not configured.")
    app=services["application"]
    if str(app.get("user"))!="82:82":
        raise ValueError("Application must run as unprivileged UID/GID 82.")
    env=app.get("environment")
    if not isinstance(env,dict):
        raise ValueError("Environment missing.")
    for key,val in EXPECTED_ENV.items():
        if str(env.get(key,"")).lower()!=val.lower():
            raise ValueError("Staging configuration invalid: "+key)
    if "GITHUB_ACTIONS" in env or "PORTAL_PREVIEW_LOOPBACK_TEST" in env:
        raise ValueError("Unsafe CI-only rehearsal enabled in VPS.")
    if services["database"].get("image")!="postgres:18-alpine" or gateway.get("image")!="nginxinc/nginx-unprivileged:stable-alpine":
        raise ValueError("Unexpected unreviewed image.")
    for name,target in (
        ("application","/run/secrets/preview-runtime.json"),
        ("database","/run/secrets/admin-password"),
        ("database","/docker-entrypoint-initdb.d/01-preview.sql")
    ):
        mounts=services[name].get("volumes",[])
        correct=[v for v in mounts if isinstance(v,dict) and v.get("type")=="bind"
                 and v.get("target")==target and v.get("read_only") is True]
        if len(correct)!=1:
            raise ValueError("Missing read-only private mount: "+target)

def check_files(private):
    for relative,mode,uid,directory in (
        (".",0o700,0,True),("runtime.json",0o400,82,False),
        ("admin-password",0o400,70,False),("init.sql",0o400,70,False),
        ("profiles",0o700,82,True),("profiles/users.json",0o400,82,False)
    ):
        path=private/relative
        if path.is_symlink():
            raise ValueError("Symbolic link in private secrets.")
        s=path.stat()
        if stat.S_IMODE(s.st_mode)!=mode or s.st_uid!=uid:
            raise ValueError("Incorrect private file ownership or mode.")
        if directory!=stat.S_ISDIR(s.st_mode):
            raise ValueError("Unexpected private file type.")

def report():
    claims={
        "schema":"imagine.portal.private-staging-preflight.v1",
        "database_and_application_internal_only":True,
        "gateway_loopback_port_only":True,
        "staging_route_gate_ci_disabled":True,
        "read_only_container_and_secret_metadata_verified":True,
        "real_iam_mfa_verified":False,
        "basic_auth_tls_outer_perimeter_verified":False,
        "external_revocation_witness_verified":False,
        "psp_payments_verified":False,
        "vps_deployed":False,
        "production_released":False,
        "release_gate":"NO_GO",
    }
    canonical=json.dumps(claims,ensure_ascii=False,separators=(",",":"))
    return {"claims":claims,"sha256_integrity_only":sha256(canonical.encode()).hexdigest()}

def self_test():
    valid={
      "networks":{"private":{"internal":True},"host_access":{}},
      "services":{
        "database":{"image":"postgres:18-alpine","networks":{"private":{}},"volumes":[
          {"type":"bind","target":"/run/secrets/admin-password","read_only":True},
          {"type":"bind","target":"/docker-entrypoint-initdb.d/01-preview.sql","read_only":True}]},
        "application":{"networks":{"private":{}},"read_only":True,"cap_drop":["ALL"],
          "security_opt":["no-new-privileges:true"],"user":"82:82",
          "environment":EXPECTED_ENV.copy(),
          "volumes":[{"type":"bind","target":"/run/secrets/preview-runtime.json","read_only":True}]},
        "gateway":{"image":"nginxinc/nginx-unprivileged:stable-alpine",
          "networks":{"private":{},"host_access":{}},"read_only":True,
          "cap_drop":["ALL"],"security_opt":["no-new-privileges:true"],
          "ports":[{"host_ip":"127.0.0.1","published":"18083","target":8080}]},
      }
    }
    check_compose(valid)
    for section,key,val in (
      ("port","host_ip","0.0.0.0"),("app","read_only",False),
      ("app","privileged",True),("env","APP_URL","https://example.invalid"),
      ("env","APP_ENV","production"),("network","internal",False)
    ):
        altered=json.loads(json.dumps(valid))
        if section=="port": altered["services"]["gateway"]["ports"][0][key]=val
        elif section=="app": altered["services"]["application"][key]=val
        elif section=="env": altered["services"]["application"]["environment"][key]=val
        else: altered["networks"]["private"][key]=val
        try: check_compose(altered)
        except ValueError: continue
        raise AssertionError("Unsafe preflight change was accepted.")
    assert report()["claims"]["release_gate"]=="NO_GO"
    print("PASS: private preflight rejects unsafe network, permissions, and environment; NO-GO.")

def main():
    p=argparse.ArgumentParser(description=__doc__)
    g=p.add_mutually_exclusive_group(required=True)
    g.add_argument("--self-test",action="store_true")
    g.add_argument("--inspect",action="store_true")
    p.add_argument("--compose-json",type=Path)
    p.add_argument("--private-dir",type=Path)
    p.add_argument("--output",type=Path)
    x=p.parse_args()
    if x.self_test:
        if x.compose_json or x.private_dir or x.output:
            p.error("Self-test has no input files.")
        self_test();return
    if not x.compose_json or not x.private_dir:
        p.error("Provide Compose JSON and private directory.")
    if (x.compose_json.is_symlink() or not x.compose_json.is_file()
        or x.compose_json.stat().st_size>150000):
        raise ValueError("Invalid Compose input.")
    check_compose(json.loads(x.compose_json.read_text()))
    check_files(x.private_dir)
    if x.output:
        if x.output.exists() or x.output.is_symlink():
            raise ValueError("Refusing evidence overwrite.")
        x.output.write_text(json.dumps(report(),indent=2,ensure_ascii=False)+"\n")
    print("PASS: private runtime metadata only; IAM/TLS/PSP remain NO-GO.")

if __name__=="__main__":
    try: main()
    except (OSError,ValueError,TypeError,AssertionError) as error:
        print("NO-GO private preview preflight: "+type(error).__name__,file=sys.stderr)
        raise SystemExit(2)
