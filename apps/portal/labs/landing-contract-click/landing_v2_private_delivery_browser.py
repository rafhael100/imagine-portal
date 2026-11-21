#!/usr/bin/env python3
"""R29: buyer-only localhost delivery receipt after independently reconciled release.
Synthetic PHP session, PG18 fixture, R28 provider. Not native IAM or publication.
"""
import hashlib
import os
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent
FILE = ROOT / "landing_v2_private_delivery_receipt_lab.php"
LOGIN = "http://127.0.0.1:8765/lab/login"
DELIVERY = "http://127.0.0.1:8792/lab/delivery"
EXPECTED = "12203ec511d532bb6a84cabb11b3c458bd2d669fe1f316b4bdb2b1f7083d1314"
assert os.getenv("GITHUB_ACTIONS") == "true"
assert os.getenv("APP_ENV") == "testing"
assert os.getenv("LANDING_R29_DELIVERY_LAB") == "1"
checks = 0

def check(value, message):
    global checks
    if not value:
        raise AssertionError(message)
    checks += 1

def cli(mode):
    result = subprocess.run(["php", str(FILE), mode],
                            capture_output=True, text=True, check=True)
    return result.stdout.strip()

def login(page, actor):
    page.goto(LOGIN)
    page.locator("#fixture").select_option(actor)
    page.locator("#login").click()

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, args=["--no-sandbox"])
    anon = browser.new_context()
    check(anon.request.get(DELIVERY).status == 403, "anonymous no delivery")
    foreign = browser.new_context()
    login(foreign.new_page(), "buyer-foreign")
    check(foreign.request.get(DELIVERY).status == 403, "foreign tenant no delivery")
    other = browser.new_context()
    login(other.new_page(), "buyer-B")
    check(other.request.get(DELIVERY).status == 403, "same tenant other buyer no delivery")

    buyer = browser.new_context()
    page = buyer.new_page()
    login(page, "buyer-A")
    response = page.goto(DELIVERY)
    check(response is not None and response.status == 200, "buyer sees local receipt only after R28 state readback")
    # R30: assert actual R29 buyer receipt remains legible, keyboard-operable
    # and private on narrow screens, without creating new mocks or accounts.
    check(page.locator("main").count() == 1 and page.locator("h1").count() == 1,
          "receipt has a semantic main landmark and a single heading")
    check(response.headers.get("cache-control", "").startswith("private, no-store"),
          "buyer receipt explicitly opts out of caching")
    check("default-src 'none'" in response.headers.get("content-security-policy", ""),
          "script and external resources remain denied by CSP")
    check(response.headers.get("x-robots-tag") == "noindex, nofollow",
          "buyer receipt is not indexable")
    check(page.locator('meta[name="viewport"]').get_attribute("content")
          == "width=device-width,initial-scale=1", "mobile viewport is explicit")
    check(page.locator("#download").is_visible(), "receipt's only action is visible")
    page.keyboard.press("Tab")
    check(page.evaluate("document.activeElement.id") == "download",
          "private receipt link is reachable by keyboard")
    check(page.eval_on_selector_all("a[href]", """elements => elements.length === 1 &&
          elements.every(el => el.getAttribute('href').startsWith('/lab/delivery/html?ref='))"""),
          "no external navigation link in receipt")
    for width in (320, 360, 768):
        page.set_viewport_size({"width": width, "height": 640})
        check(page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"),
              f"receipt has no horizontal overflow at {width}px")
        check(page.locator("#download").is_visible(),
              f"private link remains visible at {width}px")
    check(page.locator("#order").inner_text() == "landing-order-001", "exact same order")
    check(page.locator("#release").inner_text() == "landing-v2-generation-2-ci", "exact release")
    check(page.locator("#generation").inner_text() == "2", "exact generation")
    digest = page.locator("#digest").inner_text()
    check(digest == EXPECTED, "receipt tied to exact accepted SHA")
    href = page.locator("#download").get_attribute("href")
    check(href is not None and href.startswith("/lab/delivery/html?ref=")
          and len(href.split("ref=")[1]) == 32, "private link is local and receipt scoped")
    private_url = "http://127.0.0.1:8792" + href
    check(anon.request.get(private_url).status == 403, "link alone grants no anonymous access")
    check(other.request.get(private_url).status == 403, "link grants no access to buyer B of same tenant")
    check(foreign.request.get(private_url).status == 403, "link grants no access cross tenant")
    rendered = buyer.request.get(private_url)
    check(rendered.status == 200, "correct buyer sees HTML served from mock provider")
    data = rendered.body()
    check(hashlib.sha256(data).hexdigest() == digest, "link body bytes equal independent release SHA")
    check(b'data-site-product="landing-page"' in data
          and b'data-site-product="onepage"' not in data, "Landing only")
    check(b"<form" not in data.lower() and b"<script" not in data.lower(),
          "form and scripts remain off")
    check(b"http://" not in data.lower() and b"https://" not in data.lower(),
          "no external destination in delivered HTML")
    check(buyer.request.post(private_url).status == 403, "POST disabled on delivery")
    check(buyer.request.get(DELIVERY + "?order=landing-order-foreign").status == 403,
          "arbitrary order reference cannot be injected")

    check("R29_TAMPER_LOCAL_MOCK_ONLY" in cli("tamper"), "tamper only disposable provider")
    check(buyer.request.get(DELIVERY).status == 403
          and buyer.request.get(private_url).status == 403,
          "stale release bytes deny receipt and private link")
    check("R29_RESTORE_LOCAL_MOCK_ONLY" in cli("restore"), "restore does not mint new receipt")
    restored = buyer.request.get(private_url)
    check(restored.status == 200 and hashlib.sha256(restored.body()).hexdigest() == EXPECTED,
          "independent bytes reverified after isolated restoration")
    browser.close()

print(f"R29_PRIVATE_BUYER_DELIVERY_PASS: {checks} new checks")
print(f"R29_HTTP_SHA256={EXPECTED}")
print("SESSION=LAB_ONLY; RECEIPT=UNSIGNED_CI; FORM=OFF; URL=LOOPBACK_ONLY; PUBLISH=NO_GO")
