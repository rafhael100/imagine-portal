"""Built Imagine Comercial CTAs -> private Portal signup, order, fake briefing.

Real Chromium + Laravel + PG18, but all identities and payment states are synthetic.
Run only inside isolated GitHub Actions, after disposable Portal Docker CI is live.
The Commercial build is pinned by SHA and only its TEMP copy is rewritten to localhost.
"""
from __future__ import annotations

from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from tempfile import TemporaryDirectory
from threading import Thread
from urllib.parse import urlsplit
import json
import os
import re
from hashlib import sha256
import shutil
import uuid

from playwright.sync_api import sync_playwright

assert os.environ.get("GITHUB_ACTIONS") == "true"
assert os.environ.get("PORTAL_CUSTOMER_COMMERCIAL_E2E") == "1"
root = Path(os.environ["IMAGINE_COMERCIAL_CUSTOMER_PREVIEW_ROOT"]).resolve(strict=True)
base = root / "index.html"
assert base.is_file() and not base.is_symlink()
manifest = json.loads((root / "private-portal-handoff.json").read_text(encoding="utf-8"))
assert manifest["publicReleaseAuthorized"] is False
assert manifest["paymentEnabled"] is False
assert manifest["unservedCopyOnly"] is True
assert manifest["buttons"] == 4

review_path_raw = os.environ.get("IMAGINE_CUSTOMER_VISUAL_REVIEW")
review_path = Path(review_path_raw).resolve() if review_path_raw else None
shots = {
    "01-comercial-desktop.png", "02-comercial-mobile.png",
    "03-portal-login.png", "04-customer-offer.png",
    "05-customer-order-pending.png", "06-customer-briefing-durable.png",
    "07-customer-my-services.png",
}
if review_path is not None:
    assert review_path.is_absolute() and not review_path.exists()
    review_path.mkdir(parents=True,mode=0o700)

def capture(page,filename: str) -> None:
    if review_path is None:
        return
    assert filename in shots
    options = {"path": str(review_path / filename), "animations": "disabled"}
    # Mask synthetic individual identifiers; the screenshots are UI evidence,
    # never an export of customer/order identities or credentials.
    options["mask"] = [
        page.locator('input[name="username"]'),
        page.locator('input[name="password"]'),
        page.locator('input[name="password_confirmation"]'),
        page.locator('code'),
        page.locator('td[data-customer-order-id]'),
    ]
    page.screenshot(**options)


remote = "https://portal-dev.imaginecomunica.com.br/_preview/portal/shop?product="
local = "http://127.0.0.1:18083/_preview/portal/shop?product="
login = "http://127.0.0.1:18083/_preview/portal"
cases = (
    ("onepage", "onepage"),
    ("landing-pages", "landing-pages"),
    ("gestao-google-ads", "performance"),
    ("gestao-meta-ads", "performance"),
)

def rewrite_static(html: str) -> str:
    source = html
    for slug, offer in cases:
        pattern = re.compile(
            r'<article\b[^>]*data-quick-hire="' + re.escape(slug)
            + r'"[^>]*>[\s\S]*?</article>'
        )
        count = [0, 0]
        def change_card(m: re.Match[str]) -> str:
            count[0] += 1
            card = m.group(0)
            def change_cta(a: re.Match[str]) -> str:
                count[1] += 1
                attrs = a.group(1)
                assert ('href="' + remote + offer + '"') in attrs, (
                    "Commercial CTA must use the exact SHA-pinned private Portal URL", slug
                )
                return "<a" + attrs.replace(
                    'href="' + remote + offer + '"',
                    'href="' + local + offer + '"'
                ) + ">"
            return re.sub(r'<a\b([^>]*data-quick-hire-action[^>]*)>', change_cta, card)
        html = pattern.sub(change_card, html)
        assert count == [1, 1], ("Card/action mismatch", slug, count)
    assert source != html
    return html


class StaticOnly(SimpleHTTPRequestHandler):
    def log_message(self, *_args: object) -> None:
        pass

    def do_POST(self) -> None:
        self.send_error(405, "The static commercial copy cannot accept purchases")


