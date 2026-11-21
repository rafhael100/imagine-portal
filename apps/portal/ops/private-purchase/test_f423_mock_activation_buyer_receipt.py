"""CI-only Chromium receipt after an independently signed HTTP mock exercise.
No Google/Meta access, delivery, account authorization, payment or publishing."""
import json
import os
import re
import shutil
import subprocess
import sys
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

assert len(sys.argv) == 2 and sys.argv[1] == "all"
mode = sys.argv[1]
owner = os.environ["F423_STAGE_OWNER"]
order = os.environ["F423_STAGE_ORDER"]
assert re.fullmatch(r"demo-[a-f0-9]{32}", owner)
assert re.fullmatch(r"TEST-[a-f0-9]{32}", order)

query = (
    "SELECT approved_by FROM purchase_rehearsal.f423_briefing_reviews "
    f"WHERE customer_id='{owner}' AND order_id='{order}'"
)
command = [
    "docker", "compose", "-f", "apps/portal/ops/private-purchase/compose.yaml",
    "-f", "apps/portal/ops/private-purchase/compose.ci.yaml", "exec", "-T",
    "database", "psql", "-X", "-q", "-t", "-A", "-v", "ON_ERROR_STOP=1",
    "-U", "postgres", "-d", "portal_purchase_rehearsal", "-c", query
]
actor = subprocess.run(
    command, check=True, capture_output=True, text=True
).stdout.strip()
assert re.fullmatch(r"[A-Za-z0-9_.@+-]{1,128}", actor)

base = "http://127.0.0.1:18083/_preview/portal"
receipt = f"{base}/shop/orders/{order}/mock-activation-receipt"
snapshot_url = f"{base}/shop/orders/{order}/performance-snapshot"

