"""Chromium: compiled Astro offer -> actual Laravel CSRF -> PostgreSQL18 durable briefing.

TWO local servers on disposable GitHub-hosted CI ONLY:
  Comercial 127.0.0.1:18777 (safe rewritten static build)
  Portal    127.0.0.1:18776 (CI-only role-bound synthetic Laravel)
No PSP, real customer IAM, Cloudflare, VPS, payment or deployment.
"""
from __future__ import annotations
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import os
from pathlib import Path
from hashlib import sha256
import json
import shutil
from threading import Thread
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

COMERCIAL_PORT = 18777
PORTAL = "http://127.0.0.1:18776/_rehearsal/purchase"
COMERCIAL = f"http://127.0.0.1:{COMERCIAL_PORT}"
OFFERS = (
    ("onepage", "onepage"),
    ("landing-pages", "landing-pages"),
    ("gestao-google-ads", "performance"),
    ("gestao-meta-ads", "performance"),
)
root = Path(os.environ["COMERCIAL_P0_HANDOFF_ROOT"]).resolve(strict=True)
assert root.is_dir() and not root.is_symlink()
assert (root / "index.html").is_file()

review_dir_env = os.environ.get("P0_REVIEW_SCREENSHOT_DIR")
review_dir = Path(review_dir_env) if review_dir_env else None
if review_dir is not None:
    assert review_dir.is_absolute() and not review_dir.exists()
    review_dir.mkdir(parents=True, mode=0o700)
review_expected = (
    "01-comercial-desktop.png",
    "02-comercial-mobile.png",
    "03-portal-choice-desktop.png",
    "04-portal-choice-mobile.png",
    "05-payment-blocked.png",
    "06-briefing-saved.png",
)

def capture_review(page, filename: str, *, mask_order: bool = False) -> None:
    if review_dir is None:
        return
    assert filename in review_expected
    options = {"path": str(review_dir / filename), "animations": "disabled"}
    if mask_order:
        options["mask"] = [page.locator("code")]
    page.screenshot(**options)



class NoWriteLocalSite(SimpleHTTPRequestHandler):
    def log_message(self, *args: object) -> None:
        return

    def do_POST(self) -> None:
        self.send_response(405)
        self.send_header("Content-Length", "0")
        self.end_headers()


