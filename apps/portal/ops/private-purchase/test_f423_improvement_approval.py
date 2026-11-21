"""Browser + PostgreSQL 18 readback of distinct OFFLINE improvement approval, never Ads."""
import subprocess, shutil
from playwright.sync_api import sync_playwright
BASE="http://127.0.0.1:18083/_preview/portal"
def sql(query):
    p=subprocess.run(['docker','compose','-f','apps/portal/ops/private-purchase/compose.yaml',
      '-f','apps/portal/ops/private-purchase/compose.ci.yaml','exec','-T','database',
      'psql','-X','-q','-t','-A','-v','ON_ERROR_STOP=1','-U','postgres',
      '-d','portal_purchase_rehearsal','-c',query],
      capture_output=True,text=True,check=True)
    return p.stdout.strip()
identity=sql("SELECT b.customer_id||'|'||b.order_id||'|'||b.approved_by||'|'||b.order_revision||'|'||b.briefing_sha256 "
    "FROM purchase_rehearsal.f423_briefing_reviews b "
    "JOIN purchase_rehearsal.customer_orders o ON o.customer_id=b.customer_id AND o.id=b.order_id "
    "WHERE o.offer_snapshot::jsonb->'media_by_channel_cents' ? 'google_ads' "
    "AND NOT (o.offer_snapshot::jsonb->'media_by_channel_cents' ? 'meta_ads') "
    "ORDER BY b.approved_at DESC LIMIT 1")
owner,order,user,revision,digest=identity.split('|')
assert len(owner)==37 and len(order)==37 and len(digest)==64
table="purchase_rehearsal.f423_google_improvement_approvals"
assert sql("SELECT count(*) FROM "+table)=="0", "No approval before browser action"
assert sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='"+table+"'::regclass")=="t"
assert sql("SELECT NOT (has_table_privilege('purchase_rehearsal_a','"+table+"','UPDATE') OR "
           "has_table_privilege('purchase_rehearsal_a','"+table+"','DELETE'))")=="t"
with sync_playwright() as playwright:
    browser=playwright.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'),
      headless=True,args=['--no-sandbox'])
    endpoint=BASE+'/shop/orders/'+order+'/performance-snapshot'
    review_url=BASE+'/shop/orders/'+order+'/improvement-review'
    anonymous=browser.new_context()
    assert anonymous.request.get(review_url).status==403
    assert anonymous.request.post(review_url,form={}).status in (403,419)
    anonymous.close()
    page=browser.new_page()
    assert page.goto(BASE+'/').status==200
    page.locator('[name=username]').fill(user)
    page.locator('[name=password]').fill('ci-owned-customer-only-2026')
    page.get_by_role('button',name='Entrar',exact=True).click()
    original=page.context.request.get(endpoint).json()
    proposal=original['improvement_proposal']
    assert proposal['separate_approval_recorded'] is False
    assert proposal['applied'] is False and proposal['published'] is False
    assert proposal['state']=='awaiting_separate_approval'
    assert original['campaign_drafts_created']==1
    assert page.goto(review_url).status==200
    visible=page.locator('body').inner_text()
    assert proposal['proposal_fingerprint'] in visible
    assert proposal['evidence_sha256'] in visible
    assert 'fictíc' in visible.lower()
    token=page.locator('form[action$="/improvement-review"] [name=_token]').input_value()
    body={'_token':token,'revision':revision,'proposal_version':'1',
      'proposal_fingerprint':proposal['proposal_fingerprint'],'approval_ack':'1'}
    assert page.context.request.post(review_url,form=dict(body,proposal_fingerprint='0'*64)).status==409
    assert page.context.request.post(review_url,form=dict(body,revision=str(int(revision)+1))).status==409
    assert page.context.request.post(review_url,form=dict(body,proposal_version='2'),headers={'Accept':'application/json'}).status==422
    assert page.context.request.post(review_url,form=dict(body,approval_ack=''),headers={'Accept':'application/json'}).status==422
    assert page.context.request.post(review_url,form=dict(body,applied='true'),headers={'Accept':'application/json'}).status==422
    assert page.context.request.get(endpoint).json()['improvement_proposal']['separate_approval_recorded'] is False
    page.locator('[name=approval_ack]').check()
    page.get_by_role('button',name='Aprovar melhoria fictícia exibida').click()
    assert page.locator('#approval-state').inner_text()=='synthetic_buyer_approved'
    assert page.context.request.post(review_url,form=body).status==200
    final=page.context.request.get(endpoint).json()
    assert final['improvement_proposal']['separate_approval_recorded'] is True
    assert final['improvement_proposal']['approval_state']=='synthetic_buyer_approved'
    assert final['improvement_proposal']['proposal_fingerprint']==proposal['proposal_fingerprint']
    assert final['improvement_proposal']['applied'] is False and final['improvement_proposal']['published'] is False
    assert final['campaign_drafts_created']==1
    assert final['operational_enabled'] is False and final['ads_write_enabled'] is False
    assert sql("SELECT count(*) FROM "+table+" WHERE customer_id='%s' AND order_id='%s' "
       "AND order_revision=%s AND briefing_sha256='%s' AND provider='google_ads' "
       "AND proposal_fingerprint='%s' AND approved_by='%s'"%(
        owner,order,revision,digest,proposal['proposal_fingerprint'],user))=='1'
    browser.close()
print("PASS F423 IMPROVEMENT APPROVAL: exact proposal shown/accepted separately by same synthetic buyer, one immutable PG18 row, negatives denied, no application or Ads")
