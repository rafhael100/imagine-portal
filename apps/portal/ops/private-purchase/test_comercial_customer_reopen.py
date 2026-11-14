"""Independent CI-only re-login after PostgreSQL and Laravel restart.

Verifies all FOUR compiled Comercial CTAs, not just the onepage fixture.
The identity list is a short-lived synthetic runner file, never an artifact.
"""
from __future__ import annotations

import json
import os
import re
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

assert os.environ.get("GITHUB_ACTIONS") == "true"
assert os.environ.get("PORTAL_CUSTOMER_COMMERCIAL_E2E") == "1"

root = Path(os.environ["RUNNER_TEMP"]).resolve(strict=True)
proof = Path(os.environ["IMAGINE_CUSTOMER_REOPEN_EVIDENCE"])
assert proof.parent.resolve() == root and proof.is_file() and not proof.is_symlink()
assert proof.stat().st_mode & 0o077 == 0, "Fixture may only be readable by CI user"
payload = json.loads(proof.read_text(encoding="utf-8"))
assert set(payload) == {"schema", "scope", "accounts"}
assert payload["schema"] == "imagine.portal-p0.four-cta-post-restart.v1"
assert payload["scope"] == "fictional-ci-only"
accounts = payload["accounts"]
expected = {
    "onepage": "onepage",
    "landing-pages": "landing-pages",
    "gestao-google-ads": "performance",
    "gestao-meta-ads": "performance",
}
assert isinstance(accounts, list) and len(accounts) == 4
assert {a["slug"] for a in accounts} == set(expected)
base = "http://127.0.0.1:18083/_preview/portal"
for entry in accounts:
    assert set(entry) == {"slug", "product", "username", "order_url"}
    assert entry["product"] == expected[entry["slug"]]
    assert re.fullmatch(r"demo-comercial-[a-f0-9]{12}", entry["username"])
    assert re.fullmatch(
        r"http://127\.0\.0\.1:18083/_preview/portal/shop/orders/TEST-[a-f0-9]{32}",
        entry["order_url"],
    )
assert len({a["order_url"] for a in accounts}) == 4
assert len({a["username"] for a in accounts}) == 4

with sync_playwright() as pw:
    executable = shutil.which("google-chrome") or shutil.which("chromium")
    assert executable, "CI Chromium executable is missing"
    browser = pw.chromium.launch(executable_path=executable, headless=True, args=[
        "--no-sandbox", "--disable-background-networking",
    ])
    try:
        for index, account in enumerate(accounts):
            context = browser.new_context()
            page = context.new_page()
            def loopback_only(route) -> None:
                if route.request.url.startswith("http://127.0.0.1:18083/"):
                    route.continue_()
                else:
                    route.abort()
            page.route("**/*", loopback_only)
            response = page.goto(base, wait_until="domcontentloaded")
            assert response is not None and response.status == 200
            page.locator('[name="username"]').fill(account["username"])
            page.locator('[name="password"]').fill("ci-comercial-portal-only-2026")
            page.get_by_role("button", name="Entrar", exact=True).click()
            page.wait_for_url(base + "/client/overview")
            restored = page.goto(account["order_url"])
            assert restored is not None and restored.status == 200
            assert page.get_by_role("heading", name="Briefing salvo no Portal de teste").count() == 1
            page.goto(base + "/client/services")
            row = page.locator(
                'tr[data-customer-order="' + account["order_url"].rsplit("/", 1)[1] + '"]'
            )
            assert row.count() == 1, ("Missing durable order", account["slug"])
            assert row.locator("[data-customer-next-step]").inner_text().strip() == "Aguardar análise do briefing"
            assert page.locator("tr[data-customer-order]").count() == 1
            # Two distinct accounts in the SAME synthetic company must never
            # read each other's saved order, even after DB/application restart.
            other = accounts[(index + 1) % 4]
            assert page.request.get(other["order_url"]).status == 404, (
                "Cross-customer order read unexpectedly succeeded",
                account["slug"], other["slug"],
            )
            print("PASS after restart:", account["slug"], account["product"],
                  "owned PG18 order + briefing, foreign denied")
            context.close()
    finally:
        browser.close()

# Never preserve the synthetic account lookup file beyond this CI job.
proof.unlink()
print("PASS all four Comercial purchases persisted after PG18/Laravel restart; NO-GO to real payment/Sites publication")
