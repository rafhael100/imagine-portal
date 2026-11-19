"""Pinned source compiler. No DB observations enter expected role/ACL inventories."""
import hashlib
import re

BOOT = "portal_native_test_bootstrap"
STOCK = ["postgres","template0","template1"]
NATIVE = "portal_native_orders_ci"
INBOX = "portal_asaas_ci"
INGRESS = "asaas_webhook_runtime"
WORKER = "asaas_reconcile_worker"
PINNED = {
 "portal_browser_auth.sql":"42bb622b01e1b89f7ec99214ac104da2262c3774",
 "portal_browser_principal_candidate.sql":"2ea9540009989b17656e18b588f0e7a60ed3762a",
 "portal_onepage_d0_account_epoch.sql":"a917cd4c7a486fb703b3a37bfe7a9ee68733804f",
 "portal_onepage_d0_candidate.sql":"85f7a12dcf90d9319d1ced9300bda659cfec3c15",
 "portal_onepage_entry_payments.sql":"d2ed493ac1227f033092b2db72cf5bb1e324d837",
 "portal_onepage_orders.sql":"93fc7987ed7b1902817af95092fe195c7cfc6722",
 "2026_11_09_000002_create_portal_identity_foundation.php":"f952bd64cb51be2ea18122ec232f6862bc1d522a",
 "2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php":"3a5c99b8a50cf8b9473f912db6477309d7ea5fcb",
 "2026_11_10_000004_add_org_revision_to_portal_sessions.php":"b8dee597906106dea33fabc79ce24bcf561d5e9a",
 "2026_11_10_000005_create_portal_auth_throttle_buckets.php":"7cb48e75b4de17fd7d9091f5dad81a8eed7b2b4d",
 "2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php":"737d43aa4271229e9c34bc2fa4759014ea11b21d",
 "2026_11_11_000012_fence_portal_membership_revision.php":"86e75433868b1f6ecc2fd74344b70c8e256e1ec5",
 "2026_11_11_000013_fence_portal_organization_revision.php":"7f6eb9526ed56f4306fbe0e6eda961b14142e63f",
 "2026_11_11_000014_guard_portal_session_issuance.php":"d00b2d0db13f788ff40b3964cb2b5b20eaf1f699",
 "2026_11_12_000016_fence_portal_identity_revision.php":"a7528d5d461f1653efb13ecc3f4fb86ed66dbec8",
 "inbox.sql":"292b23f7416e34e9b6f6b8ebb9dff6d056426f4b",
 "lease.sql":"03da8af90eca8733fb1842498fd4ac6b50fe8d7d",
}
PG_SHARE = {
 "postgres.bki":"360718603dc060a1bfefe67c4e898d16eff18580a48b8fa3b6680e8b21de2e05",
 "system_functions.sql":"e5b61e70663b9dd55e2e6397ecb1ff3084f4f9afbf12907529b6e565a75f254f",
}
HOOK_PINS = {
 "ConfiguredReaderPgTest.php":"4f02d535424d178df22001d5584aa5313e476a626b8b9bf2f3b627043901573d",
 "BindingFixture.php":"ee50ac02662f63cffa925b8ccd3aa717639eca6eadde325c01a57325f92bc3c9",
}
# initdb binary is source evidence for the template ACL exception. A different
# approved vendor build must have its initdb behavior independently reviewed.
INITDB_SHA = "7b9b40a57cae1ed9d5ee8212c728ea3a7e77cb13395865928fd80d4a1c13c962"

def need(ok, field):
    if not ok:
        raise RuntimeError("REVIEW_REFUSED:"+field)

def pin(source):
    return hashlib.sha1(f"blob {len(source)}\0".encode()+source).hexdigest()

def role(name, login=False):
    return [name,login,False,False,False,False,False,False]

def baseline_roles(bki):
    group=bki.decode().split("open pg_authid\n",1)[1].split("close pg_authid",1)[0]
    out=[]
    for l in group.splitlines():
        x=re.fullmatch(r"insert \( [0-9]+ ([A-Za-z_]+) ([tf]) ([tf]) ([tf]) ([tf]) ([tf]) ([tf]) ([tf]) -1 _null_ _null_ \)",l)
        need(x is not None,"bki_role")
        n,*bits=x.groups()
        sup,inh,createrole,createdb,login,repl,bypass=map(lambda v:v=="t",bits)
        out.append([BOOT if n=="POSTGRES" else n,login,sup,inh,createdb,createrole,repl,bypass])
    need(len(out)==17 and len({r[0] for r in out})==17,"bki_role_count")
    return sorted(out)