with ThreadingHTTPServer(
    ("127.0.0.1", COMERCIAL_PORT), partial(NoWriteLocalSite, directory=str(root))
) as server:
    assert server.server_address[0] == "127.0.0.1"
    worker = Thread(target=server.serve_forever, daemon=True)
    worker.start()
    try:
        with sync_playwright() as p:
            chrome = shutil.which("google-chrome") or shutil.which("chromium")
            assert chrome, "GitHub-hosted CI Chromium executable required"
            browser = p.chromium.launch(
                executable_path=chrome, headless=True,
                args=["--no-sandbox", "--disable-background-networking"],
            )
            try:
                refused_resources: list[str] = []
                context = browser.new_context(viewport={"width": 1280, "height": 900})
                try:
                    def only_two_loopback_servers(route) -> None:
                        target = urlsplit(route.request.url)
                        allowed = (
                            target.scheme == "http"
                            and target.hostname == "127.0.0.1"
                            and target.port in {18776, 18777}
                        )
                        if allowed or target.scheme == "data":
                            route.continue_()
                        else:
                            # An unexpected external resource fails the CI lab.
                            refused_resources.append(target.scheme)
                            route.abort()

                    context.route("**/*", only_two_loopback_servers)
                    for slug, product in OFFERS:
                        page = context.new_page()
                        errors: list[str] = []
                        page.on("pageerror", lambda err: errors.append(type(err).__name__))
                        home = page.goto(COMERCIAL, wait_until="domcontentloaded", timeout=15000)
                        assert home and home.status == 200
                        if slug == "onepage" and review_dir is not None:
                            capture_review(page, "01-comercial-desktop.png")
                            page.set_viewport_size({"width": 390, "height": 844})
                            assert page.evaluate("document.documentElement.scrollWidth <= innerWidth + 1")
                            capture_review(page, "02-comercial-mobile.png")
                            page.set_viewport_size({"width": 1280, "height": 900})
                        link = page.locator(
                            f'article[data-quick-hire="{slug}"] a[data-quick-hire-action]'
                        )
                        assert link.count() == 1
                        assert link.get_attribute("href") == PORTAL + "?offer=" + product
                        link.click()
                        page.wait_for_url(PORTAL + "?offer=" + product, timeout=10000)
                        assert page.get_by_role(
                            "heading", name="Escolha o serviço para testar a contratação"
                        ).count() == 1
                        assert page.locator('p[data-comercial-handoff="confirmed"]').count() == 1
                        selected = page.locator(
                            f'section[data-handoff-offer="{product}"][data-handoff-selected="true"]'
                        )
                        assert selected.count() == 1
                        assert page.locator('section[data-handoff-selected="true"]').count() == 1
                        assert page.get_by_role("button", name="Criar pedido de teste").count() == 3
                        if slug == "onepage" and review_dir is not None:
                            capture_review(page, "03-portal-choice-desktop.png")
                            page.set_viewport_size({"width": 390, "height": 844})
                            assert page.evaluate("document.documentElement.scrollWidth <= innerWidth + 1")
                            capture_review(page, "04-portal-choice-mobile.png")
                            page.set_viewport_size({"width": 1280, "height": 900})
                        # CSRF must remain enforced even with a valid offer hint.
                        bad = page.request.post(
                            PORTAL + "/orders",
                            form={"product": product, "request_key": "TEST-without-csrf-12345678"},
                        )
                        assert bad.status == 419
                        selected.get_by_role("button", name="Criar pedido de teste").click()
                        page.get_by_role("heading", name="Seu pedido de teste", exact=True).wait_for()
                        order_url = page.url
                        assert order_url.startswith(PORTAL + "/orders/TEST-")
                        # Pending and declined MUST NOT expose briefing.
                        for state in ("declined", "pending"):
                            page.get_by_role("checkbox").check()
                            page.locator("select[name=outcome]").select_option(state)
                            page.get_by_role("button", name="Aplicar resultado de teste").click()
                            assert page.get_by_role(
                                "button", name="Salvar briefing de teste"
                            ).count() == 0
                            if slug == "onepage" and state == "pending":
                                capture_review(page, "05-payment-blocked.png", mask_order=True)
                        page.get_by_role("checkbox").check()
                        page.locator("select[name=outcome]").select_option("approved")
                        page.get_by_role("button", name="Aplicar resultado de teste").click()
                        for field, value in {
                            "business": "Empresa Fictícia Portal",
                            "goal": "<script>alert(1)</script>",
                            "audience": "Público de laboratório",
                            "materials": "Materiais fictícios",
                        }.items():
                            page.locator(f'textarea[name="{field}"]').fill(value)
                        page.get_by_role("button", name="Salvar briefing de teste").click()
                        page.get_by_role(
                            "heading", name="Briefing salvo no Portal de teste"
                        ).wait_for()
                        if slug == "onepage":
                            capture_review(page, "06-briefing-saved.png", mask_order=True)
                        page.reload()
                        assert page.get_by_text("<script>alert(1)</script>", exact=True).count() == 1
                        assert page.locator("script").count() == 0
                        # New browser context proves PostgreSQL durability; the
                        # synthetic CI scope is fixed, this is NOT an IAM test.
                        fresh = browser.new_page()
                        fresh.goto(order_url)
                        fresh.get_by_role(
                            "heading", name="Briefing salvo no Portal de teste"
                        ).wait_for()
                        fresh.close()
                        assert not errors, errors
                        page.close()

                    checks = context.new_page()
                    for invalid in ("unknown", "%2e%2e%2fadmin", "onepage%3Cscript%3E", ""):
                        response = checks.goto(PORTAL + "?offer=" + invalid)
                        assert response and response.status == 422
                    checks.close()
                    assert not refused_resources, refused_resources
                finally:
                    context.close()
            finally:
                browser.close()
    finally:
        server.shutdown()
        worker.join(timeout=5)
if review_dir is not None:
    images = sorted(review_dir.glob("*.png"))
    assert [p.name for p in images] == list(review_expected)
    records = []
    for image in images:
        content = image.read_bytes()
        assert len(content) > 2000 and len(content) < 2_500_000
        assert content.startswith(b"\x89PNG\r\n\x1a\n")
        records.append({"filename": image.name, "bytes": len(content), "sha256": sha256(content).hexdigest()})
    manifest = {
        "schema": "imagine.p0.visual-review.v1",
        "source": "synthetic-astro-laravel-pg18-chromium",
        "screenshots": records,
        "all_data_fictitious": True,
        "customer_sessions_used": False,
        "real_purchases_executed": False,
        "external_navigation_authorized": False,
        "publishable": False,
        "release_gate": "NO_GO",
    }
    (review_dir / "manifest.json").write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    print("PASS: six review screenshots + SHA256 manifest produced; NO-GO for real service.")
print("PASS: 4 Astro CTA -> actual Laravel HTTP + CSRF + PG18 durable fake payment/briefing.")
print("NO-GO: no customer login, authorization, real payment, public route or VPS.")
