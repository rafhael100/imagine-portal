#!/usr/bin/env python3
"""Adversarial Z1 container. Only synthetic actor-A handle; NO database access."""
import json
import os
import socket
import urllib.error
import urllib.parse
import urllib.request

API = "http://authority:8080"
HANDLE = os.environ.get("R07_ACTOR_A_HANDLE", "")
PHASE = os.environ.get("R07_PHASE", "before")
passed = 0


def check(name, condition):
    global passed
    if not condition:
        raise AssertionError("[FAIL] " + name)
    passed += 1
    print("[PASS] " + name, flush=True)


def call(method, route, token=None, payload=None, extra_headers=None):
    headers = {"Accept": "application/json"}
    if token is not None:
        headers["Authorization"] = "Bearer " + token
    if extra_headers:
        headers.update(extra_headers)
    body = None
    if payload is not None:
        body = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(API + route, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=8) as response:
            data = response.read(4096).decode("utf-8")
            return response.status, json.loads(data)
    except urllib.error.HTTPError as error:
        data = error.read(4096).decode("utf-8")
        return error.code, json.loads(data)


def no_tenant_b(data):
    return "SYNTHETIC_B_ONLY" not in json.dumps(data)


check("R07-T00 valid disposable actor-A handle", len(HANDLE) == 64)

if PHASE == "before":
    check("R07-T01 edge has no database or admin credentials",
          all(k not in os.environ for k in (
              "R07_OWNER_PASSWORD", "R07_AUTH_PASSWORD", "R07_DB_PASSWORD", "R07_DB_DSN",
              "R07_ACTOR_B_DIGEST", "R07_ACTOR_B_HANDLE"))
          and not os.path.exists("/var/run/docker.sock"))

    try:
        socket.getaddrinfo("pg", 5432)
        database_resolvable = True
    except socket.gaierror:
        database_resolvable = False
    check("R07-T02 database service absent from hostile edge network", not database_resolvable)

    status, data = call("GET", "/health")
    check("R07-T03 isolated authority is reachable", status == 200 and data.get("synthetic") is True)

    status, data = call("GET", "/v1/resource?id=resource-a", HANDLE)
    check("R07-T04 valid A can read own synthetic resource",
          status == 200 and data.get("payload") == "SYNTHETIC_A_ONLY")

    forged = {
        "X-Actor-Id": "actor-b", "X-Organization-Id": "org-b",
        "X-Role": "admin", "X-MFA-Verified": "true",
    }
    status, data = call("GET", "/v1/resource?id=resource-b&actor=actor-b&organization=org-b",
                        HANDLE, extra_headers=forged)
    check("R07-T05 forged actor/tenant/grant cannot read B",
          status == 403 and no_tenant_b(data))

    injected = urllib.parse.quote("resource-a' OR '1'='1'--", safe="")
    status, data = call("GET", "/v1/resource?id=" + injected, HANDLE)
    check("R07-T06 SQL injection string cannot retrieve another tenant",
          status in (400, 403) and no_tenant_b(data))

    status, data = call("GET", "/v1/resource?id=resource-b", "fake-crm-cookie")
    check("R07-T07 CRM token does not authenticate Portal authority",
          status == 401 and no_tenant_b(data))

    status, data = call("GET", "/v1/resource?id=resource-b")
    check("R07-T08 missing session denied", status == 401 and no_tenant_b(data))

    status, data = call("POST", "/v1/sql", HANDLE, payload={"sql": "SET LOCAL r07.tenant='org-b'"})
    check("R07-T09 no generic SQL API exposed", status == 404 and no_tenant_b(data))

    attempt = {
        "resource_id": "resource-b", "effect_id": "malicious-b",
        "actor_id": "actor-b", "organization_id": "org-b",
        "role": "owner", "mfa_verified": True, "sql": "set_config('r07.tenant','org-b',true)"
    }
    status, data = call("POST", "/v1/effect", HANDLE, payload=attempt, extra_headers=forged)
    check("R07-T10 forged B effect denied", status == 403 and no_tenant_b(data))

    valid = {"resource_id": "resource-a", "effect_id": "idempotent-a"}
    status, data = call("POST", "/v1/effect", HANDLE, payload=valid)
    check("R07-T11 authorized A effect committed", status == 201 and data.get("recorded") is True)

    status, data = call("POST", "/v1/effect", HANDLE, payload=valid)
    check("R07-T12 idempotent replay does not insert a second effect",
          status == 200 and data.get("idempotent_replay") is True)

    status, data = call("GET", "/v1/resource?id=resource-a", HANDLE)
    check("R07-T13 stolen valid A handle still allows A operations (T3 residual)",
          status == 200 and data.get("payload") == "SYNTHETIC_A_ONLY")
    print("[KNOWN RESIDUAL T3] Compromised edge holding valid A session can request legitimate A actions.", flush=True)

elif PHASE == "after":
    status, data = call("GET", "/v1/resource?id=resource-a", HANDLE)
    check("R07-T14 revoked epoch rejects previous A session", status == 401 and no_tenant_b(data))

    status, data = call(
        "POST", "/v1/effect", HANDLE,
        payload={"resource_id": "resource-a", "effect_id": "after-revocation"})
    check("R07-T15 revoked epoch denies new synthetic effect", status == 401 and no_tenant_b(data))
else:
    raise AssertionError("Unknown adversarial test phase")

print("[R07-F1] " + str(passed) + " adversarial checks passed in phase " + PHASE, flush=True)
