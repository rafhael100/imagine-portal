#!/usr/bin/env python3
"""Read-only HTTPS/Basic Auth perimeter gate for the synthetic DEV Portal.

Never supplies credentials. Never reads environment secrets. Cannot deploy.
Uses the system trust store, rejects redirects and fails if a guest gets access.

No live probes occur without an explicit --probe argument.
"""
from __future__ import annotations

import argparse
import ssl
import urllib.error
import urllib.request

BASE = "https://portal-dev.imaginecomunica.com.br"
PATHS = (
    "/_preview/portal",
    "/_preview/portal/",
    "/_preview/portal/login",
    "/_preview/portal/logo",
    "/_preview/portal/client/overview",
    "/_preview/portal/admin/overview",
    "/_rehearsal/purchase",
    "/_rehearsal/purchase/orders/TEST-" + "0" * 32,
)


class RefuseRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, url):
        return None


def validate_unauthenticated(code: int, headers) -> bool:
    """An unauthenticated external request must meet the FIRST auth boundary."""
    if code != 401:
        return False
    challenge = headers.get("WWW-Authenticate", "")
    return isinstance(challenge, str) and challenge.lower().startswith("basic ")


def probe() -> int:
    opener = urllib.request.build_opener(
        urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        RefuseRedirect(),
    )
    failure_count = 0
    for path in PATHS:
        req = urllib.request.Request(
            BASE + path,
            headers={"User-Agent": "Imagine-Private-Preview-Perimeter-Check/1"},
            method="GET",
        )
        try:
            with opener.open(req, timeout=8) as response:
                code = response.status
                headers = response.headers
        except urllib.error.HTTPError as error:
            code = error.code
            headers = error.headers
        except (urllib.error.URLError, TimeoutError, ssl.SSLError, OSError) as exc:
            print(f"FAIL {path}: HTTPS verification/transport unavailable ({type(exc).__name__})")
            failure_count += 1
            continue
        ok = validate_unauthenticated(code, headers)
        print(f"{'PASS' if ok else 'FAIL'} {path}: HTTPS guest status {code}")
        failure_count += int(not ok)
    if failure_count:
        print(f"NO-GO: {failure_count}/{len(PATHS)} private routes failed perimeter checks")
        return 1
    print("PASS: all eight private development routes require Basic Auth; no credentials sent")
    print("NOTE: does not verify login, identity, business grants, database or anti-rollback")
    return 0


def self_test() -> int:
    class Headers(dict):
        def get(self, name, default=""):
            return super().get(name, default)

    good = Headers({"WWW-Authenticate": 'Basic realm="Imagine development"'})
    assert validate_unauthenticated(401, good)
    for status in (200, 201, 302, 403, 404, 500):
        assert not validate_unauthenticated(status, good), status
    assert not validate_unauthenticated(401, Headers())
    assert not validate_unauthenticated(401, Headers({"WWW-Authenticate": "Bearer"}))
    assert BASE.startswith("https://") and len(PATHS) == 8 and len(set(PATHS)) == 8
    assert all(p.startswith("/") and not p.startswith("//") for p in PATHS)
    print("PASS offline Basic-Auth perimeter verifier self-test; zero network requests")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--self-test", action="store_true",
                      help="Validate refusal logic without any HTTP requests")
    mode.add_argument("--probe", action="store_true",
                      help="Explicitly check only the fixed HTTPS development host without credentials")
    args = parser.parse_args()
    return self_test() if args.self_test else probe()


if __name__ == "__main__":
    raise SystemExit(main())
