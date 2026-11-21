"""F423: owned Performance V5 activation in disposable Asaas sandbox.

Chromium + PostgreSQL FORCE RLS + injected provider API only.
No production account, fiscal invoice, recurring management fee or real money.
"""
import os
import shutil
import re
import subprocess
import uuid
import time
from playwright.sync_api import sync_playwright
from asaas_sandbox_worker import Database, Worker
from test_asaas_sandbox_worker import API

class PerOrderSyntheticAPI(API):
    """Distinct fake PSP references per order; keep DB uniqueness enabled."""
    def __init__(self, order_id):
        super().__init__()
        self.suffix = order_id.split('-')[-1][:20]
        assert len(self.suffix)==20 and all(c in '0123456789abcdef' for c in self.suffix)

    def call(self, method, path, payload=None):
        result=super().call(method,path,payload)
        if method=='POST' and path=='/customers':
            ref='cus_f423'+self.suffix
            self.customer['id']=ref
            result['id']=ref
        elif method=='POST' and path=='/payments':
            ref='pay_f423'+self.suffix
            self.payment['id']=ref
            result['id']=ref
        return result

# Separate single-Google order proof from the existing Google+Meta regression.
# It stops at buyer briefing approval; the two-channel S2S/plan/draft path
# is deliberately NOT claimed for this different purchase.
GOOGLE_ONLY = os.environ.get('F423_GOOGLE_ONLY') == '1'
BASE = os.environ.get(
    'PURCHASE_PREVIEW_BASE',
    'http://127.0.0.1:18083/_rehearsal/purchase'
).split('/_rehearsal/')[0] + '/_preview/portal'

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        executable_path=shutil.which('google-chrome') or shutil.which('chromium'),
        headless=True, args=['--no-sandbox']
    )
    page = browser.new_page()

    # Do not reuse a customer who already logged in repeatedly earlier
    # in the full CI suite. Keep the real account throttle enabled and
    # create a one-time fictitious user rather than bypassing security.
    user = 'demo-f423-' + uuid.uuid4().hex[:12]
    assert page.goto(BASE+'/register').status == 200
    for name, value in [
        ('name','Cliente ficticio F423'),
        ('company','Empresa sintética F423'),
        ('username',user),
        ('password','ci-owned-customer-only-2026'),
        ('password_confirmation','ci-owned-customer-only-2026'),
    ]:
        page.locator('[name='+name+']').fill(value)
    page.get_by_role('button', name='Criar conta e continuar', exact=True).click()
    assert '/client/' in page.url, page.locator('body').inner_text()[:200]

    assert page.goto(BASE+'/shop?product=performance').status == 200
    page.locator('[name=google_media]').fill('1000')
    if not GOOGLE_ONLY:
        page.locator('[name=meta_media]').fill('700')
    assert re.fullmatch(r'[a-f0-9]{64}', page.locator('[name=offer_version]').input_value())
    page.locator('[name=offer_ack]').check()
    page.get_by_role('button', name='Criar pedido de teste', exact=True).click()
    url = page.url
    assert '/shop/orders/' in url
    oid = url.rsplit('/',1)[1]
    snapshot_url = url + '/performance-snapshot'
    review_url = url + '/briefing-review'

    def snapshot():
        response = page.context.request.get(snapshot_url)
        assert response.status == 200, 'Real authenticated Portal source read must return HTTP 200'
        payload = response.json()
        assert payload['schema'] == 'portal_f423_ci_authenticated_order_source_v1'
        assert payload['order_id'] == oid
        assert payload['company_id'].startswith('demo-')
        assert payload['source'] == 'ci_only_portal_customer_purchase_pg18'
        assert payload['ready_channels_count'] == 0
        assert payload['decision_persisted_by_performance'] is False
        assert payload['performance_s2s_proven'] is False
        assert payload['operational_enabled'] is False
        assert payload['ads_write_enabled'] is False
        assert payload['billable'] is False
        assert set(payload['channels']) == ({'google_ads'} if GOOGLE_ONLY else {'google_ads','meta_ads'})
        assert payload['channels']['google_ads']['media_cents'] == 100000
        if not GOOGLE_ONLY:
            assert payload['channels']['meta_ads']['media_cents'] == 70000
        for channel in payload['channels'].values():
            assert channel['activation_cents'] == 49000
            assert channel['decision_state'] == 'blocked'
            assert channel['connection_state'] == 'not_connected'
            assert channel['campaign_state'] == 'not_created'
        return payload

    unauthed = browser.new_context()
    assert unauthed.request.get(snapshot_url).status == 403, 'No session must deny source read'
    unauthed.close()
    initial_snapshot = snapshot()
    assert initial_snapshot['purchase_state'] == 'pending_or_unverified'
    assert initial_snapshot['purchase_evidence'] is None
    assert initial_snapshot['onboarding_state'] == 'awaiting_briefing'
    assert initial_snapshot['approved_briefing_revision'] is None
    # A pending purchase cannot be turned into an approved briefing,
    # even by the signed buyer; read-only receipts are not a payment.
    pending_review = page.context.request.post(review_url, form={
        '_token':page.locator('[name=_token]').first.input_value(),
        'revision':'1','briefing_sha256':'0'*64,'review_ack':'1'})
    assert pending_review.status == 409, 'Pending fake payment must deny review'

    assert page.context.request.get(
        BASE + '/shop/orders/TEST-' + uuid.uuid4().hex + '/performance-snapshot'
    ).status == 404, 'Missing order fails closed'
    text = page.locator('body').inner_text()
    assert ('R$ 490,00' if GOOGLE_ONLY else 'R$ 980,00') in text
    if not GOOGLE_ONLY:
        assert 'R$ 370,00' in text
    assert 'Ativação única' in text
    assert page.locator('[name=sandbox_ack]').count() == 1

    def csrf():
        return page.locator('[name=_token]').first.input_value()
    def revision():
        return page.locator('[name=revision]').first.input_value()

    # Forbidden amount_cents POST is already proven by the generic
    # sandbox checkout suite and the unchanged controller whitelist.
    # Do not consume another shared per-IP rate-limit slot here.
    def submit_sandbox(label):
        for attempt in range(5):
            # Reload after a 429 without reusing form state or CSRF token.
            response = page.goto(url)
            assert response is not None and response.status == 200
            page.locator('[name=sandbox_ack]').check()
            with page.expect_response(
                lambda response: response.request.method == 'POST'
                    and response.url.endswith('/sandbox')
            ) as pending:
                page.get_by_role('button',name=label,exact=True).click()
            accepted=pending.value
            if accepted.status == 429:
                header=accepted.headers.get('retry-after','60')
                assert header.isdecimal(), 'Bounded Retry-After required'
                seconds=int(header)
                assert 0<=seconds<=90, 'Unexpected throttle delay'
                print('F423: respecting application Retry-After for sandbox POST')
                time.sleep(max(1,seconds+1))
                continue
            assert accepted.status in (302,303), (
                'F423 activation POST refused (HTTP '+str(accepted.status)+')'
            )
            return
        raise AssertionError('F423 sandbox POST refused after bounded rate-limit retries')

    submit_sandbox('Gerar cobrança no Asaas sandbox')
    assert page.locator('[name=outcome]').count() == 0
    db = Database()
    rows = [row for row in db.rows() if row['order_id'] == oid]
    assert len(rows) == 1, 'Exactly one owned Performance sandbox intent'
    row = rows[0]
    assert row['amount_cents'] == (49000 if GOOGLE_ONLY else 98000), 'Only selected channel activation is quoted in fake PSP intent'
    assert row['state']=='queued'

    api = PerOrderSyntheticAPI(oid)
    worker = Worker(db,api)
    worker.process(row)
    assert len(api.posts)==2
    payment_requests=[entry[1] for entry in api.posts if entry[0]=='/payments']
    assert len(payment_requests)==1
    payment = payment_requests[0]
    assert payment['value']==(490.0 if GOOGLE_ONLY else 980.0)
    assert not ({'media_budget','management_fee','planned_spend','ads_spend'} & set(payment)), 'No media or management fields sent to PSP'
    assert payment['externalReference']=='imagine:'+oid+':initial'
    assert api.posts[0][1]['notificationDisabled'] is True
    assert not ({'email','mobilePhone','phone'} & set(api.posts[0][1]))

    page.reload()
    assert page.locator('[name=business]').count()==0, 'Pending means no briefing'
    submit_sandbox('Solicitar atualização do Asaas')
    row = next(x for x in db.rows() if x['order_id']==oid)
    api.payment['status']='RECEIVED'
    worker.process(row)
    page.reload()
    assert page.locator('[name=business]').count()==1, 'Trusted provider confirmation unlocks briefing'
    confirmed = snapshot()
    assert confirmed['purchase_state'] == 'synthetic_confirmed'
    assert confirmed['purchase_evidence'] == 'fake_provider_callback_only'
    assert confirmed['onboarding_state'] == 'awaiting_briefing'
    assert confirmed['approved_briefing_revision'] is None
    assert len(api.posts)==2, 'No duplicated provider writes'
    assert page.locator('form[action$="/future-billing"]').count()==0
    assert page.locator('form[action$="/performance-report"]').count()==0
    assert ('R$ 490,00' if GOOGLE_ONLY else 'R$ 980,00') in page.locator('body').inner_text()

    # Persist actual briefing submission via the real Portal HTTP form.
    # Submission is NOT an approval and must never mint a channel mandate.
    for field in ('business','goal','audience','materials'):
        page.locator('[name='+field+']').fill('Conteudo ficticio F423 '+field)
    page.get_by_role('button',name='Salvar briefing de teste',exact=True).click()
    submitted = snapshot()
    assert submitted['purchase_state'] == 'synthetic_confirmed'
    assert submitted['onboarding_state'] == 'briefing_submitted_unreviewed'
    assert submitted['briefing_review_state'] == 'not_approved'
    assert submitted['approved_briefing_revision'] is None
    assert submitted['briefing_submission_revision'] > confirmed['order_revision']
    assert len(submitted['briefing_submission_sha256']) == 64
    assert snapshot() == submitted, 'Repeated authenticated GET is stable; no ledger invented'

    # This is a NEW, distinct buyer review of the submitted revision and SHA,
    # not a channel/account mandate and not a future Performance plan approval.
    assert page.get_by_role('button', name='Validar revisão fictícia do briefing').count() == 1
    buyer_csrf = page.locator('form[action$="/briefing-review"] [name=_token]').input_value()
    review_form = {
        '_token':buyer_csrf,'revision':str(submitted['briefing_submission_revision']),
        'briefing_sha256':submitted['briefing_submission_sha256'],'review_ack':'1'}
    wrong_sha = dict(review_form, briefing_sha256='0'*64)
    assert page.context.request.post(review_url, form=wrong_sha).status == 409, 'Wrong digest must deny review'
    old_revision = dict(review_form, revision=str(submitted['briefing_submission_revision']-1))
    assert page.context.request.post(review_url, form=old_revision).status == 409, 'Stale revision must deny review'
    assert snapshot()['briefing_review_state'] == 'not_approved'
    page.locator('[name=review_ack]').check()
    page.get_by_role('button',name='Validar revisão fictícia do briefing').click()
    approved = snapshot()
    assert approved['briefing_review_state'] == 'synthetic_buyer_approved'
    assert approved['approved_briefing_revision'] == submitted['briefing_submission_revision']
    assert approved['approved_briefing_sha256'] == submitted['briefing_submission_sha256']
    assert approved['briefing_review_actor'] == user
    assert approved['onboarding_state'] == 'briefing_approved_synthetic'
    assert approved['purchase_state'] == 'synthetic_confirmed'
    assert approved['ready_channels_count'] == 0
    assert approved['decision_persisted_by_performance'] is False
    assert approved['performance_s2s_proven'] is False
    assert all(channel['decision_state']=='blocked' for channel in approved['channels'].values())
    assert all(channel['campaign_state']=='not_created' for channel in approved['channels'].values())
    # Cross-request idempotency through SQL (not a PHP array ledger).
    replay = page.context.request.post(review_url, form=review_form)
    assert replay.status == 200, 'Exact buyer review replay must be idempotent'
    assert snapshot() == approved

    assert re.fullmatch(r'TEST-[0-9a-f]{32}',oid)
    assert re.fullmatch(r'demo-[0-9a-f]{32}',approved['company_id'])
    assert re.fullmatch(r'[0-9a-f]{64}',approved['approved_briefing_sha256'])
    sql = ("SELECT customer_id,order_id,order_revision,briefing_sha256,approved_by "
           "FROM purchase_rehearsal.f423_briefing_reviews WHERE order_id='%s'" % oid)
    query = subprocess.run([
        'docker','compose','-f','apps/portal/ops/private-purchase/compose.yaml',
        '-f','apps/portal/ops/private-purchase/compose.ci.yaml',
        'exec','-T','database','psql','-X','-q','-t','-A','-v','ON_ERROR_STOP=1',
        '-U','postgres','-d','portal_purchase_rehearsal','-c',sql],
        check=True,capture_output=True,text=True)
    rows = [line.split('|') for line in query.stdout.splitlines() if line.strip()]
    assert rows == [[approved['company_id'],oid,str(approved['approved_briefing_revision']),
                    approved['approved_briefing_sha256'],user]], (
        'One exact row in actual disposable PG18, never in-memory-only')
    if GOOGLE_ONLY:
        # Ground channel identity in the order persisted by Laravel/PG18,
        # not merely in a Python fixture or in the HTTP presentation.
        offer_sql = ("SELECT offer_snapshot::text FROM purchase_rehearsal.customer_orders "
                     "WHERE customer_id='%s' AND id='%s'" % (approved['company_id'],oid))
        offer_query = subprocess.run([
            'docker','compose','-f','apps/portal/ops/private-purchase/compose.yaml',
            '-f','apps/portal/ops/private-purchase/compose.ci.yaml',
            'exec','-T','database','psql','-X','-q','-t','-A','-v','ON_ERROR_STOP=1',
            '-U','postgres','-d','portal_purchase_rehearsal','-c',offer_sql],
            check=True,capture_output=True,text=True)
        import json
        quote = json.loads(offer_query.stdout.strip())
        assert quote['media_by_channel_cents'] == {'google_ads':100000}, 'No Meta in purchased SQL order'
        assert quote['activation_per_channel_cents'] == 49000
        assert len(rows) == 1
    guard = subprocess.run([
        'docker','compose','-f','apps/portal/ops/private-purchase/compose.yaml',
        '-f','apps/portal/ops/private-purchase/compose.ci.yaml',
        'exec','-T','database','psql','-X','-q','-t','-A','-v','ON_ERROR_STOP=1',
        '-U','postgres','-d','portal_purchase_rehearsal','-c',
        "SELECT relrowsecurity AND relforcerowsecurity FROM pg_class "
        "WHERE oid='purchase_rehearsal.f423_briefing_reviews'::regclass"],
        check=True,capture_output=True,text=True)
    assert guard.stdout.strip() == 't', 'Approval ledger must FORCE RLS'


    # A different signed individual-customer scope cannot read this order.
    other = browser.new_context()
    other_page = other.new_page()
    assert other_page.goto(BASE+'/register').status == 200
    for name,value in [
        ('name','Cliente ficticio de outra empresa'),
        ('company','Empresa sintética B'),
        ('username','demo-f423-other-'+uuid.uuid4().hex[:10]),
        ('password','ci-other-customer-only-2026'),
        ('password_confirmation','ci-other-customer-only-2026'),
    ]:
        other_page.locator('[name='+name+']').fill(value)
    other_page.get_by_role('button',name='Criar conta e continuar',exact=True).click()
    assert '/client/' in other_page.url
    assert other.request.get(snapshot_url).status == 404, 'Cross-customer order read blocked by signed FORCE RLS'
    other.close()
    browser.close()
print('PASS F423 '+('GOOGLE-ONLY' if GOOGLE_ONLY else 'GOOGLE+META')+': fake checkout, synthetic payment and exact buyer briefing approval read in disposable PG18 HTTP; 0 ready, no campaign, no Performance S2S decision for this order')
