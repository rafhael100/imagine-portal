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
import tempfile
import zipfile

WIDTHS = (320, 375, 390, 640, 768, 1024, 1440)
PAGES = (
    "overview.html", "services.html", "preparation.html", "available.html", "contracts.html",
    "modules.html", "journey.html", "requests.html", "help.html",
    "notices.html", "index.html",
)


def audit(archive: Path, browser_executable: str | None = None, screenshots_dir: Path | None = None) -> dict:
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
    guided_clicks = 0
    guided_pages = 0
    mobile_header_checks = 0
    max_mobile_header_height = 0.0
    visual_evidence: list[dict] = []
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
            page.route("http://**", lambda route: route.abort())
            page.route("https://**", lambda route: route.abort())
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
                          nav: document.querySelector('.pi-sidebar nav')?.scrollWidth || 0,
                          sidebar_height: document.querySelector('.pi-sidebar')?.getBoundingClientRect().height || 0
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
                    if width == 390 and name != "index.html":
                        mobile_header_checks += 1
                        max_mobile_header_height = max(
                            max_mobile_header_height, metrics["sidebar_height"]
                        )
                        if metrics["sidebar_height"] > 260:
                            errors.append({
                                "page": name, "width": width,
                                "reason": "mobile_header_consumes_too_much_vertical_space",
                                "sidebar_height": metrics["sidebar_height"],
                            })
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
            # Unlike DOM-only checks, this follows real file:// clicks in
            # Chromium through every approved step, without an HTTP server.
            sequence = (
                "overview.html", "available.html", "requests.html",
                "preparation.html", "journey.html", "services.html",
            )
            if not errors:
                with tempfile.TemporaryDirectory(prefix="imagine-guided-offline-") as tmp:
                    root = Path(tmp)
                    for filename, content in pages.items():
                        (root / filename).write_text(content, encoding="utf-8")
                    page.set_viewport_size({"width": 390, "height": 844})
                    page.goto((root / "index.html").resolve().as_uri(),
                              wait_until="domcontentloaded", timeout=15000)
                    if page.locator("a[data-guide-start]").count() != 1:
                        errors.append({"reason": "missing_guided_start"})
                    else:
                        page.locator("a[data-guide-start]").click(timeout=15000)
                        guided_clicks += 1
                        for position, filename in enumerate(sequence):
                            expected = (root / filename).resolve().as_uri()
                            if page.url != expected:
                                errors.append({"reason": "guided_click_wrong_target",
                                               "expected": filename, "url": page.url})
                                break
                            active = page.locator('nav[data-guided-flow="portal"]')
                            if (active.count() != 1 or
                                active.get_attribute("data-step-number") != str(position + 1) or
                                active.locator('[aria-current="step"]').count() != 1 or
                                page.locator("main").count() != 1):
                                errors.append({"reason": "guided_step_markers_wrong",
                                               "step": filename})
                                break
                            guided_pages += 1
                            if position < len(sequence) - 1:
                                if active.locator("a[data-guide-next]").count() != 1:
                                    errors.append({"reason": "guided_next_missing",
                                                   "step": filename})
                                    break
                                active.locator("a[data-guide-next]").click(timeout=15000)
                                guided_clicks += 1
                            else:
                                if active.locator("a[data-guide-end]").count() != 1:
                                    errors.append({"reason": "guided_return_missing"})
                                    break
                                active.locator("a[data-guide-end]").click(timeout=15000)
                                guided_clicks += 1
                                if (page.url != (root / "index.html").resolve().as_uri()
                                    or page.locator("a[data-guide-start]").count() != 1):
                                    errors.append({"reason": "guided_return_failed"})
            if screenshots_dir is not None and not errors:
                if screenshots_dir.exists() or screenshots_dir.is_symlink():
                    raise ValueError("Visual review requires a new output directory.")
                screenshots_dir.mkdir(parents=True, mode=0o700)
                for name in ("overview.html", "services.html", "preparation.html"):
                    for label, width, height in (("mobile", 390, 844), ("desktop", 1440, 900)):
                        page.set_viewport_size({"width": width, "height": height})
                        page.set_content(pages[name], wait_until="domcontentloaded", timeout=15000)
                        if page.locator("main").count() != 1 or page.locator("h1").count() != 1:
                            raise ValueError("Invalid landmarks in screenshot view.")
                        filename = name.removesuffix(".html") + "-" + label + ".png"
                        payload = page.screenshot(
                            path=str(screenshots_dir / filename),
                            full_page=True, animations="disabled", timeout=15000,
                        )
                        if not payload.startswith(b"\x89PNG\r\n\x1a\n"):
                            raise ValueError("Screenshot must be PNG.")
                        visual_evidence.append({
                            "name": filename, "page": name, "viewport": width,
                            "bytes": len(payload), "sha256": hashlib.sha256(payload).hexdigest(),
                        })
                report = {
                    "schema": "imagine.portal.synthetic-offline-visual-review.v1",
                    "synthetic_only": True, "safe_for_production": False,
                    "customer_data_present": False,
                    "source_manifest_schema": manifest["schema"],
                    "screenshots": visual_evidence,
                }
                (screenshots_dir / "screenshots-manifest.json").write_text(
                    json.dumps(report, ensure_ascii=False, indent=2) + "\n",
                    encoding="utf-8",
                )
        finally:
            browser.close()
    return {
        "mode": "synthetic_offline_browser_qa",
        "screens": inspected,
        "widths": list(WIDTHS),
        "pages": len(PAGES),
        "keyboard_table_checks": scroll_tests,
        "mobile_menu_checks": nav_tests,
        "mobile_header_checks": mobile_header_checks,
        "max_mobile_header_height": round(max_mobile_header_height, 2),
        "mobile_header_limit_px": 260,
        "offline_navigation_checks": offline_nav_checks,
        "guided_flow_pages": guided_pages,
        "guided_flow_clicks": guided_clicks,
        "manifest_integrity_verified": True,
        "passed": not errors,
        "issues": errors,
        "visual_evidence_count": len(visual_evidence),
        "visual_evidence": visual_evidence,
        "authorizes_production": False,
    }


if __name__ == "__main__":
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("zip", type=Path, help="GitHub Actions synthetic preview artifact ZIP")
    cli.add_argument("--chromium", default=None, help="optional local Chromium executable path")
    cli.add_argument("--screenshots-dir", type=Path, default=None,
                     help="new folder for six synthetic desktop/mobile review screenshots")
    args = cli.parse_args()
    try:
        result = audit(args.zip, args.chromium, args.screenshots_dir)
    except Exception as exc:
        print(json.dumps({"passed": False, "error": type(exc).__name__,
                          "message": str(exc)[:180], "authorizes_production": False}))
        sys.exit(2)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    sys.exit(0 if result["passed"] else 1)
