#!/usr/bin/env python3
"""F6 adversarial end-to-end localhost HTTP with real PG18 + synthetic MFA.

Only Python stdlib. Never a public server, never customer credentials.
"""
import hashlib
import hmac
import http.cookiejar
import json
import os
import subprocess
import time
import urllib.error
import urllib.request

BASE = "http://127.0.0.1:18947"
PREFIX = "/_loopback/pg/"
COOKIE = "portal_f6_session"
FIXTURE_SECRET = b"12345678901234567890"  # publicly known, invented fixture
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
checks = 0


def check(value, description):
    global checks
    if not value:
        raise AssertionError(description)
    checks += 1


def totp():
    step = int(time.time() // 30)
    digest = hmac.new(FIXTURE_SECRET, step.to_bytes(8, "big"), hashlib.sha1).digest()
    offset = digest[-1] & 15
    number = int.from_bytes(digest[offset:offset + 4], "big") & 0x7fffffff
    return f"{number % 1000000:06d}"


def request(path, method="GET", data=None, csrf=None, origin=BASE, extra_headers=None):
    headers = {"Accept": "application/json", "Cache-Control": "no-store"}
    if data is not None:
        headers["Content-Type"] = "application/json"
        body = json.dumps(data).encode("utf-8")
    else:
        body = b"" if method == "POST" else None

    if csrf is not None:
        headers["X-CSRF-TOKEN"] = csrf
    if origin is not None and method != "GET":
        headers["Origin"] = origin
    if extra_headers:
        headers.update(extra_headers)

    req = urllib.request.Request(BASE + path, method=method, data=body, headers=headers)
    try:
        with opener.open(req, timeout=8) as response:
            return response.status, response.headers, response.read()
    except urllib.error.HTTPError as exc:
        return exc.code, exc.headers, exc.read()


def json_result(value):
    try:
        return json.loads(value[2].decode("utf-8"))
    except Exception as e:
        raise AssertionError(f"Non-JSON response: status {value[0]}") from e


def session_cookie():
    matches = [c for c in jar if c.name == COOKIE]
    check(len(matches) == 1, "Exactly one F6 session cookie required")
    return matches[0].value


def offline_revoke(action):
    env = os.environ.copy()
    env.update({
        "APP_ENV": "testing",
        "PORTAL_CLIENT_LAB_ENABLED": "1",
        "DB_USERNAME": "postgres",
        "DB_PASSWORD": "portal-lab-admin-only",
        "SESSION_DRIVER": "array",
    })
    output = subprocess.run(
        ["php", "labs/http-pg/revoke_lab.php", action],
        env=env, capture_output=True, text=True, timeout=15, check=True,
    )
    check("[PASS F6]" in output.stdout, f"Offline {action} failed")


for attempt in range(50):
    try:
        initial = request(PREFIX + "csrf")
        if initial[0] == 200:
            break
    except (urllib.error.URLError, ConnectionRefusedError, TimeoutError):
        pass
    time.sleep(0.15)
else:
    raise AssertionError("F6 localhost Laravel/PG server did not become ready")

check(initial[0] == 200, "CSRF bootstrap GET failed")
info = json_result(initial)
check(info["mode"] == "pg_mfa_synthetic", "Wrong HTTP harness")
token = info["csrf_token"]
check(isinstance(token, str) and len(token) >= 30, "No real session CSRF token")
cookie_headers = initial[1].get_all("Set-Cookie") or []
session_headers = [x.lower() for x in cookie_headers if x.lower().startswith(COOKIE + "=")]
check(len(session_headers) == 1, "Missing F6 session cookie")
cookie = session_headers[0]
check("httponly" in cookie and "samesite=strict" in cookie.replace(" ", ""), "Cookie flags unsafe")
check("domain=" not in cookie and "path=/" in cookie, "Cookie must be host-only")
check("no-store" in (initial[1].get("Cache-Control") or "").lower(), "CSRF bootstrap may be cached")
cookie_before = session_cookie()

creds = {
    "email": "a@example.invalid",
    "password": "synthetic-pass-A-2026",
    "totp": totp(),
}
check(request(PREFIX + "organizations")[0] == 401, "Unauthenticated organizations request not denied")
check(request(PREFIX + "login", "POST", creds, csrf=None)[0] == 419, "Missing CSRF should be 419")
check(request(PREFIX + "login", "POST", creds, csrf="invalid")[0] == 419, "Wrong CSRF should be 419")
check(request(PREFIX + "login", "POST", creds, csrf=token, origin="https://outside.invalid")[0] == 403,
      "Foreign origin should be denied even with correct token")

check(request(PREFIX + "login", "POST", dict(creds, password="wrong-password"), csrf=token)[0] == 401,
      "Wrong password must fail")
valid = request(PREFIX + "login", "POST", creds, csrf=token)
check(valid[0] == 200, f"Real HTTP PG/MFA login failed: {valid[0]} {valid[2][:160]!r}")
login = json_result(valid)
check(login.get("message") == "synthetic_pg_mfa_session", "Wrong login type")
check(session_cookie() != cookie_before, "Session cookie not rotated after MFA")
rotated_csrf = login["csrf_token"]
check(isinstance(rotated_csrf, str) and len(rotated_csrf) >= 30, "Rotated CSRF missing")
check("portal_lab_v2_token" not in str(login) and "token_digest" not in str(login),
      "Internal credential leaked in response")

orgs = request(PREFIX + "organizations")
check(orgs[0] == 200, "Authorized organizations failed")
check([o["id"] for o in json_result(orgs)["organizations"]] == ["org-a"], "Cross-tenant org leaked")
services = request(PREFIX + "organizations/org-a/services")
check(services[0] == 200, "Own services failed")
check([s["id"] for s in json_result(services)["services"]] == ["service-a"], "Wrong service scope")
check(request(PREFIX + "organizations/org-b/services")[0] == 404, "Foreign services leaked")
check(request("/_lab/portal/login")[0] == 404, "F1 CLI-only lab routes leaked")
check(request("/portal")[0] == 404, "Production-like Portal route was accidentally opened")
check(request(PREFIX + "missing")[0] == 404, "Unexpected HTTP route open")

payload = {
    "service_id": "service-a", "title": "F6 local synthetic request",
    "idempotency_key": "F6-LOCAL-2026-IDEMPOTENCY",
}
check(request(PREFIX + "organizations/org-a/requests", "POST", payload, csrf=None)[0] == 419,
      "Request create bypassed CSRF")
check(request(PREFIX + "organizations/org-a/requests", "POST", payload, csrf=rotated_csrf,
              origin="https://outside.invalid")[0] == 403,
      "Foreign origin accepted a valid token")
check(request(PREFIX + "organizations/org-a/requests", "POST", payload, csrf=rotated_csrf,
              extra_headers={"X-Portal-Actor": "portal-user-b"})[0] == 201,
      "Authorized request failed or trusted browser actor header")
first = request(PREFIX + "organizations/org-a/requests", "POST", payload, csrf=rotated_csrf)
check(first[0] == 200 and json_result(first).get("replayed") is True,
      "Idempotent replay failed")
check(request(PREFIX + "organizations/org-a/requests", "POST", dict(payload, title="changed"), csrf=rotated_csrf)[0] == 409,
      "Idempotency content conflict not rejected")
check(request(PREFIX + "organizations/org-b/requests", "POST",
              dict(payload, service_id="service-b"), csrf=rotated_csrf)[0] == 404,
      "Cross-organization request accepted")

offline_revoke("membership")
check(json_result(request(PREFIX + "organizations"))["organizations"] == [],
      "Revoked membership still visible via real HTTP")
check(request(PREFIX + "organizations/org-a/services")[0] == 404,
      "Revoked membership still reads service")
check(request(PREFIX + "organizations/org-a/requests", "POST", payload, csrf=rotated_csrf)[0] == 404,
      "Revoked membership still writes")

offline_revoke("account-revision")
check(request(PREFIX + "organizations")[0] == 401, "Account revision failed to invalidate DB-backed session")
check(request(PREFIX + "organizations/org-a/services")[0] == 401, "Revoked session read services")
logout = request(PREFIX + "logout", "POST", {}, csrf=rotated_csrf)
check(logout[0] == 200, "Logout after revocation failed")
check(request(PREFIX + "organizations")[0] == 401, "Logout failed to invalidate session")
check(request(PREFIX + "login", "POST", creds, csrf=rotated_csrf)[0] == 419,
      "Stale CSRF accepted after logout")
check(request(PREFIX + "organizations")[0] == 401, "Stale CSRF restored session")

print(f"[PASS F6 HTTP-PG] {checks} checks: real Laravel HTTP+CSRF, TOTP, PG session, RLS tenant A/B, idempotency, membership/account revocation and logout; localhost only")
