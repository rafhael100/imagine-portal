#!/usr/bin/env python3
"""Operator-only private staging checks; never writes payments or deploys apps.

Examples:
  python3 check_staging_runtime_readonly.py --self-test
  sudo APP_ENV=staging PORTAL_STAGING_READONLY_CHECK=1 \
    python3 check_staging_runtime_readonly.py --check

This check is NOT an approval to expose the service or activate billing.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.request

from preflight_private_runtime import check_compose, check_files

ROOT=Path(__file__).resolve().parent
ALLOWED_HTTP={200,301,302,303,307,308,401,403}
IDENTITY='purchase_rehearsal_psp|f|f'
URL='http://127.0.0.1:18083/_preview/portal'

class NoGo(Exception):
    pass

def run(argv, *, timeout=25):
    try:
        result=subprocess.run(argv,cwd=ROOT,text=True,capture_output=True,
                              timeout=timeout,check=False)
    except (OSError,subprocess.TimeoutExpired) as error:
        raise NoGo("Command unavailable.") from error
    if result.returncode:
        # Never echo command stderr: Docker/psql can expose private metadata.
        raise NoGo("Read-only check unavailable.")
    return result.stdout.strip()

def parse_services(output):
    values=output.splitlines()
    if len(values)!=len(set(values)):
        raise NoGo("Duplicate Docker service.")
    needed={'database','application','gateway'}
    if not needed.issubset(values) or 'broker' in values:
        raise NoGo("Missing private service or persistent broker.")
    if any(v not in needed for v in values):
        raise NoGo("Unexpected running service.")

def check_broker_identity(value):
    if value!=IDENTITY:
        raise NoGo("PSP SQL identity is privileged or unexpected.")

def check_http_status(value):
    if value not in ALLOWED_HTTP:
        raise NoGo("Private gateway not reachable.")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):
        return None

def check_local_gateway():
    # Unauthenticated GET to exact localhost endpoint, no proxy, no redirects.
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),
                                       NoRedirect)
    request=urllib.request.Request(URL,headers={'Accept':'text/html'},
                                   method='GET')
    try:
        with opener.open(request,timeout=7) as response:
            status=response.status
    except urllib.error.HTTPError as error:
        status=error.code
    except (OSError,ValueError) as error:
        raise NoGo("Gateway not responding.") from error
    check_http_status(status)

def self_test():
    parse_services("database\napplication\ngateway")
    for bad in ("database\napplication\n", "database\napplication\ngateway\nbroker",
                "database\ndatabase\napplication\ngateway",
                "database\napplication\ngateway\nunreviewed"):
        try:
            parse_services(bad)
        except NoGo:
            continue
        raise AssertionError("Unsafe Docker topology accepted.")
    check_broker_identity(IDENTITY)
    for bad in ('postgres|t|t','purchase_rehearsal_psp|t|f',
                'purchase_rehearsal_psp|f|t',''):
        try:
            check_broker_identity(bad)
        except NoGo:
            continue
        raise AssertionError("Unsafe SQL identity accepted.")
    for good in ALLOWED_HTTP:
        check_http_status(good)
    for bad in (0,404,500,502,503):
        try:
            check_http_status(bad)
        except NoGo:
            continue
        raise AssertionError("Unsafe gateway status accepted.")
    print("PASS offline readiness policy self-test; production gate NO-GO.")

def check():
    if not (os.geteuid()==0 and os.environ.get('APP_ENV')=='staging'
            and os.environ.get('PORTAL_STAGING_READONLY_CHECK')=='1'
            and os.environ.get('GITHUB_ACTIONS')!='true'
            and os.environ.get('PORTAL_SANDBOX_BROKER_ONCE')!='1'):
        raise NoGo("Explicit root-owned staging operator invocation required.")
    if not (ROOT/'compose.yaml').is_file():
        raise NoGo("Missing private Compose specification.")
    document=json.loads(run(['docker','compose','-f','compose.yaml',
                             'config','--format','json'],timeout=15))
    check_compose(document)
    # Metadata only: no secret contents are opened or logged.
    check_files(ROOT/'.private')
    running=run(['docker','compose','-f','compose.yaml','ps',
                 '--services','--status','running'],timeout=15)
    parse_services(running)
    network=run(['docker','network','inspect','imagine-purchase-preview_private',
                 '--format','{{.Internal}}'],timeout=12)
    if network!='true':
        raise NoGo("Private Docker network is not internal.")
    base=['docker','compose','-f','compose.yaml','run','--rm','-T',
          '--no-deps','broker','-X','-q','-tA','-v','ON_ERROR_STOP=1',
          '-h','database','-U','purchase_rehearsal_psp',
          '-d','portal_purchase_rehearsal','-c']
    identity=run(base+["SELECT current_user,rolsuper,rolbypassrls "
                       "FROM pg_roles WHERE rolname=current_user"],
                 timeout=30)
    check_broker_identity(identity)
    # No row values are read, only a constant zero on this query.
    if run(base+["SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents "
                 "WHERE false"],timeout=30)!='0':
        raise NoGo("Restricted SQL count failed.")
    for forbidden in (
        "SELECT customer_id FROM purchase_rehearsal.customer_orders LIMIT 1",
        "SELECT key FROM purchase_rehearsal.preview_scope_secret",
    ):
        try:
            run(base+[forbidden],timeout=30)
        except NoGo:
            pass
        else:
            raise NoGo("Finance broker accessed a forbidden customer field.")
    check_local_gateway()
    print("PASS private gateway, Docker metadata and independent SQL broker authentication.")
    print("NO-GO: external TLS, IAM/MFA, contracts, PSP API, webhooks, Sites and production remain UNVERIFIED.")

def main():
    p=argparse.ArgumentParser(description=__doc__)
    group=p.add_mutually_exclusive_group(required=True)
    group.add_argument('--self-test',action='store_true')
    group.add_argument('--check',action='store_true')
    args=p.parse_args()
    if args.self_test:
        self_test()
    else:
        check()

if __name__=='__main__':
    try:
        main()
    except (NoGo,ValueError,TypeError,KeyError,OSError) as error:
        print("NO-GO: readonly staging preflight rejected; no deployment or financial action.",file=sys.stderr)
        raise SystemExit(2)
