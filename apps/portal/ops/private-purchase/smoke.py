#!/usr/bin/env python3
"""NON-MUTATING loopback smoke for privately staged synthetic Portal.

No passwords, cookies, account signup, orders, payments or briefings.
Requires --probe to touch localhost and refuses public/domain targets.
Separate HTTPS Basic-Auth perimeter probe: check_public_boundary.py --probe.
"""
from __future__ import annotations

import argparse
import json
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:18083"
LOGIN = "https://portal-dev.imaginecomunica.com.br/_preview/portal"
FAKE_ORDER = "TEST-00000000000000000000000000000000"


class RefuseRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


def assert_expected(path: str, code: int, headers, expected_code: int) -> None:
    assert code == expected_code, (path, code, expected_code)
    assert headers.get("Cache-Control") == "no-store, private", (
        "Synthetic preview must never be cached", path
    )
    assert headers.get("X-Robots-Tag") == "noindex, nofollow", path
    if expected_code == 302:
        loc = urllib.parse.urlsplit(headers.get("Location", ""))
        assert loc.scheme == "https" and loc.netloc == "portal-dev.imaginecomunica.com.br"
        assert loc.path == "/_preview/portal" and not loc.query and not loc.fragment


def self_test() -> None:
    from email.message import Message
    headers = Message()
    headers["Cache-Control"] = "no-store, private"
    headers["X-Robots-Tag"] = "noindex, nofollow"
    headers["Location"] = LOGIN
    for path in ("/_preview/portal/admin/orders", "/_preview/portal/client/overview"):
        assert_expected(path, 302, headers, 302)
    for path in ("/_rehearsal/purchase", "/_rehearsal/purchase/orders/" + FAKE_ORDER):
        assert_expected(path, 404, headers, 404)
    for code in (200, 201, 301, 403):
        try:
            assert_expected("/_rehearsal/purchase", code, headers, 404)
        except AssertionError:
            pass
        else:
            raise AssertionError("Legacy shared checkout cannot be accepted in VPS smoke.")
    assert BASE == "http://127.0.0.1:18083"
    print("PASS offline private VPS routing smoke: no network or mutations.")


def probe() -> None:
    opener = urllib.request.build_opener(RefuseRedirect())
    cases = (
        ("/_preview/portal", 200),
        ("/_preview/portal/register", 200),
        ("/_preview/portal/client/overview", 302),
        ("/_preview/portal/admin/orders", 302),
        ("/_preview/portal/shop/orders/" + FAKE_ORDER, 403),
        ("/_rehearsal/purchase", 404),
        ("/_rehearsal/purchase/orders/" + FAKE_ORDER, 404),
    )
    for path, expected in cases:
        assert path.startswith("/") and not path.startswith("//")
        request = urllib.request.Request(
            BASE + path, method="GET", headers={"User-Agent": "Imagine-Private-Preview-ReadOnly-Smoke/2"}
        )
        try:
            with opener.open(request, timeout=6) as response:
                code, headers = response.status, response.headers
                body = response.read(8000)
        except urllib.error.HTTPError as error:
            code, headers = error.code, error.headers
            body = error.read(8000)
        assert_expected(path, code, headers, expected)
        if path == "/_preview/portal":
            assert b"_token" in body or b"Bem-vindo" in body, (
                "Private Portal login must be reachable on loopback"
            )
        print("PASS read-only local Portal", path, "status", code)

    request = urllib.request.Request(BASE + "/up", method="GET")
    with opener.open(request, timeout=6) as response:
        assert response.status == 200
        health = json.loads(response.read(512))
        assert health == {"synthetic_preview": True}
    print("PASS local synthetic Portal health; no orders or sessions created.")
    print("NO-GO: loopback smoke is not Basic Auth/TLS, IAM, PSP or customer permission evidence.")


def main() -> int:
    cli = argparse.ArgumentParser(description=__doc__)
    mode = cli.add_mutually_exclusive_group(required=True)
    mode.add_argument("--self-test", action="store_true", help="Offline only")
    mode.add_argument("--probe", action="store_true", help="GET only from fixed localhost URL")
    args = cli.parse_args()
    self_test() if args.self_test else probe()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
