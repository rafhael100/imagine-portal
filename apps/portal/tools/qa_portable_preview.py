#!/usr/bin/env python3
"""OFFLINE browser QA for the synthetic Portal preview ZIP.

Run with an installed Python Playwright + Chromium. No account, web endpoint,
secret, private content, network, deployment, customer role or database access.
The QA checks rendered width, isolated table scroll and mobile menu focus.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import sys
import zipfile

WIDTHS = (320, 375, 390, 640, 768, 1024, 1440)
PAGES = (
    "overview.html", "services.html", "preparation.html", "available.html", "contracts.html",
    "modules.html", "journey.html", "requests.html", "help.html",
    "notices.html", "index.html",
)


def audit(archive: Path, browser_executable: str | None = None) -> dict:
    from playwright.sync_api import sync_playwright

    with zipfile.ZipFile(archive) as zf:
        inventory = zf.namelist()
        if len(inventory) != len(PAGES) + 1 or sorted(inventory) != sorted((*PAGES, "manifest.json")):
            raise ValueError("Expected exactly eleven synthetic HTML files and one manifest.")
        manifest = json.loads(zf.read("manifest.json").decode("utf-8"))
        if (manifest.get("schema") != "imagine-portal-visual-only-preview-v1"
            or manifest.get("synthetic_only") is not True
            or manifest.get("logo_git_blob_sha1") != "678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77"
            or any(manifest.get(k) is not False for k in (
                "authorizes_customer_access", "authorizes_payment",
                "authorizes_deployment"))):
            raise ValueError("Manifest must explicitly deny customer access and deployment.")
        records = manifest.get("pages")
        if not isinstance(records, list) or len(records) != len(PAGES):
            raise ValueError("Incomplete manifest page list.")
        pages: dict[str, str] = {}
        for record in records:
            if not isinstance(record, dict) or record.get("name") not in PAGES:
                raise ValueError("Unrecognized manifest entry.")
            name = record["name"]
            if name in pages:
                raise ValueError("Duplicate manifest entry.")
            content = zf.read(name)
            if (record.get("bytes") != len(content)
                or record.get("sha256") != hashlib.sha256(content).hexdigest()
                or len(content) > 1_000_000):
                raise ValueError("Manifest checksum/size mismatch.")
            pages[name] = content.decode("utf-8")
        if set(pages) != set(PAGES):
            raise ValueError("Missing HTML page in manifest.")

    errors: list[dict] = []
    inspected = 0
    scroll_tests = 0
    nav_tests = 0
    offline_nav_checks = 0
    with sync_playwright() as p:
        options = {"headless": True, "args": [
            "--no-sandbox", "--disable-gpu",
            "--disable-dev-shm-usage", "--disable-background-networking",
        ]}
        if browser_executable:
            options["executable_path"] = browser_executable
        browser = p.chromium.launch(**options)
        try:
            page = browser.new_page(viewport={"width": 390, "height": 844})
            for width in WIDTHS:
                page.set_viewport_size({"width": width, "height": 844})
                for name in PAGES:
                    inspected += 1
                    page.set_content(pages[name], wait_until="domcontentloaded", timeout=15000)
                    metrics = page.evaluate(
                        """() => ({
                          viewport: innerWidth,
                          doc: document.documentElement.scrollWidth,
                          body: document.body.scrollWidth,
                          main: document.querySelectorAll('main').length,
                          title: document.querySelectorAll('h1').length,
                          images: document.querySelectorAll('img').length,
                          nav: document.querySelector('.pi-sidebar nav')?.scrollWidth || 0
                        })"""
                    )
                    if metrics["doc"] > width + 1 or metrics["body"] > width + 1:
                        errors.append({"page": name, "width": width,
                                       "reason": "global_horizontal_overflow", **metrics})
                    if metrics["main"] != 1 or metrics["title"] != 1:
                        errors.append({"page": name, "width": width,
                                       "reason": "invalid_page_landmarks"})
                    if metrics["images"] != (0 if name == "index.html" else 1):
                        errors.append({"page": name, "width": width,
                                       "reason": "unexpected_image_count"})
                    if width == 320:
                        # The review-only navigation must remain on one
                        # horizontal line and be reachable by tab focus.
                        offline_nav = page.locator(".pi-offline-nav")
                        if offline_nav.count() != 1:
                            errors.append({"page": name, "width": width,
                                           "reason": "missing_offline_review_navigation"})
                        else:
                            offline_nav_checks += 1
                            offline_nav.evaluate("(e) => { e.scrollLeft = 0; }")
                            offline_nav.locator("a").last.focus()
                            page.wait_for_timeout(50)
                            moved_nav = offline_nav.evaluate("(e) => e.scrollLeft")
                            if moved_nav < 1:
                                errors.append({"page": name, "width": width,
                                               "reason": "offline_navigation_not_keyboard_scrollable"})
                            actual_color = offline_nav.locator("a").first.evaluate(
                                "(e) => getComputedStyle(e).color"
                            )
                            if actual_color != "rgb(153, 75, 0)":
                                errors.append({"page": name, "width": width,
                                               "reason": "offline_navigation_not_imagine_branded"})
                        regions = page.locator(".pi-table-wrap")
                        for index in range(regions.count()):
                            region = regions.nth(index)
                            dimensions = region.evaluate(
                                "(e) => ({client:e.clientWidth, scroll:e.scrollWidth})"
                            )
                            if dimensions["scroll"] <= dimensions["client"]:
                                continue
                            region.focus()
                            page.keyboard.press("ArrowRight")
                            # Chromium scrolls asynchronously; reading in the
                            # same event-loop turn produces a false failure.
                            page.wait_for_timeout(180)
                            moved = region.evaluate("(e) => e.scrollLeft")
                            scroll_tests += 1
                            if moved < 1:
                                errors.append({"page": name, "width": width,
                                               "reason": "table_not_keyboard_scrollable",
                                               "table": index})
                        if name != "index.html":
                            nav = page.locator(".pi-sidebar nav")
                            if nav.count() != 1:
                                errors.append({"page": name, "width": width,
                                               "reason": "missing_sidebar_navigation"})
                            else:
                                nav_tests += 1
                                sidebar_links = nav.locator("a[data-preview-section]")
                                if sidebar_links.count() != 8:
                                    errors.append({"page": name, "width": width,
                                                   "reason": "sidebar_local_links_missing"})
                                else:
                                    # Help/notices have no active client section;
                                    # each of the eight client pages has one.
                                    active_links = nav.locator("a[aria-current='page']")
                                    expected_active = 1 if name in PAGES[:8] else 0
                                    if active_links.count() != expected_active:
                                        errors.append({"page": name, "width": width,
                                                       "reason": "incorrect_sidebar_current_page"})
                                    elif expected_active and active_links.first.get_attribute("href") != name:
                                        errors.append({"page": name, "width": width,
                                                       "reason": "sidebar_current_page_mismatch"})
                                    nav.evaluate("(e) => { e.scrollLeft = 0; }")
                                    sidebar_links.last.focus()
                                    page.wait_for_timeout(75)
                                    if nav.evaluate("(e) => e.scrollLeft") < 1:
                                        errors.append({"page": name, "width": width,
                                                       "reason": "sidebar_last_link_not_keyboard_reachable"})
        finally:
            browser.close()
    return {
        "mode": "synthetic_offline_browser_qa",
        "screens": inspected,
        "widths": list(WIDTHS),
        "pages": len(PAGES),
        "keyboard_table_checks": scroll_tests,
        "mobile_menu_checks": nav_tests,
        "offline_navigation_checks": offline_nav_checks,
        "manifest_integrity_verified": True,
        "passed": not errors,
        "issues": errors,
        "authorizes_production": False,
    }


if __name__ == "__main__":
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("zip", type=Path, help="GitHub Actions synthetic preview artifact ZIP")
    cli.add_argument("--chromium", default=None, help="optional local Chromium executable path")
    args = cli.parse_args()
    try:
        result = audit(args.zip, args.chromium)
    except Exception as exc:
        print(json.dumps({"passed": False, "error": type(exc).__name__,
                          "message": str(exc)[:180], "authorizes_production": False}))
        sys.exit(2)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    sys.exit(0 if result["passed"] else 1)
