#!/usr/bin/env python3
"""R03 HTTP requests from a disposable Z1-only client, no SQL credentials."""
import json
import os
import time
import urllib.error
import urllib.request

token = os.environ["R07_ACTOR_A_HANDLE"]
expected = int(os.environ["R03_EXPECT"])
if len(token) != 64 or expected not in (401, 503):
    raise SystemExit("Invalid lab expectation or bearer")
api = "http://restored:8080"
for method, path, label in (
    ("GET", "/v1/resource?id=resource-a", "READ"),
    ("POST", "/v1/effect", "WRITE"),
):
    body = None
    if method == "POST":
        body = json.dumps({"resource_id": "resource-a", "effect_id": "r03-after"}).encode()
    headers = {"Authorization": "Bearer " + token}
    if body is not None:
        headers["Content-Type"] = "application/json"
    result = None
    for attempt in range(20):
        req = urllib.request.Request(api + path, headers=headers, method=method, data=body)
        try:
            with urllib.request.urlopen(req, timeout=2) as resp:
                result = resp.status
        except urllib.error.HTTPError as ex:
            result = ex.code
        except urllib.error.URLError:
            time.sleep(.4)
            continue
        break
    if result != expected:
        raise AssertionError(f"R03 {label} expected HTTP {expected}, received {result}")
    print(f"[PASS R03-HTTP-{label}] old A handle -> {expected}", flush=True)
