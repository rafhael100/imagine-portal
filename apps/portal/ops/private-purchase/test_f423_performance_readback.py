"""CI-only Portal CUSTOMER HTTP readback of a real PG18 Performance decision.
No payment operations, no Ads account access, no real campaigns."""
import os, subprocess, shutil
from playwright.sync_api import sync_playwright
BASE='http://127.0.0.1:18083/_preview/portal'
GOOGLE_ONLY=os.getenv('F423_GOOGLE_ONLY')=='1'
DRAFT_EXPECTED=GOOGLE_ONLY and bool(os.getenv('F423_RESTARTED'))
def sql(query):
    p=subprocess.run(['docker','compose','-f','apps/portal/ops/private-purchase/compose.yaml',
       '-f','apps/portal/ops/private-purchase/compose.ci.yaml',
       'exec','-T','database','psql','-X','-q','-t','-A','-v','ON_ERROR_STOP=1',
       '-U','postgres','-d','portal_purchase_rehearsal','-c',query],
       capture_output=True,text=True,check=True)
    return p.stdout.strip()
selection="AND NOT (o.offer_snapshot::jsonb->'media_by_channel_cents' ? 'meta_ads')" if GOOGLE_ONLY else "AND (o.offer_snapshot::jsonb->'media_by_channel_cents' ? 'meta_ads')"
identity=sql("SELECT b.customer_id||'|'||b.order_id||'|'||b.approved_by||'|'||b.order_revision||'|'||b.briefing_sha256 "
             "FROM purchase_rehearsal.f423_briefing_reviews b "
             "JOIN purchase_rehearsal.customer_orders o ON o.id=b.order_id AND o.customer_id=b.customer_id "
             "WHERE o.offer_snapshot::jsonb->'media_by_channel_cents' ? 'google_ads' "+selection+" ORDER BY b.approved_at DESC LIMIT 1")
owner,order,user,revision,digest=identity.split('|')
assert len(owner)==37 and len(order)==37 and len(digest)==64
stored=sql("SELECT provider||'|'||decision_state||'|'||decision_reason||'|'||media_cents FROM purchase_rehearsal.f423_performance_decisions WHERE customer_id='%s' AND order_id='%s' AND order_revision=%s AND briefing_sha256='%s' ORDER BY provider"%(owner,order,revision,digest)).splitlines()
expected=['google_ads|internal_review_only|synthetic_gate_accepted_for_internal_review|100000']
if not GOOGLE_ONLY:
    expected.append('meta_ads|blocked|mandate_or_s2s_denied|70000')
assert stored==expected,stored
assert sql("SELECT count(*) FROM purchase_rehearsal.f423_performance_decisions WHERE customer_id='%s' AND order_id='%s'"%(owner,order))==str(len(expected))
guard=sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.f423_performance_decisions'::regclass")
assert guard=='t','Decision ledger must enforce FORCE RLS'
if GOOGLE_ONLY:
    assert sql("SELECT count(*) FROM purchase_rehearsal.f423_google_diagnostic_plans WHERE customer_id='%s' AND order_id='%s' AND order_revision=%s AND briefing_sha256='%s' AND provider='google_ads' AND plan_version=1"%(owner,order,revision,digest))=='1', 'Exactly one persisted versioned Google diagnostic plan'
    assert sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.f423_google_diagnostic_plans'::regclass")=='t', 'Plan ledger must FORCE RLS'
    assert sql("SELECT NOT (has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_diagnostic_plans','UPDATE') OR has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_diagnostic_plans','DELETE'))")=='t', 'Plan ledger mutation forbidden'