with TemporaryDirectory(prefix="imagine-commercial-portal-customer-", dir=os.environ["RUNNER_TEMP"]) as temp:
    tree = Path(temp) / "commercial"
    shutil.copytree(root, tree, symlinks=False)
    original = base.read_text(encoding="utf-8")
    (tree / "index.html").write_text(rewrite_static(original), encoding="utf-8")
    assert base.read_text(encoding="utf-8") == original, "Pinned Comercial candidate changed"

    with ThreadingHTTPServer(
        ("127.0.0.1", 0), partial(StaticOnly, directory=str(tree))
    ) as server:
        port = server.server_port
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with sync_playwright() as pw:
                chrome = shutil.which("google-chrome") or shutil.which("chromium")
                assert chrome, "GitHub CI needs Chromium"
                browser = pw.chromium.launch(
                    executable_path=chrome,
                    headless=True,
                    args=["--no-sandbox", "--disable-background-networking"],
                )
                try:
                    seen_orders: set[str] = set()
                    for slug, offer in cases:
                        context = browser.new_context(
                            viewport={"width": 1280, "height": 900},
                            reduced_motion="reduce",
                        )
                        page = context.new_page()
                        errors: list[str] = []
                        blocked: list[str] = []
                        page.on("pageerror", lambda exc: errors.append(str(exc)))

                        def only_loopback(route) -> None:
                            target = urlsplit(route.request.url)
                            if (target.scheme == "http"
                                and target.hostname == "127.0.0.1"
                                and target.port in (port, 18083)):
                                route.continue_()
                            elif target.scheme == "data":
                                route.continue_()
                            else:
                                blocked.append(target.scheme)
                                route.abort()
                        page.route("**/*", only_loopback)
                        landing = page.goto(
                            f"http://127.0.0.1:{port}/", wait_until="domcontentloaded"
                        )
                        assert landing is not None and landing.status == 200
                        if slug == "onepage" and review_path is not None:
                            capture(page, "01-comercial-desktop.png")
                            page.set_viewport_size({"width":390,"height":844})
                            assert page.evaluate("document.documentElement.scrollWidth <= innerWidth + 1")
                            capture(page, "02-comercial-mobile.png")
                            page.set_viewport_size({"width":1280,"height":900})
                        link = page.locator(
                            f'article[data-quick-hire="{slug}"] a[data-quick-hire-action]'
                        )
                        assert link.count() == 1
                        assert link.get_attribute("href") == local + offer
                        link.click()
                        page.wait_for_url(login)
                        assert page.get_by_role(
                            "heading", name="Bem-vindo ao seu Portal"
                        ).count() == 1, ("Real Portal guest login not reached", slug)
                        if slug == "onepage":
                            capture(page, "03-portal-login.png")
                        # The guest is not authenticated; offer intent stays
                        # in its server-side synthetic session through signup.
                        # End-to-end ownership and PG18 persistence for EACH
                        # distinct CTA. Google and Meta share the Performance
                        # SKU but must retain separate customer/order records.
                        page.get_by_role(
                            "link", name="Criar minha conta de teste"
                        ).click()
                        username = "demo-comercial-" + uuid.uuid4().hex[:12]
                        for field, value in (
                            ("name", "Pessoa inteiramente fictícia"),
                            ("company", "Empresa fictícia Comercial"),
                            ("username", username),
                            ("password", "ci-comercial-portal-only-2026"),
                            ("password_confirmation", "ci-comercial-portal-only-2026"),
                        ):
                            page.locator(f'[name="{field}"]').fill(value)
                        page.get_by_role(
                            "button", name="Criar conta e continuar"
                        ).click()
                        page.wait_for_url(local + offer)
                        assert page.locator(
                            'form[action$="/_preview/portal/shop/orders"]'
                        ).count() == 1
                        assert page.locator('input[name="product"]').get_attribute(
                            "value"
                        ) == offer
                        if slug == "onepage":
                            capture(page, "04-customer-offer.png")
                        page.get_by_role(
                            "button", name="Criar pedido de teste"
                        ).click()
                        page.get_by_role(
                            "heading", name="Seu pedido de teste", exact=True
                        ).wait_for()
                        order_url = page.url
                        assert "/shop/orders/TEST-" in order_url
                        assert page.get_by_text("Pendente", exact=True).count() >= 1
                        if slug == "onepage":
                            capture(page, "05-customer-order-pending.png")
                        page.get_by_role("checkbox").check()
                        page.locator('[name="outcome"]').select_option("approved")
                        page.get_by_role(
                            "button", name="Aplicar resultado de teste"
                        ).click()
                        for field in ("business", "goal", "audience", "materials"):
                            page.locator(f'[name="{field}"]').fill(
                                "Exemplo sintético Comercial"
                            )
                        page.get_by_role(
                            "button", name="Salvar briefing de teste"
                        ).click()
                        page.get_by_role(
                            "heading", name="Briefing salvo no Portal de teste"
                        ).wait_for()
                        if slug == "onepage":
                            capture(page, "06-customer-briefing-durable.png")
                        page.reload()
                        page.get_by_role(
                            "heading", name="Briefing salvo no Portal de teste"
                        ).wait_for()
                        page.goto(login + "/client/services")
                        rows = page.locator("tr[data-customer-order]")
                        assert rows.count() == 1
                        assert page.get_by_text(
                            "Aguardar análise do briefing", exact=True
                        ).count() == 1
                        if slug == "onepage":
                            capture(page, "07-customer-my-services.png")
                        # New browser without the customer's signed session
                        # must not read a saved private order.
                        outside = browser.new_context()
                        try:
                            unauth = outside.request.get(order_url)
                            assert unauth.status == 403
                        finally:
                            outside.close()
                        assert order_url not in seen_orders
                        seen_orders.add(order_url)
                        print("PASS Comercial", slug, "->", offer, "-> independent synthetic PG18 order and briefing")
                        assert not errors, errors
                        assert not blocked, ("Only the two loopback servers allowed", blocked)
                        print("PASS Comercial CTA -> private Portal login:", slug, offer)
                        context.close()
                finally:
                    browser.close()
        finally:
            server.shutdown()
            thread.join(timeout=5)
if review_path is not None:
    assert {p.name for p in review_path.iterdir()} == shots
    pngs=[]
    for name in sorted(shots):
        path=review_path/name
        assert not path.is_symlink()
        raw=path.read_bytes()
        assert raw.startswith(bytes.fromhex("89504e470d0a1a0a"))
        assert 2_000 <= len(raw) <= 2_500_000
        pngs.append({"name":name,"bytes":len(raw),"sha256":sha256(raw).hexdigest()})
    report={
        "schema":"imagine.portal-p0.customer-auth-browser.visual-only.v1",
        "scope":"synthetic-privately-authenticated-preview",
        "screenshots":pngs,
        "customer_data_included":False,
        "real_payment":False,
        "customer_credentials_exported":False,
        "production_release":False,
        "release_gate":"NO_GO",
    }
    (review_path/"manifest.json").write_text(
        json.dumps(report,indent=2,ensure_ascii=False)+"\\n",encoding="utf-8"
    )
    print("PASS 7 screenshots: Comercial and authenticated customer checkout, NO-GO")
print("PASS all four Comercial CTAs reach private Portal, four independent synthetic accounts; zero real clients/payments/deploy")
