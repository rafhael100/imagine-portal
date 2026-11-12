#!/usr/bin/env python3
"""Local private-gateway smoke. Never print token, cookies or response payload."""
import http.client
import json
import os
import sys

HOST = "portal-dev.imaginecomunica.com.br"

def request(method, path, payload=None, token=None, origin=None, cookie=None, host=HOST):
    connection = http.client.HTTPConnection("127.0.0.1", 18082, timeout=8)
    headers = {"Host": host, "Accept": "application/json"}
    if payload is not None:
        headers["Content-Type"] = "application/json"
    if token is not None:
        headers["X-CSRF-TOKEN"] = token
    if origin is not None:
        headers["Origin"] = origin
        headers["Sec-Fetch-Site"] = "same-origin"
    if cookie is not None:
        headers["Cookie"] = cookie
    try:
        connection.request(method, path, json.dumps(payload) if payload is not None else None, headers)
        response = connection.getresponse()
        return response.status, dict(response.getheaders()), response.read(16384)
    finally:
        connection.close()

def main():
    assert request("GET", "/up")[0] == 200
    page_status, _, page = request("GET", "/_development/auth/login")
    assert page_status == 200 and "Seu acesso está em preparação.".encode() in page
    for asset in ("ui.css", "ui.js", "logo.png"):
        assert request("GET", "/_development/auth/" + asset)[0] == 200
    status, headers, body = request("GET", "/_development/auth/csrf")
    assert status == 200
    token = json.loads(body)["csrf_token"]
    assert isinstance(token, str) and len(token) > 20
    cookie = headers.get("Set-Cookie", headers.get("set-cookie", ""))
    assert "__Host-imagine-portal-session=" in cookie
    assert "secure" in cookie.lower() and "httponly" in cookie.lower()
    assert "domain=" not in cookie.lower()
    cookie = cookie.split(";", 1)[0]
    assert request("GET", "/_development/auth/session", cookie=cookie)[0] == 401
    credentials = {"email": "absent@example.invalid", "password": "synthetic-invalid-only",
                   "organization": "22222222-2222-4222-8222-222222222222"}
    assert request("POST", "/_development/auth/password", credentials, token, "https://" + HOST, cookie)[0] == 401
    assert request("POST", "/_development/auth/password", credentials, token, "https://external.invalid", cookie)[0] in (403, 419)
    assert request("POST", "/_development/auth/password", credentials, None, "https://" + HOST, cookie)[0] == 419
    assert request("GET", "/login")[0] == 404
    assert request("GET", "/.env")[0] == 404
    assert request("GET", "/_development/auth/password")[0] == 403
    if len(sys.argv) == 3 and sys.argv[1] == "--save":
        # Invalid-password handling deliberately rotates the earlier session.
        # Persist a fresh CSRF-only session to test an unchanged cookie after restart.
        fresh_status, fresh_headers, fresh_body = request("GET", "/_development/auth/csrf")
        assert fresh_status == 200
        token = json.loads(fresh_body)["csrf_token"]
        cookie = fresh_headers.get("Set-Cookie", fresh_headers.get("set-cookie", "")).split(";", 1)[0]
        assert cookie.startswith("__Host-imagine-portal-session=")
        descriptor = os.open(sys.argv[2], os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, "w") as handle:
            json.dump({"token": token, "cookie": cookie}, handle)
    print("PASS private gateway: health, CSRF, host-only secure cookie, unauthenticated/invalid/external-origin refusals and route allowlist")

if __name__ == "__main__":
    try:
        if len(sys.argv) == 3 and sys.argv[1] == "--resume":
            with open(sys.argv[2]) as handle:
                previous = json.load(handle)
            try:
                credentials = {"email": "absent@example.invalid", "password": "synthetic-invalid-only",
                               "organization": "22222222-2222-4222-8222-222222222222"}
                assert request("POST", "/_development/auth/password", credentials, previous["token"],
                               "https://" + HOST, previous["cookie"])[0] == 401
                print("PASS encrypted cookie/session and CSRF retained across application restart")
            finally:
                os.unlink(sys.argv[2])
        else:
            assert len(sys.argv) == 1 or (len(sys.argv) == 3 and sys.argv[1] == "--save")
            main()
    except Exception:
        raise SystemExit("Private Portal gateway smoke failed; sensitive response details suppressed.")
