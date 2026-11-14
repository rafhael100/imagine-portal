"""F414: real Chromium interaction against loopback-only static synthetic UI.
No credentials, trackers, advertising API, CRM, PSP, PII or production server.
"""
from __future__ import annotations

import functools
import http.server
import os
import pathlib
import threading
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent
SCREENSHOTS = pathlib.Path(os.environ.get("PORTAL_F414_SCREENSHOTS", "/tmp/portal-f414-shots"))


class QuietStatic(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def assert_contains(element, expected):
    content = element.inner_text()
    assert expected in content, f"Expected {expected!r} in UI text {content!r}"


def run() -> None:
    server = http.server.ThreadingHTTPServer(
        ("127.0.0.1", 0), functools.partial(QuietStatic, directory=str(ROOT))
    )
    port = server.server_port
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    SCREENSHOTS.mkdir(parents=True, exist_ok=True)
    errors: list[str] = []
    external_requests: list[str] = []
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            desktop = browser.new_context(viewport={"width": 1280, "height": 900})
            def local_only(route):
                u = urlsplit(route.request.url)
                if u.scheme not in ("http", "https") or u.hostname != "127.0.0.1" or u.port != port:
                    external_requests.append(u.hostname or "unknown")
                    route.abort()
                else:
                    route.continue_()
            desktop.route("**/*", local_only)
            page = desktop.new_page()
            page.on("pageerror", lambda error: errors.append(str(error)))
            response = page.goto(f"http://127.0.0.1:{port}/index.html", wait_until="networkidle")
            assert response is not None and response.status == 200
            assert "Escolha os canais" in page.locator("h1").inner_text()
            assert page.locator("#meta").is_checked()
            assert not page.locator("#google").is_checked()
            assert page.locator("#chatgpt").is_disabled()
            assert_contains(page.locator("#activation"), "490,00")
            assert_contains(page.locator("#media"), "1.000,00")
            assert_contains(page.locator("#management"), "200,00")

            page.locator("#google").check()
            assert_contains(page.locator("#activation"), "980,00")
            assert_contains(page.locator("#media"), "2.500,00")
            assert_contains(page.locator("#management"), "500,00")
            page.screenshot(path=str(SCREENSHOTS / "desktop-two-channels-synthetic.png"), full_page=True)

            page.locator("#meta-budget").fill("700")
            assert_contains(page.locator("#media"), "2.200,00")
            assert_contains(page.locator("#management"), "470,00")
            assert_contains(page.locator("#management"), "470,00")  # Meta 170 + Google 300
            page.locator("#meta-budget").fill("600")
            assert_contains(page.locator("#error"), "abaixo do mínimo")
            assert "—" in page.locator("#activation").inner_text()
            assert "—" in page.locator("#management").inner_text()

            page.locator("#meta-budget").fill("1000")
            page.locator("#meta").uncheck()
            page.locator("#google").uncheck()
            assert_contains(page.locator("#error"), "Selecione pelo menos um canal")
            assert "—" in page.locator("#activation").inner_text()
            assert not page.locator("form").count()
            assert not page.locator("iframe").count()

            mobile = browser.new_context(viewport={"width": 375, "height": 812},
                device_scale_factor=1)
            mobile.route("**/*", local_only)
            mobile_page = mobile.new_page()
            mobile_page.on("pageerror", lambda error: errors.append(str(error)))
            response = mobile_page.goto(f"http://127.0.0.1:{port}/index.html",
                wait_until="networkidle")
            assert response is not None and response.status == 200
            mobile_page.screenshot(
                path=str(SCREENSHOTS / "mobile-single-channel-synthetic.png"), full_page=True)
            width = mobile_page.evaluate("({body: document.body.scrollWidth, viewport: innerWidth})")
            assert width["body"] <= width["viewport"], f"Mobile overflow: {width}"
            browser.close()
        assert not external_requests, f"Disallowed external requests: {external_requests}"
        assert not errors, f"JavaScript exceptions: {errors}"
        print("PASS F414: Chromium desktop/mobile, totals, validation, no external calls or checkout.")
    finally:
        server.shutdown()
        thread.join(timeout=5)
        server.server_close()


if __name__ == "__main__":
    run()
