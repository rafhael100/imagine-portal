"""Commercial's built offer buttons -> real Laravel/PG synthetic purchase, loopback only.

This is an integration test, not a deployment artifact. Never follow production
hosts or send customer data. All writes use CI's disposable PostgreSQL roles.
"""
from __future__ import annotations

from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
from tempfile import TemporaryDirectory
from urllib.parse import urlsplit
import os
import re
import shutil

from playwright.sync_api import sync_playwright

PORTAL = "http://127.0.0.1:18776/_rehearsal/purchase"
CASES = (
    ("onepage", "onepage"),
    ("landing-pages", "landing-pages"),
    ("gestao-google-ads", "performance"),
    ("gestao-meta-ads", "performance"),
)
assert os.environ.get("GITHUB_ACTIONS") == "true"
assert os.environ.get("PORTAL_PURCHASE_HTTP_CI") == "1"
source = Path(os.environ["IMAGINE_COMERCIAL_DIST"]).resolve(strict=True)
assert (source / "index.html").is_file() and not source.is_symlink()
for path in source.rglob("*"):
    assert not path.is_symlink(), "No symlinks are allowed in the commercial build"


class QuietStatic(SimpleHTTPRequestHandler):
    def log_message(self, *args: object) -> None:
        pass

    def do_POST(self) -> None:
        self.send_error(405, "Static test pages never accept writes")


def prepare_home(original: str) -> str:
    result = original
    hero = re.compile(r'<section\b[^>]*class="[^"]*hero-main[^"]*"[^>]*>[\s\S]*?</section>')
    original_hero = hero.search(original)
    assert original_hero, "Approved commercial hero must exist"
    for slug, offer in CASES:
        pattern = re.compile(
            r'<article\b[^>]*data-quick-hire="' + re.escape(slug)
            + r'"[^>]*>[\s\S]*?</article>'
        )
        seen = [0, 0]

        def rewrite_card(match: re.Match[str]) -> str:
            seen[0] += 1
            card = match.group(0)

            def rewrite_link(link: re.Match[str]) -> str:
                seen[1] += 1
                attrs = link.group(1)
                assert re.search(r'\bhref="[^"]*"', attrs)
                attrs = re.sub(
                    r'\bhref="[^"]*"',
                    f'href="{PORTAL}?offer={offer}"',
                    attrs, count=1
                )
                attrs = re.sub(r'\s+target="[^"]*"', '', attrs)
                attrs = re.sub(r'\s+rel="[^"]*"', '', attrs)
                return "<a" + attrs + ">"

            return re.sub(r'<a\b([^>]*data-quick-hire-action[^>]*)>', rewrite_link, card)

        result = pattern.sub(rewrite_card, result)
        assert seen == [1, 1], f"Expected exactly one card/link for {slug}: {seen}"
    assert hero.search(result).group(0) == original_hero.group(0)
    assert result != original
    return result


with TemporaryDirectory(prefix="imagine-commercial-portal-", dir=os.environ.get("RUNNER_TEMP")) as temp:
    root = Path(temp) / "build"
    shutil.copytree(source, root)
    original = (root / "index.html").read_text(encoding="utf-8")
    (root / "index.html").write_text(prepare_home(original), encoding="utf-8")
    assert (source / "index.html").read_text(encoding="utf-8") == original

    with ThreadingHTTPServer(
        ("127.0.0.1", 0), partial(QuietStatic, directory=str(root))
    ) as server:
        assert server.server_address[0] == "127.0.0.1"
        port = server.server_port
        origin = f"http://127.0.0.1:{port}"
        runner = Thread(target=server.serve_forever, daemon=True)
        runner.start()
        try:
            with sync_playwright() as playwright:
                chrome = shutil.which("google-chrome") or shutil.which("chromium")
                assert chrome, "CI Chromium is required"
                browser = playwright.chromium.launch(
                    executable_path=chrome, headless=True,
                    args=["--no-sandbox", "--disable-background-networking"],
                )
                try:
                    for slug, offer in CASES:
                        page = browser.new_page(viewport={"width": 1280, "height": 900})
                        errors: list[str] = []
                        blocked: list[str] = []
                        page.on("pageerror", lambda err: errors.append(str(err)))

                        def only_loopback(route) -> None:
                            parsed = urlsplit(route.request.url)
                            if (parsed.scheme == "http" and parsed.hostname == "127.0.0.1"
                                and parsed.port in (port, 18776)):
                                route.continue_()
                            elif parsed.scheme == "data":
                                route.continue_()
                            else:
                                blocked.append(parsed.scheme)
                                route.abort()

                        page.route("**/*", only_loopback)
                        response = page.goto(origin + "/", wait_until="domcontentloaded")
                        assert response is not None and response.status == 200
                        button = page.locator(
                            f'article[data-quick-hire="{slug}"] a[data-quick-hire-action]'
                        )
                        assert button.count() == 1
                        assert button.get_attribute("href") == PORTAL + "?offer=" + offer
                        button.click()
                        page.wait_for_url(PORTAL + "?offer=" + offer)
                        selected = page.locator("section.card.selected")
                        assert selected.count() == 1
                        assert selected.locator('input[name="product"]').get_attribute("value") == offer
                        assert page.locator("[data-selected-offer]").get_attribute(
                            "data-selected-offer"
                        ) == offer

                        # Browser-origin controls remain authoritative at the server.
                        assert page.request.get(PORTAL + "?offer=unknown").status == 404
                        assert page.request.get(PORTAL + "?offer=onepage&organization=other").status == 422
                        no_csrf = page.request.post(
                            PORTAL + "/orders",
                            form={"product": offer, "request_key": "TEST-without-csrf-12345"},
                        )
                        assert no_csrf.status == 419
                        selected.get_by_role("button", name="Criar pedido de teste").click()
                        page.get_by_role("heading", name="Seu pedido de teste", exact=True).wait_for()
                        order_url = page.url
                        assert "/orders/TEST-" in order_url

                        for outcome in ("declined", "pending", "approved"):
                            page.get_by_role("checkbox").check()
                            page.locator('select[name="outcome"]').select_option(outcome)
                            page.get_by_role("button", name="Aplicar resultado de teste").click()
                            if outcome != "approved":
                                assert page.get_by_role(
                                    "button", name="Salvar briefing de teste"
                                ).count() == 0

                        for field, value in {
                            "business": "Empresa fictícia de integração",
                            "goal": "<script>alert(1)</script>",
                            "audience": "Público de demonstração",
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
                        fresh = browser.new_page()
                        fresh.goto(order_url)
                        fresh.get_by_role(
                            "heading", name="Briefing salvo no Portal de teste"
                        ).wait_for()
                        fresh.close()
                        assert errors == [], errors
                        assert blocked == [], blocked
                        page.close()
                        print(f"PASS: Comercial {slug} -> Portal {offer} -> persisted fake brief")
                finally:
                    browser.close()
        finally:
            server.shutdown()
            runner.join(timeout=5)

print("PASS: 4 commercial CTAs through Laravel HTTP/CSRF to disposable PostgreSQL; no external requests, payments or customers.")
