#!/usr/bin/env python3
"""Landing-only buyer rework v1 -> independent editorial fixture v2 -> final click.

One persistent fictional order. The Sites renderer is pinned and real, while
login, approval actor, order and persistence are strictly disposable laboratory
fixtures. Not an official release, provider, publication or legal acceptance.
"""
import hashlib
import json
import os
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright

DIR = Path(__file__).resolve().parent
LOGIN = "http://127.0.0.1:8765/lab/login"
PREVIEW = "http://127.0.0.1:8766/lab/private-preview?order=landing-order-001"
FINAL = "http://127.0.0.1:8767/lab/final-review"
ACCEPT = "http://127.0.0.1:8767/lab/final-accept"
REWORK = "http://127.0.0.1:8768/lab/request-review"
REQUEST = "http://127.0.0.1:8768/lab/request-rework"

assert os.getenv("GITHUB_ACTIONS") == "true"
assert os.getenv("APP_ENV") == "testing"
for variable in ("LANDING_EXACT_CLICK_LAB", "LANDING_PREVIEW_GET_LAB",
                 "LANDING_SITES_RENDER_LAB", "LANDING_FINAL_ACCEPT_LAB",
                 "LANDING_REWORK_V2_LAB"):
    assert os.getenv(variable) == "1"

total = 0

def check(predicate, message):
    global total
    if not predicate:
        raise AssertionError(message)
    total += 1

def php(name, mode):
    run = subprocess.run(["php", str(DIR / name), mode],
                         capture_output=True, text=True, check=True)
    return run.stdout.strip()

def rework_state():
    return json.loads(php("buyer_rework_v2_lab.php", "readback"))

def artifact_state():
    return json.loads(php("final_preview_acceptance_lab.php", "readback"))

def login(page, buyer):
    page.goto(LOGIN)
    page.locator("#fixture").select_option(buyer)
    page.locator("#login").click()

