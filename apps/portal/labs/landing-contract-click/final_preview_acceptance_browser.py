#!/usr/bin/env python3
"""New Landing-only proof: final click bound to exact HTTP artifact and generation.

Prior #1041/#1043/#1047/#1050 setup is reused. No native auth, official
brief/terms writer, lead, publication, release or valid legal consent.
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
FINAL = "http://127.0.0.1:8767/lab/final-review"
ACCEPT = "http://127.0.0.1:8767/lab/final-accept"

for key in (
    "GITHUB_ACTIONS", "LANDING_EXACT_CLICK_LAB", "LANDING_PREVIEW_GET_LAB",
    "LANDING_SITES_RENDER_LAB", "LANDING_FINAL_ACCEPT_LAB",
):
    assert os.getenv(key) == "true" if key == "GITHUB_ACTIONS" else os.getenv(key) == "1"
assert os.getenv("APP_ENV") == "testing"

checks = 0

def check(value, message):
    global checks
    if not value:
        raise AssertionError(message)
    checks += 1

def cli(file, mode):
    completed = subprocess.run(
        ["php", str(HERE / file), mode],
        check=True, capture_output=True, text=True,
    )
    return completed.stdout.strip()

def readback():
    return json.loads(cli("final_preview_acceptance_lab.php", "readback"))

def login(page, fixture):
    page.goto(LOGIN)
    page.locator("#fixture").select_option(fixture)
    page.locator("#login").click()

def preview(page, digest):
    response = page.goto(PREVIEW)
    check(response is not None and response.status == 200,
          "current buyer sees private real Sites-rendered HTML over HTTP")
    content = response.body()
    check(hashlib.sha256(content).hexdigest() == digest,
          "browser response bytes match current PG18 artifact digest")
    check(b'data-site-product="landing-page"' in content
          and b'data-site-product="onepage"' not in content,
          "Landing product isolation")
    return content

def review(page, generation, digest):
    response = page.goto(FINAL)
    check(response is not None and response.status == 200,
          "same synthetic buyer sees final review in browser")
    check(page.locator("#order").inner_text() == "landing-order-001",
          "final review refers to same accepted contract order")
    check(page.locator("#artifact-generation").inner_text() == str(generation),
          "final review displays current artifact generation")
    check(page.locator("#html-digest").inner_text() == digest,
          "final review displays exact HTML SHA, not terms SHA")
    check(page.locator("#brief-revision").inner_text() == "1",
          "current synthetic approved briefing revision")
    return {
        item["name"]: item["value"]
        for item in page.locator('input[type="hidden"]').evaluate_all(
            "(nodes) => nodes.map(n => ({name:n.name, value:n.value}))"
        )
    }

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True, args=["--no-sandbox"])
    anonymous = browser.new_context()
    check(anonymous.request.get(FINAL).status == 403,
          "anonymous final review denied")
    other = browser.new_context()
    other_page = other.new_page()
    login(other_page, "buyer-B")
    check(other.request.get(FINAL).status == 403,
          "other buyer in same tenant final review denied")
    foreign = browser.new_context()
    foreign_page = foreign.new_page()
    login(foreign_page, "buyer-foreign")
    check(foreign.request.get(FINAL).status == 403,
          "different tenant final review denied")

    buyer = browser.new_context()
    page = buyer.new_page()
    login(page, "buyer-A")
    original = readback()
    check(original["currentSource"] and not original["labFinalAccepted"]
          and original["artifactGeneration"] == 1
          and original["operational"] is False,
          "no default final consent from clicked purchase terms")
    original_bytes = preview(page, original["artifactSha256"])
    first_form = review(page, 1, original["artifactSha256"])
    check(buyer.request.post(ACCEPT, form=first_form).status == 409,
          "omitting final checkbox denies acceptance")
    check(not readback()["labFinalAccepted"],
          "no acceptance row following checkbox omission")
    page.locator("#final-checkbox").check()
    page.locator("#final-confirm").click()
    check("LANDING LAB FINAL ACCEPTED ONLY" in page.locator("body").inner_text(),
          "explicit buyer browser click persisted for exact generation and SHA")
    check(readback()["labFinalAccepted"],
          "independent PHP process and PG18 connection read accepted current artifact")
    check(buyer.request.post(ACCEPT, form={**first_form, "confirm": "yes"}).status == 409,
          "consumed challenge cannot be replayed")
    check(other.request.post(ACCEPT, form={**first_form, "confirm": "yes"}).status == 403,
          "other buyer cannot reuse a presentation token")

    stale_tab = buyer.new_page()
    stale_form = review(stale_tab, 1, original["artifactSha256"])
    cli("final_preview_acceptance_lab.php", "change-artifact")
    changed = readback()
    check(changed["artifactGeneration"] == 2
          and changed["artifactSha256"] != original["artifactSha256"]
          and not changed["labFinalAccepted"],
          "post-render artifact adjustment invalidates previous final acceptance")
    check(buyer.request.post(ACCEPT, form={**stale_form, "confirm": "yes"}).status == 409,
          "stale pre-change presentation cannot accept new SHA")
    changed_bytes = preview(page, changed["artifactSha256"])
    check(changed_bytes != original_bytes,
          "buyer observed newly changed artifact")
    second_form = review(page, 2, changed["artifactSha256"])
    page.locator("#final-checkbox").check()
    page.locator("#final-confirm").click()
    check("LANDING LAB FINAL ACCEPTED ONLY" in page.locator("body").inner_text(),
          "new explicit click required after artifact revision")
    check(readback()["labFinalAccepted"],
          "new generation receipt verified by independent reader")

    cli("final_preview_acceptance_lab.php", "restore-artifact")
    restored = readback()
    check(restored["artifactGeneration"] == 3
          and restored["artifactSha256"] == original["artifactSha256"]
          and not restored["labFinalAccepted"],
          "restoring byte-identical old HTML NEVER revives superseded approval")
    preview(page, restored["artifactSha256"])
    cli("private_preview_fixture.php", "revoke")
    check(not readback()["currentSource"] and not readback()["labFinalAccepted"],
          "reviewer fixture revocation hides current approved source and final receipt")
    check(buyer.request.get(PREVIEW).status == 403,
          "revoked synthetic briefing denies private rendered HTML")
    check(buyer.request.get(FINAL).status == 403,
          "revoked briefing denies another acceptance presentation")
    browser.close()

print(f"LANDING SAME-ORDER FINAL BUYER ARTIFACT ACCEPT LAB PASS: {checks} new checks")
print("PREVIEW_INITIAL_SHA256=" + original["artifactSha256"])
print("PREVIEW_CHANGED_SHA256=" + changed["artifactSha256"])
print("RESTORE_DIGEST_EQUAL_BUT_GENERATION=3; STALE_APPROVAL=DENIED")
print("PRODUCT=LANDING_ONLY; SOURCE=BRIEF_APPROVAL_FIXTURE; BUYER=LAB_SESSION; "
      "FINAL_ACCEPTANCE=SYNTHETIC_ONLY; PSP=NOT_CALLED; PUBLISH=NO_GO; "
      "LEAD=NOT_TESTED; PORTAL_NATIVE_AUTH=NOT_TESTED")
