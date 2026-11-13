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
print("PASS: 4 Astro CTA -> actual Laravel HTTP + CSRF + PG18 durable fake payment/briefing.")
print("NO-GO: no customer login, authorization, real payment, public route or VPS.")
