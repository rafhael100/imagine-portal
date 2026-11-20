"""CI-only Portal CUSTOMER HTTP readback of a real PG18 Performance decision.
No payment operations, no Ads account access, no real campaigns."""
import os, subprocess, shutil
from playwright.sync_api import sync_playwright
BASE='http://127.0.0.1:18083/_preview/portal'
def sql(query):
    p=subprocess.run(['docker','compose','-f','apps/portal/ops/private-purchase/compose.yaml',
       '-f','apps/portal/ops/private-purchase/compose.ci.yaml',
       'exec','-T','database','psql','-X','-q','-t','-A','-v','ON_ERROR_STOP=1',
       '-U','postgres','-d','portal_purchase_rehearsal','-c',query],
       capture_output=True,text=True,check=True)
    return p.stdout.strip()
identity=sql("SELECT b.customer_id||'|'||b.order_id||'|'||b.approved_by||'|'||b.order_revision||'|'||b.briefing_sha256 FROM purchase_rehearsal.f423_briefing_reviews b ORDER BY b.approved_at DESC LIMIT 1")
owner,order,user,revision,digest=identity.split('|')
assert len(owner)==37 and len(order)==37 and len(digest)==64
stored=sql("SELECT provider||'|'||decision_state||'|'||decision_reason||'|'||media_cents FROM purchase_rehearsal.f423_performance_decisions WHERE customer_id='%s' AND order_id='%s' AND order_revision=%s AND briefing_sha256='%s' ORDER BY provider"%(owner,order,revision,digest)).splitlines()
assert stored==['google_ads|blocked|mandate_or_s2s_denied|100000','meta_ads|blocked|mandate_or_s2s_denied|70000'],stored
assert sql("SELECT count(*) FROM purchase_rehearsal.f423_performance_decisions WHERE customer_id='%s' AND order_id='%s'"%(owner,order))=='2'
guard=sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.f423_performance_decisions'::regclass")
assert guard=='t','Decision ledger must enforce FORCE RLS'
privileges=sql("SELECT has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_performance_decisions','UPDATE')||'|'||has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_performance_decisions','DELETE')")
assert privileges=='f|f','No SQL write mutation capability'
with sync_playwright() as playwright:
    browser=playwright.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),
            headless=True,args=['--no-sandbox'])
    anonymous=browser.new_context()
    endpoint=BASE+'/shop/orders/'+order+'/performance-snapshot'
    assert anonymous.request.get(endpoint).status==403
    anonymous.close()
    page=browser.new_page()
    assert page.goto(BASE+'/').status==200
    page.locator('[name=username]').fill(user)
    page.locator('[name=password]').fill('ci-owned-customer-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    response=page.context.request.get(endpoint)
    assert response.status==200,response.status
    data=response.json()
    assert data['company_id']==owner and data['order_id']==order
    assert data['briefing_review_state']=='synthetic_buyer_approved'
    assert str(data['approved_briefing_revision'])==revision
    assert data['approved_briefing_sha256']==digest
    assert data['purchase_state']=='synthetic_confirmed'
    assert data['decision_persisted_by_performance'] is True
    assert data['performance_s2s_proven'] is True
    assert data['ready_channels_count']==0
    assert data['operational_enabled'] is False and data['ads_write_enabled'] is False
    assert data['billable'] is False
    assert set(data['channels'])=={'google_ads','meta_ads'}
    for provider in ('google_ads','meta_ads'):
        channel=data['channels'][provider]
        assert channel['decision_state']=='blocked'
        assert channel['decision_reason']=='mandate_or_s2s_denied'
        assert channel['connection_state']=='not_connected'
        assert channel['campaign_state']=='not_created'
    assert page.context.request.get(endpoint).json()==data
    browser.close()
print('PASS F423: Portal buyer HTTP re-read of same exact PG18 order/review, 2 blocked Performance S2S decisions, zero ready, durable after fresh process'+(' and SQL restart' if os.getenv('F423_RESTARTED') else ''))
