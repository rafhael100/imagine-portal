#!/usr/bin/env python3
"""Buyer-bound HTTP GET of exact bytes on ONE synthetic Landing order.

Never claims Portal-native login or legal/customer approval.
"""
import hashlib
import json
import os
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent
LOGIN = "http://127.0.0.1:8765"
PREVIEW = "http://127.0.0.1:8766/lab/private-preview?order=landing-order-001"
assert os.environ.get("APP_ENV") == "testing"
assert os.environ.get("LANDING_EXACT_CLICK_LAB") == "1"
assert os.environ.get("LANDING_PREVIEW_GET_LAB") == "1"
assert os.environ.get("GITHUB_ACTIONS") == "true"
checks = 0
def ok(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1

def fixture(mode):
    process = subprocess.run(
        ["php", str(ROOT / "private_preview_fixture.php"), mode],
        check=True, capture_output=True, text=True, env=os.environ.copy(),
    )
    return process.stdout.strip()

evidence = json.loads(fixture("inspect"))
expected = evidence["htmlSha256"]
ok(evidence["contractRevision"] == 2 and evidence["operational"] is False,
   "same clicked revision and no operational authority")
with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True, args=["--no-sandbox"])
    anon = browser.new_context()
    response = anon.request.get(PREVIEW)
    ok(response.status == 403, "anonymous must not receive HTML")
    ok(b"Landing fict" not in response.body(), "denied response hides HTML")

    foreign = browser.new_context()
    other = foreign.new_page()
    other.goto(LOGIN + "/lab/login")
    other.locator("#fixture").select_option("buyer-foreign")
    other.locator("#login").click()
    ok(foreign.request.get(PREVIEW).status == 403, "different tenant denied")

    same_tenant = browser.new_context()
    bad = same_tenant.new_page()
    bad.goto(LOGIN + "/lab/login")
    bad.locator("#fixture").select_option("buyer-B")
    bad.locator("#login").click()
    ok(same_tenant.request.get(PREVIEW).status == 403,
       "another buyer in same tenant denied")

    correct = browser.new_context()
    page = correct.new_page()
    page.goto(LOGIN + "/lab/login")
    page.locator("#fixture").select_option("buyer-A")
    page.locator("#login").click()
    response = page.goto(PREVIEW)
    ok(response is not None and response.status == 200, "same buyer GET 200")
    raw = response.body()
    observed = hashlib.sha256(raw).hexdigest()
    ok(observed == expected, "raw browser HTTP body SHA equals PG18 persisted SHA")
    ok(page.get_by_role("heading", name="Landing fictícia para teste privado").is_visible(),
       "buyer actually saw private synthetic HTML")
    ok(response.headers.get("cache-control", "").find("no-store") >= 0,
       "preview HTTP response must not be cached")
    ok("sandbox" in response.headers.get("content-security-policy", ""),
       "preview HTTP response sandboxed")
    ok(response.headers.get("x-landing-lab-only") == "synthetic-no-go",
       "no client or production grant")
    ok(correct.request.get("http://127.0.0.1:8766/lab/private-preview?order=other-order").status == 403,
       "other order denied even with same browser session")

    print(fixture("corrupt"))
    ok(correct.request.get(PREVIEW).status == 403,
       "tampered artifact SHA denies even the correct buyer")
    print(fixture("restore"))
    ok(hashlib.sha256(correct.request.get(PREVIEW).body()).hexdigest() == expected,
       "restored exact fixture still matches body SHA")
    print(fixture("revoke"))
    ok(correct.request.get(PREVIEW).status == 403,
       "revoked briefing denies a previously valid preview GET")
    browser.close()

print(f"LANDING PRIVATE BUYER HTTP GET LAB PASS: {checks} new checks;")
print(f"HTTP_BODY_SHA256={observed} BRIEF_SHA256={evidence['briefSha256']}")
print("BUYER_AUTH=LAB_SESSION_ONLY; APPROVAL=FIXTURE_ONLY; SITES_RENDERER=NOT_INVOKED;")
print("CLIENT_FINAL_ACCEPTANCE=NOT_TESTED; PUBLISH=NO_GO; PSP=NOT_CALLED; LEAD=NOT_TESTED")
