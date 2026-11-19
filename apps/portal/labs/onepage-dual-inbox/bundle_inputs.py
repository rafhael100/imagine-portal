"""Load exact approved source bytes; expected receipt hash is compiled into scope overlay."""
from pathlib import Path
from inbox_supervisor import regular, sha, need, json_exact, LIBS
from inventories import PINNED, HOOK_PINS

def read_inputs(dual,pg,php,ini,expected_receipt_sha):
    _,raw=regular(dual/"approved-ubuntu-receipt.json",262144)
    need(sha(raw)==expected_receipt_sha)
    receipt=json_exact(raw)
    need(set(receipt)=={"profile","assets","binaries","ini"})
    need(set(receipt["assets"])==LIBS|{"observer.php"})
    need(set(receipt["binaries"])=={"psql","pg_controldata","php"})
    # PG_PATH: <apt extract>/usr/lib/postgresql/18/bin.
    share=pg.parents[3]/"share/postgresql/18"
    root=dual.parents[1] # apps/portal
    reader=dual.parent/"onepage-d0-reader-pg"
    source={}
    for name in set(PINNED)|set(HOOK_PINS):
        if name in ("inbox.sql","lease.sql"): origin=dual/name
        elif name in HOOK_PINS: origin=reader/name
        elif name.endswith(".sql"): origin=root/"database/security"/name
        else: origin=root/"database/migrations"/name
        source[name]=regular(origin,1048576)[1]
    for filepath,n in ((pg/"psql","psql"),(pg/"pg_controldata","pg_controldata"),(php,"php")):
        need(sha(regular(filepath,33554432)[1])==receipt["binaries"][n])
    fsql=regular(dual.parent/"onepage-finance-integrated/finance.sql",1048576)[1]
    return {
      "source_bytes":source,"pg_share_bytes":{n:regular(share/n,33554432)[1] for n in ("postgres.bki","system_functions.sql")},
      "initdb":pg/"initdb","audit_source":dual,"audit_pins":receipt["assets"],
      "observer_ini":ini,"observer_ini_sha":receipt["ini"],
      "psql_sha":receipt["binaries"]["psql"],"pg_control_sha":receipt["binaries"]["pg_controldata"],
      "php_sha":receipt["binaries"]["php"],"ubuntu_profile":receipt["profile"]
    },fsql
