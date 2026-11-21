"""CI-only: actual buyer views the exact paused-stage receipt in Chromium.
Never proves Ads connection, delivery, contract acceptance or activation."""
import os
import re
import json
from pathlib import Path
import shutil
import subprocess
import sys
from playwright.sync_api import sync_playwright

assert len(sys.argv) == 2 and sys.argv[1] in {"absent", "paused", "unavailable"}
mode = sys.argv[1]
owner = os.environ["F423_STAGE_OWNER"]
order = os.environ["F423_STAGE_ORDER"]
assert re.fullmatch(r"demo-[a-f0-9]{32}", owner)
assert re.fullmatch(r"TEST-[a-f0-9]{32}", order)

def sql(query):
    command = [
        "docker", "compose", "-f", "apps/portal/ops/private-purchase/compose.yaml",
        "-f", "apps/portal/ops/private-purchase/compose.ci.yaml",
        "exec", "-T", "database", "psql", "-X", "-q", "-t", "-A",
        "-v", "ON_ERROR_STOP=1", "-U", "postgres",
        "-d", "portal_purchase_rehearsal", "-c", query,
    ]
    return subprocess.run(command, capture_output=True, text=True, check=True).stdout.strip()

actor = sql(
    "SELECT approved_by FROM purchase_rehearsal.f423_briefing_reviews "
    f"WHERE customer_id='{owner}' AND order_id='{order}'"
)
assert re.fullmatch(r"[A-Za-z0-9_.@+-]{1,128}", actor), "Exact buyer fixture required"
base = "http://127.0.0.1:18083/_preview/portal"
receipt_url = base + "/shop/orders/" + order + "/paused-stage-receipt"
snapshot_url = base + "/shop/orders/" + order + "/performance-snapshot"

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        executable_path=shutil.which("google-chrome") or shutil.which("chromium"),
        headless=True, args=["--no-sandbox"],
    )
    anonymous = browser.new_context()
    assert anonymous.request.get(receipt_url).status == 403, "No buyer session"
    anonymous.close()
    page = browser.new_page()
    assert page.goto(base + "/").status == 200
    page.locator("[name=username]").fill(actor)
    page.locator("[name=password]").fill("ci-owned-customer-only-2026")
    page.get_by_role("button", name="Entrar", exact=True).click()
    wrong_order_status = page.context.request.get(
        base + "/shop/orders/TEST-" + "f" * 32 + "/paused-stage-receipt"
    ).status
    assert wrong_order_status == 404, (
        f"Wrong order must not expose a stage (HTTP {wrong_order_status})"
    )

    snapshot_response = page.context.request.get(snapshot_url)
    assert snapshot_response.status == 200
    snapshot = snapshot_response.json()
    assert snapshot["company_id"] == owner and snapshot["order_id"] == order
    assert snapshot["briefing_review_actor"] == actor
    assert snapshot["approved_briefing_revision"] == snapshot["order_revision"]
    assert set(snapshot["channels"]) == {"google_ads"}
    assert snapshot["operational_enabled"] is False
    assert snapshot["ads_write_enabled"] is False
    assert snapshot["billable"] is False

    if mode == "paused":
        assert snapshot["plan_approved"] is True
        assert snapshot["campaign_drafts_created"] == 1
        response = page.goto(receipt_url)
        assert response.status == 200, response.status
        assert page.locator("#receipt-order").inner_text() == order
        assert page.locator("#receipt-revision").inner_text() == str(snapshot["order_revision"])
        assert page.locator("#receipt-briefing").inner_text() == snapshot["approved_briefing_sha256"]
        assert page.locator("#receipt-plan").inner_text() == snapshot["diagnostic_plan"]["plan_fingerprint"]
        assert page.locator("#receipt-draft").inner_text() == snapshot["internal_campaign_draft"]["draft_fingerprint"]
        assert page.locator("#receipt-state").inner_text() == "ci_staged_paused_not_delivered"
        assert "Ativado: não" in page.locator("#receipt-limits").inner_text()
        assert "Entregue: não" in page.locator("#receipt-limits").inner_text()
        assert "Publicado: não" in page.locator("#receipt-limits").inner_text()
        assert "Cobrável: não" in page.locator("#receipt-limits").inner_text()
        assert page.context.request.get(receipt_url).status == 200, "Repeat is read-only"
        # A real but unrelated synthetic customer session must not see this
        # exact receipt. Distinct from an anonymous or forged signature.
        # demo-b was created by the already executed two-customer CI flow.
        # Use that distinct, not-yet-logged-in synthetic principal rather
        # than a shared static profile that can hit login throttling.
        previous = json.loads(Path("/tmp/portal-customer-ci.json").read_text())
        assert re.fullmatch(r"demo-a-[0-9a-f]{10}", previous["user"])
        other_user = previous["user"].replace("demo-a-", "demo-b-", 1)
        other = browser.new_page()
        assert other.goto(base + "/").status == 200
        other.locator("[name=username]").fill(other_user)
        other.locator("[name=password]").fill("ci-owned-customer-only-2026")
        other.get_by_role("button", name="Entrar", exact=True).click()
        assert "/client/" in other.url, "Unrelated buyer must authenticate before the denial test"
        status = other.context.request.get(receipt_url).status
        assert status == 404, f"Authenticated unrelated buyer receipt status {status}"
        other.close()
        # An administrator role is not a buyer for this exact presentation.
        operator = browser.new_page()
        assert operator.goto(base + "/").status == 200
        operator.locator("[name=username]").fill("administrador.teste")
        operator.locator("[name=password]").fill("ci-admin-only-2026")
        operator.get_by_role("button", name="Entrar", exact=True).click()
        assert "/admin/" in operator.url, "Administrator must authenticate before the role denial test"
        status = operator.context.request.get(receipt_url).status
        assert status == 403, f"Authenticated admin receipt status {status}"
        operator.close()
    else:
        response = page.context.request.get(receipt_url)
        expected = 404 if mode == "absent" else 503
        assert response.status == expected, (mode, response.status)
        text = response.text()
        assert "f423-performance-key" not in text
        assert "f423_paused_stage_ci_v1" not in text
    browser.close()

print("PASS F423 BUYER PAUSED RECEIPT " + mode.upper()
      + ": same authorized buyer/order, independent HTTP mock, no Ads delivery")
