"""CI-only: buyer HTTP readback of ONE Google decision from actual disposable PG18.
No operational Google access, paid order, legally binding contract, plan or campaign.
The other Google+Meta order is independently tested in test_f423_performance_readback.py.
"""
import os
import re
import shutil
import subprocess
from playwright.sync_api import sync_playwright

BASE='http://127.0.0.1:18083/_preview/portal'

def sql(query):
    process=subprocess.run([
        'docker','compose','-f','apps/portal/ops/private-purchase/compose.yaml',
        '-f','apps/portal/ops/private-purchase/compose.ci.yaml',
        'exec','-T','database','psql','-X','-q','-t','-A','-v','ON_ERROR_STOP=1',
        '-U','postgres','-d','portal_purchase_rehearsal','-c',query
    ],capture_output=True,text=True,check=True)
    return process.stdout.strip()

identity=sql("""SELECT b.customer_id||'|'||b.order_id||'|'||b.approved_by||'|'||
                     b.order_revision||'|'||b.briefing_sha256
                 FROM purchase_rehearsal.f423_briefing_reviews b
                 JOIN purchase_rehearsal.customer_orders o ON o.id=b.order_id
                   AND o.customer_id=b.customer_id
                 WHERE (o.offer_snapshot::jsonb->'media_by_channel_cents') ? 'google_ads'
                   AND NOT ((o.offer_snapshot::jsonb->'media_by_channel_cents') ? 'meta_ads')
                 ORDER BY b.approved_at DESC LIMIT 1""")
owner,order,user,revision,digest=identity.split('|')
assert re.fullmatch(r'demo-[a-f0-9]{32}',owner)
assert re.fullmatch(r'TEST-[a-f0-9]{32}',order)
assert re.fullmatch(r'[a-f0-9]{64}',digest)
assert revision.isdecimal()
where="customer_id='%s' AND order_id='%s' AND order_revision=%s AND briefing_sha256='%s'"%(owner,order,revision,digest)
rows=sql("SELECT provider||'|'||decision_state||'|'||decision_reason||'|'||media_cents FROM purchase_rehearsal.f423_performance_decisions WHERE "+where+" ORDER BY provider").splitlines()
assert rows==['google_ads|internal_review_only|synthetic_gate_accepted_for_internal_review|100000'], rows
assert sql("SELECT count(*) FROM purchase_rehearsal.f423_performance_decisions WHERE customer_id='%s' AND order_id='%s'"%(owner,order))=='1'
assert sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.f423_performance_decisions'::regclass")=='t'
assert sql("SELECT NOT (has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_performance_decisions','UPDATE') OR has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_performance_decisions','DELETE'))")=='t'
with sync_playwright() as playwright:
    browser=playwright.chromium.launch(
       executable_path=shutil.which('google-chrome') or shutil.which('chromium'),
       headless=True,args=['--no-sandbox'])
    endpoint=BASE+'/shop/orders/'+order+'/performance-snapshot'
    anon=browser.new_context()
    assert anon.request.get(endpoint).status==403
    anon.close()
    page=browser.new_page()
    assert page.goto(BASE+'/').status==200
    page.locator('[name=username]').fill(user)
    page.locator('[name=password]').fill('ci-owned-customer-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    response=page.context.request.get(endpoint)
    assert response.status==200,response.status
    data=response.json()
    assert data['source']=='ci_only_portal_customer_purchase_pg18'
    assert data['company_id']==owner and data['order_id']==order
    assert data['purchase_state']=='synthetic_confirmed'
    assert data['purchase_evidence']=='fake_provider_callback_only'
    assert data['briefing_review_state']=='synthetic_buyer_approved'
    assert str(data['approved_briefing_revision'])==revision
    assert data['approved_briefing_sha256']==digest
    assert data['decision_persisted_by_performance'] is True
    assert data['performance_s2s_proven'] is True
    assert data['ready_channels_count']==1
    assert data['operational_enabled'] is False
    assert data['ads_write_enabled'] is False and data['billable'] is False
    assert set(data['channels'])=={'google_ads'}
    google=data['channels']['google_ads']
    assert google['media_cents']==100000 and google['activation_cents']==49000
    assert google['decision_state']=='internal_review_only'
    assert google['decision_reason']=='synthetic_gate_accepted_for_internal_review'
    assert google['connection_state']=='not_connected' and google['campaign_state']=='not_created'
    assert page.context.request.get(endpoint).json()==data
    browser.close()
print('PASS F423 GOOGLE-ONLY READBACK: one PG18 SQL decision, exact buyer/revision/sha and authenticated Portal HTTP, replay stable'+(' after PostgreSQL restart' if os.getenv('F423_RESTARTED') else '')+'; internal-review only, zero campaign drafts')