def baseline_memberships(sql):
    rows=[]
    for l in sql.decode().splitlines():
        x=re.fullmatch(r"GRANT (pg_[a-z_]+) TO (pg_[a-z_]+);",l)
        if x:rows.append([*x.groups(),BOOT,False,True,True])
    need([r[0] for r in rows]==["pg_read_all_settings","pg_read_all_stats","pg_stat_scan_tables"],
        "init_memberships")
    return sorted(rows)

def native_roles(sqls, expected, logins):
    names=[]
    for f in sqls:
        for match in re.finditer(rb"(?im)^CREATE ROLE ([a-z_][a-z_0-9]*) ([ A-Z]+);",f):
            raw,flags=match.groups()
            need(set(flags.decode().split()) in (
                {"NOLOGIN","NOSUPERUSER","NOINHERIT","NOCREATEDB","NOCREATEROLE","NOBYPASSRLS"},
                {"NOLOGIN","NOSUPERUSER","NOINHERIT","NOCREATEDB","NOCREATEROLE","NOREPLICATION","NOBYPASSRLS"}),
                "native_role_flags")
            names.append(raw.decode())
    need(sorted(names)==sorted(expected),"native_role_set")
    return [role(n,n in logins) for n in sorted(names)]

def compile_sources(source, pg_share, initdb, profile=None):
    # Offline default is the already reviewed local reference ONLY.
    if profile is None:
        share_pins,init_pin=PG_SHARE,INITDB_SHA
    else:
        need(set(profile)=={"origin","arch","package","version","initdb","share","verified_deb_sha256"},"ubuntu_profile")
        need((profile["origin"],profile["arch"],profile["package"],profile["version"])==
             ("noble-pgdg","amd64","postgresql-18","18.7-1.pgdg24.04+2"),"ubuntu_package_version")
        need(re.fullmatch(r"[0-9a-f]{64}",profile["verified_deb_sha256"]) and profile["initdb"]!=INITDB_SHA,
             "ubuntu_package_signature_receipt")
        need(set(profile["share"])==set(PG_SHARE),"ubuntu_share_fields")
        share_pins,init_pin=profile["share"],profile["initdb"]
    need(set(source)==set(PINNED)|set(HOOK_PINS),"native_source_set")
    for n,h in HOOK_PINS.items():need(hashlib.sha256(source[n]).hexdigest()==h,n)
    for n,h in PINNED.items():need(pin(source[n])==h,n)
    need(set(pg_share)==set(PG_SHARE),"pg_share_set")
    for n,h in share_pins.items():need(hashlib.sha256(pg_share[n]).hexdigest()==h,n)
    need(hashlib.sha256(initdb).hexdigest()==init_pin,"initdb_target_review")
    for m,b in source.items():
        if m.endswith(".php") and m not in HOOK_PINS:
            # These exact PHP migrations are reviewed as DB-local DDL only.
            need(re.search(rb"\b(?:CREATE|ALTER|DROP)\s+ROLE\b|\b(?:GRANT|REVOKE)\b[^;]*\bON DATABASE\b",b,re.I) is None,
                 "native_migration_"+m)
    n1=native_roles([source[n] for n in ("portal_browser_auth.sql","portal_browser_principal_candidate.sql","portal_onepage_orders.sql")],
        ["portal_browser_owner","portal_browser_runtime","portal_onepage_owner","portal_onepage_runtime","portal_onepage_publisher"],
        ["portal_browser_runtime","portal_onepage_runtime","portal_onepage_publisher"])
    n2=native_roles([source[n] for n in ("portal_onepage_entry_payments.sql","portal_onepage_d0_candidate.sql","portal_onepage_d0_account_epoch.sql")],
        ["portal_onepage_payment_owner","portal_onepage_psp_registrar","portal_onepage_d0_verifier"],
        ["portal_onepage_psp_registrar","portal_onepage_d0_verifier"])
    roles=baseline_roles(pg_share["postgres.bki"])
    memberships=baseline_memberships(pg_share["system_functions.sql"])
    owner=[role("postgres")]
    dbs=sorted(STOCK+[NATIVE,INBOX])
    database_rows=[[d,BOOT,d!="template0",d.startswith("template"),-1,not d.startswith("template")] for d in dbs]
    rows=[["schema","public",None,"PUBLIC","pg_database_owner","USAGE",False]]
    rows += [["schema","public",None,"pg_database_owner","pg_database_owner",priv,False] for priv in ("CREATE","USAGE")]
    for d in dbs:
        rows.extend([["database",d,None,BOOT,BOOT,p,False] for p in ("CREATE","CONNECT","TEMPORARY")])
        rows.append(["database",d,None,"PUBLIC",BOOT,"CONNECT",False])
        if not d.startswith("template"):rows.append(["database",d,None,"PUBLIC",BOOT,"TEMPORARY",False])
    return dict(roles=roles,memberships=memberships,
                baseline={"acl":rows,"databases":database_rows,"extensions":[["plpgsql","1.0",BOOT,"pg_catalog",False]],
                          "memberships":memberships,"roles":sorted(roles+owner)},
                native_roles=n1,binding_roles=n2)

