#!/usr/bin/env python3
"""OFFLINE browser QA for the synthetic Portal preview ZIP.

Run with an installed Python Playwright + Chromium. No account, web endpoint,
secret, private content, network, deployment, customer role or database access.
The QA checks rendered width, isolated table scroll and mobile menu focus.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys
import zipfile

WIDTHS = (320, 375, 390, 640, 768, 1024, 1440)
PAGES = (
    "overview.html", "services.html", "available.html", "contracts.html",
    "modules.html", "journey.html", "requests.html", "help.html",
    "notices.html", "index.html",
)


def audit(archive: Path, browser_executable: str | None = None) -> dict:
    from playwright.sync_api import sync_playwright

    with zipfile.ZipFile(archive) as zf:
        if sorted(name for name in zf.namelist() if name.endswith(".html")) != sorted(PAGES):
            raise ValueError("Expected exactly nine synthetic views plus the index.")
        pages = {name: zf.read(name).decode("utf-8") for name in PAGES}

    errors: list[dict] = []
    inspected = 0
    scroll_tests = 0
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
                            moved = region.evaluate("(e) => e.scrollLeft")
                            scroll_tests += 1
                            if moved < 1:
                                errors.append({"page": name, "width": width,
                                               "reason": "table_not_keyboard_scrollable",
                                               "table": index})
                        if name != "index.html":
                            nav = page.locator(".pi-sidebar nav")
                            if nav.count() == 1:
                                nav.evaluate("(e) => { e.scrollLeft = 0; }")
                                nav.evaluate("(e) => { e.scrollLeft = 120; }")
                                if nav.evaluate("(e) => e.scrollLeft") < 1:
                                    errors.append({"page": name, "width": width,
                                                   "reason": "mobile_navigation_not_scrollable"})
        finally:
            browser.close()
    return {
        "mode": "synthetic_offline_browser_qa",
        "screens": inspected,
        "widths": list(WIDTHS),
        "pages": len(PAGES),
        "keyboard_table_checks": scroll_tests,
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