def hidden(page):
    return {i["name"]: i["value"] for i in
            page.locator('input[type="hidden"]').evaluate_all(
                "(nodes) => nodes.map(n => ({name:n.name,value:n.value}))")}

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, args=["--no-sandbox"])
    anonymous = browser.new_context()
    check(anonymous.request.get(REWORK).status == 403,
          "anonymous cannot request Landing rework")
    another = browser.new_context()
    another_page = another.new_page()
    login(another_page, "buyer-B")
    check(another.request.get(REWORK).status == 403,
          "other buyer same tenant cannot request original buyer rework")
    foreign = browser.new_context()
    foreign_page = foreign.new_page()
    login(foreign_page, "buyer-foreign")
    check(foreign.request.get(REWORK).status == 403,
          "different tenant cannot request original buyer rework")
    buyer = browser.new_context()
    page = buyer.new_page()
    login(page, "buyer-A")

    first = artifact_state()
    check(first["artifactGeneration"] == 1
          and first["briefRevision"] == 1 and not first["labFinalAccepted"],
          "initial v1 is unaccepted and independently readable")
    response = page.goto(PREVIEW)
    first_bytes = response.body()
    check(response.status == 200
          and hashlib.sha256(first_bytes).hexdigest() == first["artifactSha256"],
          "buyer observes exact original v1 HTTP bytes")

    page.goto(FINAL)
    page.locator("#final-checkbox").check()
    page.locator("#final-confirm").click()
    check(artifact_state()["labFinalAccepted"],
          "previous v1 final acceptance persisted on same order")

    stale_page = buyer.new_page()
    stale_page.goto(FINAL)
    old_accept_form = hidden(stale_page)

    page.goto(REWORK)
    check(page.locator("#brief-version").inner_text() == "1"
          and page.locator("#html-digest").inner_text() == first["artifactSha256"],
          "buyer sees precisely the v1 artifact targeted for rework")
    request_form = hidden(page)
    check(buyer.request.post(REQUEST, form=request_form).status == 409,
          "unchecked rework request cannot persist")
    check(not rework_state()["requested"],
          "no request row after unchecked attempt")
    page.locator("#confirm-rework").check()
    page.locator("#request-rework").click()
    check("BUYER REWORK REQUEST PERSISTED" in page.locator("body").inner_text(),
          "distinct buyer click persists a request instead of acceptance")
    requested = rework_state()
    check(requested["requested"] and requested["requestKind"] == "clarify-benefits"
          and requested["v1Digest"] == requested["v1Digest"].strip()
          and requested["v2Digest"] is None,
          "independent process reads one buyer-authored v1 rework request")
    check(buyer.request.post(REQUEST, form={**request_form,
          "confirm": "yes"}).status == 409,
          "consumed request challenge cannot be replayed")
    check(another.request.post(REQUEST, form={**request_form,
          "confirm": "yes"}).status == 403,
          "buyer B cannot submit buyer A's request")
    php("buyer_rework_v2_lab.php", "draft-v2")
    draft = rework_state()
    check(draft["v2State"] == "draft" and draft["drafted"] == 1
          and draft["reviewed"] == 0,
          "new editorial v2 exists as draft and is not approved")
    check(not artifact_state()["labFinalAccepted"]
          and not artifact_state()["currentSource"],
          "draft v2 invalidates approval HEAD and v1 final receipt")
    check(buyer.request.get(PREVIEW).status == 403
          and buyer.request.get(FINAL).status == 403,
          "unapproved v2 denies preview and final review")

    php("buyer_rework_v2_lab.php", "approve-v2")
    approved = rework_state()
    check(approved["v2State"] == "approved"
          and approved["reviewed"] == 1 and approved["drafted"] == 1,
          "separate synthetic reviewer records v2 approval after draft")
    check(buyer.request.get(PREVIEW).status == 403
          and not artifact_state()["currentSource"],
          "approved v2 cannot serve stale v1 HTML before Sites render")

    php("buyer_rework_v2_lab.php", "render-v2")
    second = artifact_state()
    check(second["currentSource"] and not second["labFinalAccepted"]
          and second["briefRevision"] == 2
          and second["artifactGeneration"] == 2
          and second["artifactSha256"] != first["artifactSha256"],
          "Sites-produced v2 has new source/digest/generation without inherited acceptance")
    check(buyer.request.post(ACCEPT, form={**old_accept_form,
          "confirm": "yes"}).status == 409,
          "stale v1 consent challenge cannot be applied to v2")

    response = page.goto(PREVIEW)
    v2_bytes = response.body()
    check(response.status == 200
          and hashlib.sha256(v2_bytes).hexdigest() == second["artifactSha256"]
          and v2_bytes != first_bytes,
          "buyer receives distinct real Sites v2 bytes by HTTP")
    check(b'data-site-product="landing-page"' in v2_bytes
          and b'data-site-product="onepage"' not in v2_bytes
          and b'http://' not in v2_bytes and b'https://' not in v2_bytes
          and b'<form' not in v2_bytes,
          "v2 stays Landing-only, form-disabled and without external destination")
    check(another.request.get(PREVIEW).status == 403,
          "same tenant other buyer denied on v2")
    page.goto(FINAL)
    check(page.locator("#brief-revision").inner_text() == "2"
          and page.locator("#artifact-generation").inner_text() == "2"
          and page.locator("#html-digest").inner_text() == second["artifactSha256"],
          "buyer reviews exact v2 revision and HTML digest before new click")
    page.locator("#final-checkbox").check()
    page.locator("#final-confirm").click()
    check("LANDING LAB FINAL ACCEPTED ONLY" in page.locator("body").inner_text(),
          "new explicit final acceptance after adjustment")
    verified = artifact_state()
    check(verified["labFinalAccepted"] and verified["briefRevision"] == 2
          and verified["artifactGeneration"] == 2
          and verified["artifactSha256"] == second["artifactSha256"],
          "separate PHP process and PG18 connection independently re-read exact v2 receipt")
    browser.close()

print(f"LANDING BUYER REWORK SAME ORDER V1-V2 LAB PASS: {total} new checks")
print(f"V1_HTTP_SHA256={first['artifactSha256']}")
print(f"V2_BRIEF_SHA256={approved['v2Digest']}")
print(f"V2_HTTP_SHA256={second['artifactSha256']}")
print("REQUEST=BUYER_LAB_CLICK; EDITORIAL=SEPARATE_FIXTURE_EVENTS; "
      "RENDERER=PINNED_SITES; ACCEPT_V2=EXPLICIT_BROWSER_CLICK; "
      "NATIVE_AUTH=NOT_TESTED; OFFICIAL_APPROVAL=NOT_TESTED; "
      "PSP=NOT_CALLED; FORM=DISABLED; PUBLISH=NO_GO; LEAD=NOT_TESTED")