with sync_playwright() as p:
    browser = p.chromium.launch(
        executable_path=shutil.which("google-chrome") or shutil.which("chromium"),
        headless=True, args=["--no-sandbox"]
    )
    anonymous = browser.new_context()
    assert anonymous.request.get(receipt).status == 403
    anonymous.close()
    buyer = browser.new_page()
    assert buyer.goto(base + "/").status == 200
    buyer.locator("[name=username]").fill(actor)
    buyer.locator("[name=password]").fill("ci-owned-customer-only-2026")
    buyer.get_by_role("button", name="Entrar", exact=True).click()
    assert "/client/" in buyer.url
    snapshot_response = buyer.context.request.get(snapshot_url)
    assert snapshot_response.status == 200
    snap = snapshot_response.json()
    assert snap["company_id"] == owner and snap["order_id"] == order
    assert snap["briefing_review_actor"] == actor
    assert snap["plan_approved"] is True
    assert snap["campaign_drafts_created"] == 1
    assert list(snap["channels"]) == ["google_ads"]
    assert snap["channels"]["google_ads"]["connection_state"] == "not_connected"
    assert snap["operational_enabled"] is False
    assert snap["ads_write_enabled"] is False
    assert snap["billable"] is False

    assert buyer.context.request.get(
        f"{base}/shop/orders/TEST-{'f'*32}/mock-activation-receipt"
    ).status == 404

    if mode == "all":
        # One authenticated buyer session across all three stages prevents
        # CI login throttling from obscuring the original negative controls.
        assert buyer.context.request.get(receipt).status == 404, (
            "No mock exercise receipt before an explicit synthetic decision"
        )
        compose = ["docker", "compose",
            "-f", "apps/portal/ops/private-purchase/compose.yaml",
            "-f", "apps/portal/ops/private-purchase/compose.ci.yaml"]
        worker = compose + ["run", "--rm", "-T", "performance-lab",
            "/lab/mock-activation-worker.php", owner, order]
        subprocess.run(worker, check=True)
        subprocess.run(worker, check=True)  # second process, no duplication
        response = buyer.goto(receipt)
        assert response.status == 200, response.status
        assert buyer.locator("#activation-order").inner_text() == order
        assert buyer.locator("#activation-revision").inner_text() == str(snap["order_revision"])
        assert buyer.locator("#activation-briefing").inner_text() == snap["approved_briefing_sha256"]
        assert buyer.locator("#activation-plan").inner_text() == snap["diagnostic_plan"]["plan_fingerprint"]
        assert buyer.locator("#activation-draft").inner_text() == snap["internal_campaign_draft"]["draft_fingerprint"]
        assert re.fullmatch(r"[a-f0-9]{64}", buyer.locator("#activation-stage").inner_text())
        assert buyer.locator("#activation-state").inner_text() == "ci_mock_activation_exercised_not_delivered"
        assert buyer.locator("#activation-count").inner_text() == "um único exercício"
        limits = buyer.locator("#activation-limits").inner_text()
        assert all(term in limits for term in (
            "Ativação Google: não", "Entrega: não",
            "Publicação: não", "Cobrança: não"
        ))
        assert buyer.context.request.get(receipt).status == 200, "GET cannot change state"

        previous = json.loads(Path("/tmp/portal-customer-ci.json").read_text())
        assert re.fullmatch(r"demo-a-[0-9a-f]{10}", previous["user"])
        unrelated_user = previous["user"].replace("demo-a-", "demo-b-", 1)
        other = browser.new_page()
        assert other.goto(base + "/").status == 200
        other.locator("[name=username]").fill(unrelated_user)
        other.locator("[name=password]").fill("ci-owned-customer-only-2026")
        other.get_by_role("button", name="Entrar", exact=True).click()
        assert "/client/" in other.url
        assert other.context.request.get(receipt).status == 404
        other.close()

        admin = browser.new_page()
        assert admin.goto(base + "/").status == 200
        admin.locator("[name=username]").fill("administrador.teste")
        admin.locator("[name=password]").fill("ci-admin-only-2026")
        admin.get_by_role("button", name="Entrar", exact=True).click()
        assert "/admin/" in admin.url
        assert admin.context.request.get(receipt).status == 403
        admin.close()
        # Stop only the simulator, not the PAUSED source. The same buyer
        # must now receive fail-closed 503, never a stale success receipt.
        subprocess.run(compose + ["stop", "f423-mock-activation"], check=True)
        response = buyer.context.request.get(receipt)
        assert response.status == 503, ("unavailable", response.status)
        text = response.text()
        assert "f423-performance-key" not in text
        assert "f423_mock_activation_ci_v1" not in text

        # New isolated CI proof: the mock writes only to ephemeral /tmp (tmpfs).
        # Restart its existing container; the prior receipt MUST be gone.
        # The upstream PAUSED stage and the authenticated buyer/PG18 draft
        # stay intact. An old 200 would be an unsafe stale evidence leak.
        subprocess.run(compose + ["start", "f423-mock-activation"], check=True)
        for attempt in range(30):
            status = buyer.context.request.get(receipt).status
            if status == 404:
                break
            assert status == 503, (
                f"Mock restarted with unexpected exercise receipt HTTP {status}"
            )
            time.sleep(0.5)
        else:
            raise AssertionError("Restarted mock did not reach clean empty state")
        paused_url = f"{base}/shop/orders/{order}/paused-stage-receipt"
        assert buyer.context.request.get(paused_url).status == 200, (
            "PAUSED source must remain readable when activation mock restarts"
        )

        # A new explicit synthetic exercise is required after restart.
        # This second lifecycle is still only in a private CI mock, never Ads.
        subprocess.run(worker, check=True)
        restored_response = buyer.goto(receipt)
        assert restored_response.status == 200
        assert buyer.locator("#activation-state").inner_text() == (
            "ci_mock_activation_exercised_not_delivered"
        )
        assert buyer.locator("#activation-count").inner_text() == "um único exercício"
        assert buyer.locator("#activation-stage").inner_text() == (
            re.fullmatch(r"[a-f0-9]{64}", buyer.locator("#activation-stage").inner_text()).group()
        )
        assert buyer.context.request.get(receipt).status == 200
        subprocess.run(compose + ["stop", "f423-mock-activation"], check=True)
        assert buyer.context.request.get(receipt).status == 503
    browser.close()

print("PASS F423 MOCK RESTART: buyer mock receipt fails closed on stop; restart clears ephemeral event 404 while PAUSED remains 200; fresh synthetic exercise yields one new receipt, no Ads delivery")