privileges=sql("SELECT NOT (has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_performance_decisions','UPDATE') OR has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_performance_decisions','DELETE'))")
assert privileges=='t','No SQL write mutation capability'
unique_alias=sql("SELECT count(*) FROM pg_constraint WHERE conrelid='purchase_rehearsal.f423_performance_decisions'::regclass AND contype='u' AND pg_get_constraintdef(oid) LIKE '%source_order_alias%'")
assert unique_alias=='1','Canonical alias collision protection must exist'
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
    assert data['ready_channels_count']==1
    assert data['operational_enabled'] is False and data['ads_write_enabled'] is False
    assert data['billable'] is False
    assert data['plan_approved'] is (GOOGLE_ONLY and bool(os.getenv('F423_RESTARTED')))
    assert data['campaign_drafts_created']==int(DRAFT_EXPECTED)
    assert (data['internal_campaign_draft'] is not None) is DRAFT_EXPECTED
    expected_samples=([
        {'sample_date':'2026-11-19','impressions':1000,'clicks':40,'conversions':2,'source':'ci_offline_google_ads_provider_fixture'},
        {'sample_date':'2026-11-20','impressions':1200,'clicks':46,'conversions':2,'source':'ci_offline_google_ads_provider_fixture'}
    ] if DRAFT_EXPECTED else [])
    assert data['monitoring_samples']==expected_samples
    assert (data['improvement_proposal'] is not None) is DRAFT_EXPECTED
    if DRAFT_EXPECTED:
        suggestion=data['improvement_proposal']
        assert suggestion['state']=='awaiting_separate_approval'
        assert suggestion['proposal_version']==1
        assert suggestion['action']=='audit_search_terms_offline_simulation'
        assert suggestion['separate_approval_recorded'] is False
        assert suggestion['applied'] is False and suggestion['published'] is False
        assert suggestion['metric_origin']=='ci_offline_google_ads_provider_fixture'
        assert suggestion['draft_fingerprint']==data['internal_campaign_draft']['draft_fingerprint']
        assert len(suggestion['proposal_fingerprint'])==64
        assert sql("SELECT count(*) FROM purchase_rehearsal.f423_google_offline_improvements WHERE customer_id='%s' AND order_id='%s' AND order_revision=%s AND briefing_sha256='%s' AND provider='google_ads' AND proposal_state='awaiting_separate_approval'"%(owner,order,revision,digest))=='1'
        assert sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.f423_google_offline_improvements'::regclass")=='t'
        assert sql("SELECT NOT (has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_offline_improvements','UPDATE') OR has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_offline_improvements','DELETE'))")=='t'
    assert data['monitoring_state']==('two_dated_offline_fixture_samples' if DRAFT_EXPECTED else 'not_started')
    if DRAFT_EXPECTED:
        assert sql("SELECT count(*) FROM purchase_rehearsal.f423_google_monitoring_samples WHERE customer_id='%s' AND order_id='%s' AND order_revision=%s AND briefing_sha256='%s' AND provider='google_ads'"%(owner,order,revision,digest))=='2'
        assert sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.f423_google_monitoring_samples'::regclass")=='t'
        assert sql("SELECT NOT (has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_monitoring_samples','UPDATE') OR has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_monitoring_samples','DELETE'))")=='t'
    if DRAFT_EXPECTED:
        draft=data['internal_campaign_draft']
        assert draft['state']=='ci_internal_unpublished' and draft['provider']=='google_ads'
        assert draft['published'] is False and draft['operational_enabled'] is False
        assert draft['ads_connected'] is False and draft['plan_version']==1
        assert len(draft['draft_fingerprint'])==64
        assert sql("SELECT count(*) FROM purchase_rehearsal.f423_google_internal_drafts WHERE customer_id='%s' AND order_id='%s' AND order_revision=%s AND briefing_sha256='%s' AND provider='google_ads'"%(owner,order,revision,digest))=='1'
        assert sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.f423_google_internal_drafts'::regclass")=='t'
        assert sql("SELECT NOT (has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_internal_drafts','UPDATE') OR has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_internal_drafts','DELETE'))")=='t'
    assert set(data['channels'])==({'google_ads'} if GOOGLE_ONLY else {'google_ads','meta_ads'})
    if GOOGLE_ONLY:
        plan=data['diagnostic_plan']
        assert plan['state']=='awaiting_separate_approval' and plan['plan_version']==1
        assert plan['diagnostic_version']==1 and plan['metric_origin']=='ci_synthetic_fixture_no_ads'
        assert plan['sample_impressions']==1000 and plan['sample_clicks']==40
        assert plan['sample_conversions']==2 and plan['media_cents']==100000
        assert plan['proposed_action']=='review_search_terms_only'
        assert plan['buyer_plan_approved'] is bool(os.getenv('F423_RESTARTED'))
        assert plan['plan_approval_state']==('synthetic_buyer_approved' if os.getenv('F423_RESTARTED') else 'not_approved')
        assert data['plan_approved'] is bool(os.getenv('F423_RESTARTED'))
        assert plan['campaign_draft_created'] is DRAFT_EXPECTED and plan['operational_enabled'] is False
        if DRAFT_EXPECTED:
            assert data['internal_campaign_draft']['plan_fingerprint']==plan['plan_fingerprint']
        assert len(plan['plan_fingerprint'])==64
    else:
        assert data['diagnostic_plan'] is None
    for provider in (('google_ads',) if GOOGLE_ONLY else ('google_ads','meta_ads')):
        channel=data['channels'][provider]
        if provider=='google_ads':
            assert channel['decision_state']=='internal_review_only'
            assert channel['decision_reason']=='synthetic_gate_accepted_for_internal_review'
        else:
            assert channel['decision_state']=='blocked'
            assert channel['decision_reason']=='mandate_or_s2s_denied'
        assert channel['connection_state']=='not_connected'
        assert channel['campaign_state']==('internal_draft_unpublished' if DRAFT_EXPECTED and provider=='google_ads' else 'not_created')
    if GOOGLE_ONLY and not os.getenv('F423_RESTARTED'):
        review_url=BASE+'/shop/orders/'+order+'/plan-review'
        unauthenticated=browser.new_context()
        assert unauthenticated.request.get(review_url).status==403, 'Anonymous plan review forbidden'
        unauthenticated.close()
        rendered=page.goto(review_url)
        assert rendered.status==200
        assert '1000' in page.locator('body').inner_text() and 'fict' in page.locator('body').inner_text().lower()
        assert plan['plan_fingerprint'] in page.locator('body').inner_text(), 'Exact plan fingerprint must be shown before approval'
        token=page.locator('form[action$="/plan-review"] [name=_token]').input_value()
        payload={'_token':token,'revision':revision,'plan_version':'1',
                 'plan_fingerprint':plan['plan_fingerprint'],'approval_ack':'1'}
        assert page.context.request.post(review_url,form=dict(payload,plan_fingerprint='0'*64)).status==409
        assert page.context.request.post(review_url,form=dict(payload,revision=str(int(revision)+1))).status==409
        assert page.context.request.post(review_url,form=dict(payload,plan_version='2'),headers={'Accept':'application/json'}).status==422
        assert page.context.request.post(review_url,form=dict(payload,approval_ack=''),headers={'Accept':'application/json'}).status==422
        assert page.context.request.get(endpoint).json()['plan_approved'] is False
        page.locator('[name=approval_ack]').check()
        page.get_by_role('button',name='Aprovar plano fictício exibido').click()
        assert page.locator('#approval-state').inner_text()=='synthetic_buyer_approved'
        assert page.context.request.post(review_url,form=payload).status==200, 'Exact approval replay is idempotent'
        fresh=page.context.request.get(endpoint).json()
        assert fresh['plan_approved'] is True
        assert fresh['diagnostic_plan']['buyer_plan_approved'] is True
        assert fresh['diagnostic_plan']['plan_fingerprint']==plan['plan_fingerprint']
        assert fresh['campaign_drafts_created']==0
        assert fresh['channels']['google_ads']['campaign_state']=='not_created'
        assert sql("SELECT count(*) FROM purchase_rehearsal.f423_google_plan_approvals WHERE customer_id='%s' AND order_id='%s' AND order_revision=%s AND plan_fingerprint='%s' AND approved_by='%s'"%(owner,order,revision,plan['plan_fingerprint'],user))=='1'
        assert sql("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.f423_google_plan_approvals'::regclass")=='t'
        assert sql("SELECT NOT (has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_plan_approvals','UPDATE') OR has_table_privilege('purchase_rehearsal_a','purchase_rehearsal.f423_google_plan_approvals','DELETE'))")=='t'
        data=fresh
    assert page.context.request.get(endpoint).json()==data
    browser.close()
print('PASS F423: Portal buyer HTTP re-read of same exact PG18 '+('Google-only' if GOOGLE_ONLY else 'Google+Meta')+' order/review, Google synthetic internal-only'+('' if GOOGLE_ONLY else ', Meta blocked')+', exactly one internal review, durable after fresh process'+(' and SQL restart' if os.getenv('F423_RESTARTED') else ''))