def plan(compiled, volatile, nonce):
    need(set(volatile)=={"cluster","started","pid","data","port"} and
         re.fullmatch(r"[a-f0-9]{64}",nonce),"volatile_shape")
    for key in ("cluster","pid","port"):need(re.fullmatch(r"[1-9][0-9]*",volatile[key]),key)
    need(re.fullmatch(r"[1-9][0-9]*\.[0-9]{6}",volatile["started"]),"startup_exact")
    need(re.fullmatch(r"/tmp/native-briefing-[a-zA-Z0-9_]+/data",volatile["data"]),"PGDATA")
    ready=compiled["baseline"]["roles"]
    memberships=compiled["memberships"]
    def facts(db,names,rs,empty,safe):
        return volatile|dict(database=db,login=BOOT,actor=BOOT,major="18",address="127.0.0.1",
            listen="127.0.0.1",sockets="",empty=empty,postgres_safe=safe,roles=sorted(r for r in rs if not r[0].startswith("pg_")),
            databases=sorted(names),memberships=sorted([r[:2]+r[3:] for r in memberships]))
    dbs=STOCK+[NATIVE,INBOX]
    global_expected={
        "F391":facts(INBOX,dbs,ready+[role(INGRESS,True)],False,True),
        "F392":facts(INBOX,dbs,ready+[role(INGRESS,True),role(WORKER,True)],False,True),
        "POST_NATIVE":facts(INBOX,dbs,ready+[role(INGRESS,True),role(WORKER,True)]+compiled["native_roles"],False,True),
        "POST_BINDING":facts(INBOX,dbs,ready+[role(INGRESS,True),role(WORKER,True)]+compiled["native_roles"]+compiled["binding_roles"],False,True),
    }
    anchor=dict(phase="asaas_empty_owner_ready",nonce=nonce,expected=facts(INBOX,dbs,ready,True,True))
    phase_anchors={
       "POST_NATIVE":{"roles":sorted(ready+compiled["native_roles"]),"memberships":memberships},
       "POST_BINDING":{"roles":sorted(ready+compiled["native_roles"]+compiled["binding_roles"]),"memberships":memberships},
    }
    keys=["database","databases","roles","memberships","empty","postgres_safe"]
    bridge={
      "initial":facts(NATIVE,STOCK+[NATIVE],compiled["roles"],True,None),
      "database_created":facts(NATIVE,dbs,compiled["roles"],True,None),
      "owner_created":facts(NATIVE,dbs,ready,True,True),
      "empty":anchor["expected"],"inbox":global_expected["F391"],"leased":global_expected["F392"],
      "post_native":global_expected["POST_NATIVE"],"post_binding":global_expected["POST_BINDING"],
    }
    return dict(anchor=anchor,baseline=compiled["baseline"],phaseAnchors=phase_anchors,
                globalExpected=global_expected),{stage:{k:v[k] for k in keys} for stage,v in bridge.items()}

FINANCE_SHA="da176ef9c16e5d32059456bccedbf344cfdc7dc5a6c6aad60c597feaab6e4c91"
def finance_manifest(model, finance_sql):
    need(hashlib.sha256(finance_sql).hexdigest()==FINANCE_SHA,"finance_962_blob")
    created=native_roles([finance_sql],["onepage_finance_lab_owner","onepage_finance_lab_verifier"],[])
    base=model["globalExpected"]["POST_BINDING"]
    out={}
    for phase in ("BEFORE_FINANCE","FINANCE_INSTALLED","AFTER_FINANCE"):
        extra=[r.copy() for r in created] if phase!="BEFORE_FINANCE" else []
        for r in extra:
            if r[0]=="onepage_finance_lab_verifier" and phase=="AFTER_FINANCE":r[1]=True
        inbox=base|{"roles":sorted(base["roles"]+extra)}
        out[phase]={"native":inbox|{"database":NATIVE},"inbox":inbox}
    return out
