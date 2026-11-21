#!/usr/bin/env python3
"""Only new proof: real Sites renderer bytes of same clicked PG18 Landing order.

#1041/#1043/#1047 run first as prerequisites, not counted as new checks.
No Portal-native login, official writer, final buyer acceptance or publication.
"""
import hashlib
import json
import os
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

HERE = Path(__file__).resolve().parent
LOGIN = "http://127.0.0.1:8765/lab/login"
PREVIEW = "http://127.0.0.1:8766/lab/private-preview?order=landing-order-001"
assert os.getenv("APP_ENV") == "testing"
assert os.getenv("GITHUB_ACTIONS") == "true"
assert os.getenv("LANDING_EXACT_CLICK_LAB") == "1"
assert os.getenv("LANDING_PREVIEW_GET_LAB") == "1"
assert os.getenv("LANDING_SITES_RENDER_LAB") == "1"

checks = 0
def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1

def fixture(mode):
    run = subprocess.run(
        ["php", str(HERE / "private_preview_fixture.php"), mode],
        check=True, capture_output=True, text=True,
    )
    return run.stdout.strip()

evidence = json.loads(fixture("inspect"))
check(evidence["operational"] is False and evidence["contractRevision"] == 2,
      "rendered HTML remains lab-only and linked to accepted contract revision 2")
expected = evidence["htmlSha256"]
check(len(expected) == 64, "readback digest must be valid sha256")

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, args=["--no-sandbox"])
    anonymous = browser.new_context()
    check(anonymous.request.get(PREVIEW).status == 403, "anonymous denied")

    other_buyer = browser.new_context()
    page_other = other_buyer.new_page()
    page_other.goto(LOGIN)
    page_other.locator("#fixture").select_option("buyer-B")
    page_other.locator("#login").click()
    check(other_buyer.request.get(PREVIEW).status == 403,
          "different buyer same tenant denied the Sites HTML")

    buyer = browser.new_context()
    page = buyer.new_page()
    page.goto(LOGIN)
    page.locator("#fixture").select_option("buyer-A")
    page.locator("#login").click()
    response = page.goto(PREVIEW)
    check(response is not None and response.status == 200,
          "correct synthetic buyer receives real Sites-rendered HTML")
    body = response.body()
    observed = hashlib.sha256(body).hexdigest()
    check(observed == expected,
          "browser HTTP body exactly equals stored Sites renderer bytes")
    check(b'data-site-product="landing-page"' in body and
          b'data-site-product="onepage"' not in body,
          "Landing renderer selected, never reuse OnePage evidence")
    check(page.locator("#hero-title").inner_text() ==
          "Landing ficticia para testar leitura conjunta",
          "browser sees headline from the same approved fictional briefing")
    check(page.locator(".brand").first.inner_text() == "Marca Fictícia Imagine",
          "Sites-owned brand not delegated to briefing fixture")
    check(b"<script" not in body and b"<form" not in body and
          b"http://" not in body and b"https://" not in body,
          "no script, lead form, network destination or external resources")
    check("no-store" in response.headers.get("cache-control", "") and
          "sandbox" in response.headers.get("content-security-policy", ""),
          "private preview response is sandboxed, uncached")

    drift = subprocess.run(
        ["php", str(HERE / "sites_renderer_same_order_lab.php"), "drift-brief"],
        capture_output=True, text=True, check=True,
    )
    check("stale rendered SHA must be denied" in drift.stdout,
          "same-order approved briefing mutated AFTER render")
    check(buyer.request.get(PREVIEW).status == 403,
          "stale Sites HTML refused after briefing digest changes")
    browser.close()

print("LANDING SAME-ORDER SITES REAL RENDER BROWSER PASS: "
      f"{checks} new assertions")
print(f"HTTP_BODY_SHA256={observed} BRIEF_SHA256={evidence['briefSha256']}")
print("SITES_RENDERER=PINNED_REAL_ENGINE; SOURCE=APPROVED_FIXTURE_ONLY; "
      "BUYER_AUTH=LAB_SESSION_ONLY; CLIENT_FINAL_ACCEPTANCE=NOT_TESTED; "
      "PUBLISH=NO_GO; LEAD=NOT_TESTED")
