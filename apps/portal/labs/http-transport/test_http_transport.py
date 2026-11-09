#!/usr/bin/env python3
"""F5: actual HTTP/1.1 over 127.0.0.1, no libraries and no real user data.

Both modes use a non-testing Laravel kernel. This matters because Laravel
Feature/HTTP tests under APP_ENV=testing skip CSRF token verification.
"""
import http.cookiejar
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

MODE = sys.argv[1] if len(sys.argv) > 1 else ""
if MODE not in ("normal", "secure-attribute"):
    raise SystemExit("usage: python test_http_transport.py normal|secure-attribute")

PORT = 18945 if MODE == "normal" else 18946
ORIGIN = f"http://127.0.0.1:{PORT}"
PREFIX = "/_loopback/transport/"
COOKIE_NAME = "portal_loopback_session"
cookie_jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookie_jar))
checks = 0


def assert_true(value, message):
    global checks
    if not value:
        raise AssertionError(message)
    checks += 1


def req(path, method="GET", data=None, token=None, origin=None):
    if not path.startswith("/"):
        raise AssertionError("Absolute loopback path required")
    headers = {"Accept": "application/json", "Cache-Control": "no-store"}
    if data is not None:
        headers["Content-Type"] = "application/json"
        payload = json.dumps(data).encode()
    else:
        payload = b"" if method == "POST" else None
    if token is not None:
        headers["X-CSRF-TOKEN"] = token
    if origin is not None:
        headers["Origin"] = origin

    request = urllib.request.Request(ORIGIN + path, data=payload, headers=headers, method=method)
    try:
        with opener.open(request, timeout=5) as response:
            body = response.read()
            return response.status, response.headers, body
    except urllib.error.HTTPError as exc:
        body = exc.read()
        return exc.code, exc.headers, body


def response_json(result):
    try:
        return json.loads(result[2].decode("utf-8"))
    except Exception as exc:
        raise AssertionError(f"Expected JSON in HTTP status={result[0]}") from exc


def session_cookie():
    matches = [c for c in cookie_jar if c.name == COOKIE_NAME]
    assert_true(len(matches) == 1, "Expected exactly one local session cookie")
    return matches[0].value


def cookie_set_headers(response):
    return response[1].get_all("Set-Cookie") or []


for attempt in range(60):
    try:
        ready = req(PREFIX + "csrf")
        if ready[0] == 200:
            break
    except (urllib.error.URLError, TimeoutError, ConnectionRefusedError):
        pass
    time.sleep(0.15)
else:
    raise AssertionError("Loopback Laravel HTTP server did not start")

assert_true(ready[0] == 200, "Initial CSRF GET must succeed")
body = response_json(ready)
assert_true(body.get("mode") == "loopback_only", "Wrong harness")
assert_true(isinstance(body.get("csrf_token"), str) and len(body["csrf_token"]) > 20, "Missing Laravel CSRF token")
token = body["csrf_token"]

session_headers = [h for h in cookie_set_headers(ready)
                   if h.lower().startswith(COOKIE_NAME.lower() + "=")]
assert_true(len(session_headers) == 1, "Missing session Set-Cookie")
header = session_headers[0].lower()
assert_true("httponly" in header, "Laravel session cookie not HttpOnly")
assert_true("samesite=strict" in header.replace(" ", ""), "Laravel SameSite policy not Strict")
assert_true("path=/" in header.replace(" ", ""), "Cookie path should be /")
assert_true("domain=" not in header, "Loopback cookie must be host-only")
assert_true("access-control-allow-origin" not in str(ready[1]).lower(), "Unexpected wildcard CORS policy")

if MODE == "secure-attribute":
    assert_true("; secure" in header, "Secure attribute missing from hardened cookie")
    assert_true("no-store" in (ready[1].get("Cache-Control") or "").lower(), "CSRF response cached")
    print(f"[PASS F5 HTTP] SECURE attribute only: {checks} checks at 127.0.0.1:{PORT}; no HTTPS claimed")
    sys.exit(0)

assert_true("; secure" not in header, "HTTP localhost functional test must use non-Secure cookie")
old_session_cookie = session_cookie()

missing = req(PREFIX + "login", "POST", {"proof": "lab-only"}, origin=ORIGIN)
assert_true(missing[0] == 419, "Real HTTP POST without CSRF must be rejected with 419")

wrong = req(PREFIX + "login", "POST", {"proof": "lab-only"}, token="incorrect", origin=ORIGIN)
assert_true(wrong[0] == 419, "Invalid CSRF token must be rejected")

state = req(PREFIX + "state")
assert_true(state[0] == 200 and not response_json(state)["authenticated"], "Missing/invalid CSRF created a session")

login = req(PREFIX + "login", "POST", {"proof": "lab-only"}, token=token, origin=ORIGIN)
assert_true(login[0] == 200, "Valid CSRF+session must be accepted")
login_json = response_json(login)
assert_true(login_json.get("message") == "synthetic_transport_session_rotated", "Login response mismatch")
assert_true(session_cookie() != old_session_cookie, "Session ID was not rotated at synthetic login")
new_token = login_json["csrf_token"]
assert_true(isinstance(new_token, str) and len(new_token) > 20, "New CSRF token missing")

signed_state = req(PREFIX + "state")
assert_true(signed_state[0] == 200 and response_json(signed_state)["authenticated"],
            "Laravel file session did not persist between real HTTP requests")
assert_true(req("/_lab/portal/login")[0] == 404, "CLI-only auth lab leaked into HTTP server")
assert_true(req("/portal")[0] == 404, "Private Portal route exposed")
assert_true(req(PREFIX + "missing")[0] == 404, "Unexpected lab route exposure")

bad_logout = req(PREFIX + "logout", "POST", {}, token="bad", origin=ORIGIN)
assert_true(bad_logout[0] == 419, "Logout without valid CSRF must be rejected")
assert_true(response_json(req(PREFIX + "state"))["authenticated"], "Invalid CSRF logged out user")

logout = req(PREFIX + "logout", "POST", {}, token=new_token, origin=ORIGIN)
assert_true(logout[0] == 200, "Valid CSRF logout failed")
assert_true(not response_json(req(PREFIX + "state"))["authenticated"], "Logout did not invalidate HTTP session")

replay = req(PREFIX + "login", "POST", {"proof": "lab-only"}, token=new_token, origin=ORIGIN)
assert_true(replay[0] == 419, "Stale CSRF token accepted after logout")
assert_true(not response_json(req(PREFIX + "state"))["authenticated"], "Stale CSRF reauthenticated actor")

assert_true("no-store" in (ready[1].get("Cache-Control") or "").lower(), "CSRF response cached")
print(f"[PASS F5 HTTP] {checks} checks; missing/invalid CSRF=419; session rotation, file persistence, logout and replay blocked; localhost={PORT}")
